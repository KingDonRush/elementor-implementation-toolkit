const instances = new WeakMap();

function labels() {
  return window.eitConfig?.i18n || {};
}

function config() {
  return window.eitConfig || {};
}

export class EntryRelationPicker {
  constructor(root) {
    this.root = root;
    this.select = root.querySelector("[data-eit-relation-select]");
    this.search = root.querySelector("[data-eit-relation-search]");
    this.status = root.querySelector("[data-eit-relation-status]");
    this.more = root.querySelector("[data-eit-relation-more]");
    this.collectionId = root.dataset.collectionId;
    this.page = 0;
    this.pages = 1;
    this.query = "";
    this.destroyed = false;
    this.abortController = null;
    this.searchTimer = null;
    this.onSearch = () => {
      window.clearTimeout(this.searchTimer);
      this.searchTimer = window.setTimeout(() => {
        this.query = this.search?.value.trim() || "";
        this.load(1, true);
      }, 250);
    };
    this.onMore = () => this.load(this.page + 1, false);
    this.search?.addEventListener("input", this.onSearch);
    this.more?.addEventListener("click", this.onMore);
    this.load(1, true);
  }

  async load(page, reset) {
    if (this.destroyed || !this.select || !this.collectionId) return;
    this.abortController?.abort();
    const controller = new AbortController();
    this.abortController = controller;
    this.root.setAttribute("aria-busy", "true");
    this.message(labels().entryRelationLoading || "Loading options…", "loading");
    if (this.more) this.more.hidden = true;
    const runtime = config();
    const base = String(
      runtime.collectionRestUrl ||
        `${String(runtime.entryRestUrl || "/wp-json/eit/v1").replace(/\/$/, "")}/collections`,
    ).replace(/\/$/, "");
    const payload = {
      page: Math.max(1, Number(page) || 1),
      per_page: 24,
      filters: [],
      facets: [],
    };
    if (this.query) payload.search = this.query;
    try {
      const response = await fetch(
        `${base}/${encodeURIComponent(this.collectionId)}/query`,
        {
          method: "POST",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            ...(runtime.nonce
              ? { "X-WP-Nonce": runtime.nonce }
              : {}),
          },
          body: JSON.stringify(payload),
          signal: controller.signal,
        },
      );
      const result = await response.json();
      if (!response.ok) throw result;
      if (this.destroyed || this.abortController !== controller) return;
      this.render(result.items || [], reset);
      this.page = Number(result.pagination?.page) || payload.page;
      this.pages = Math.max(1, Number(result.pagination?.pages) || 1);
      const total = Math.max(0, Number(result.pagination?.total) || 0);
      this.message(
        total
          ? String(labels().entryRelationCount || "%d options available.").replace(
              "%d",
              String(total),
            )
          : labels().entryRelationEmpty || "No available options.",
        total ? "ready" : "empty",
      );
      if (this.more) this.more.hidden = this.page >= this.pages;
    } catch (error) {
      if ("AbortError" !== error?.name) {
        this.message(
          error?.message ||
            labels().entryRelationError ||
            "Options could not be loaded. Existing selections were preserved.",
          "error",
        );
      }
    } finally {
      if (this.abortController === controller) {
        this.abortController = null;
        if (!this.destroyed) this.root.setAttribute("aria-busy", "false");
      }
    }
  }

  render(items, reset) {
    const selected = new Map(
      [...this.select.selectedOptions]
        .filter((option) => option.value)
        .map((option) => [option.value, option.textContent]),
    );
    const options = reset
      ? new Map(selected)
      : new Map(
          [...this.select.options]
            .filter((option) => option.value)
            .map((option) => [option.value, option.textContent]),
        );
    items.forEach((item) => {
      const id = String(item.id ?? "");
      if (id) options.set(id, String(item.title || id));
    });
    const fragment = document.createDocumentFragment();
    if (!this.select.multiple) {
      const placeholder = document.createElement("option");
      placeholder.value = "";
      placeholder.textContent = labels().entryRelationChoose || "Choose an option";
      fragment.append(placeholder);
    }
    options.forEach((label, value) => {
      const option = document.createElement("option");
      option.value = value;
      option.textContent = label;
      option.selected = selected.has(value);
      fragment.append(option);
    });
    this.select.replaceChildren(fragment);
  }

  message(value, state = "ready") {
    this.root.dataset.state = state;
    if (this.status) this.status.textContent = value;
    if (this.status) this.status.dataset.state = state;
  }

  destroy() {
    if (this.destroyed) return;
    this.destroyed = true;
    window.clearTimeout(this.searchTimer);
    this.abortController?.abort();
    this.search?.removeEventListener("input", this.onSearch);
    this.more?.removeEventListener("click", this.onMore);
    this.root.setAttribute("aria-busy", "false");
    this.root.dataset.state = "idle";
  }
}

export function initializeEntryRelations(scope) {
  return [...scope.querySelectorAll("[data-eit-relation-picker]")].map((root) => {
    const current = instances.get(root);
    if (current) return current;
    const instance = new EntryRelationPicker(root);
    instances.set(root, instance);
    return instance;
  });
}

export function destroyEntryRelations(instancesToDestroy = []) {
  instancesToDestroy.forEach((instance) => {
    instance.destroy();
    instances.delete(instance.root);
  });
}
