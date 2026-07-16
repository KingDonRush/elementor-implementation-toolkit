import { cssEscape } from "./utils.js";

const instances = new WeakMap();

export class ActionConnector {
  constructor(button) {
    this.button = button;
    this.surfaceId = button.getAttribute("data-eit-action-surface") || "";
    this.intent = button.getAttribute("data-eit-action-intent") || "default";
    this.status = button
      .closest("[data-eit-toolkit-action]")
      ?.querySelector("[data-eit-action-status]");
    this.onClick = (event) => this.activate(event);
    this.onEntryState = (event) => {
      if (event.detail?.surfaceId !== this.surfaceId) return;
      if ("eit:entry-removed" === event.type) {
        this.setMissing();
        return;
      }
      this.syncConnection();
      if ("eit:entry-busy" === event.type) this.setBusy(event.detail.busy);
    };
    this.button.addEventListener("click", this.onClick);
    document.addEventListener("eit:entry-ready", this.onEntryState);
    document.addEventListener("eit:entry-busy", this.onEntryState);
    document.addEventListener("eit:entry-removed", this.onEntryState);
    this.syncConnection();
  }

  workspace() {
    return document.querySelector(
      `[data-eit-entry-workspace][data-surface-id="${cssEscape(this.surfaceId)}"]`,
    );
  }

  syncConnection() {
    const workspace = this.workspace();
    if (!workspace) {
      this.setMissing();
      return null;
    }
    this.button.setAttribute("aria-controls", workspace.id);
    this.button.setAttribute("aria-invalid", "false");
    this.button.dataset.eitActionState = "ready";
    this.showStatus("", "");
    this.setBusy("true" === workspace.getAttribute("aria-busy"));
    return workspace;
  }

  setMissing() {
    this.button.removeAttribute("aria-controls");
    this.button.setAttribute("aria-invalid", "true");
    this.button.dataset.eitActionState = "missing";
    this.showStatus(
      window.eitConfig?.i18n?.entrySurfaceMissing ||
        "The connected Entry Surface is not present on this page.",
      "error",
    );
    this.setBusy(false);
  }

  activate(event) {
    event.preventDefault();
    if ("true" === this.button.getAttribute("aria-disabled")) return;
    const workspace = this.syncConnection();
    if (!workspace) return;
    const form = workspace?.querySelector("[data-eit-entry-form]");
    const submitter = workspace?.querySelector(
      `[data-eit-intent="${cssEscape(this.intent)}"]`,
    );
    if (!form || !submitter) {
      this.button.setAttribute("aria-invalid", "true");
      this.button.dataset.eitActionState = "unavailable";
      this.showStatus(
        window.eitConfig?.i18n?.entryActionUnavailable ||
          "This action is not available in the current item state.",
        "error",
      );
      return;
    }
    if ("function" === typeof form.requestSubmit) form.requestSubmit(submitter);
    else submitter.click();
  }

  setBusy(busy) {
    this.button.classList.toggle("is-busy", Boolean(busy));
    this.button.setAttribute("aria-busy", String(Boolean(busy)));
    if (busy) this.button.setAttribute("aria-disabled", "true");
    else this.button.removeAttribute("aria-disabled");
  }

  showStatus(message, state) {
    if (!this.status) return;
    this.status.hidden = !message;
    this.status.textContent = message;
    this.status.dataset.state = state;
  }

  destroy() {
    this.button.removeEventListener("click", this.onClick);
    document.removeEventListener("eit:entry-ready", this.onEntryState);
    document.removeEventListener("eit:entry-busy", this.onEntryState);
    document.removeEventListener("eit:entry-removed", this.onEntryState);
    this.setBusy(false);
  }
}

export function initializeAction(button) {
  if (!button) return null;
  const current = instances.get(button);
  if (current) return current;
  const instance = new ActionConnector(button);
  instances.set(button, instance);
  return instance;
}

export function destroyAction(button) {
  const instance = button ? instances.get(button) : null;
  instance?.destroy();
  if (button) instances.delete(button);
}

export function installConnectorActions(scope = document) {
  scope
    .querySelectorAll?.("[data-eit-action-surface]")
    .forEach(initializeAction);
}
