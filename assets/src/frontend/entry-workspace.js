import { evaluateEntryExpression } from "./entry-expression.js";
import { uploadEntryMedia } from "./entry-media-client.js";
import { setEntryButtonsBusy, uniqueEntryKey } from "./entry-request-state.js";
import { readEntryValues } from "./entry-values.js";

export class EntryWorkspace {
  constructor(root) {
    this.root = root;
    this.form = root.querySelector("[data-eit-entry-form]");
    this.contract = JSON.parse(
      root.querySelector("[data-eit-entry-contract]").textContent,
    );
    this.step = 0;
    this.dirty = false;
    this.busy = false;
    this.abortController = null;
    this.key = uniqueEntryKey();
    this.root.setAttribute("aria-busy", "false");
    this.bind();
    this.applyConditions();
    this.updateStep();
    this.installAutosave();
  }

  bind() {
    this.form.addEventListener("input", () => {
      this.dirty = true;
      this.applyConditions();
    });
    this.form.addEventListener("change", (event) => {
      this.dirty = true;
      this.applyConditions();
      if (event.target.matches("[data-eit-media-input]")) {
        this.uploadMedia(event.target);
      }
    });
    this.form.addEventListener("submit", (event) => this.submit(event));
    this.root
      .querySelector("[data-eit-next]")
      ?.addEventListener("click", () => this.nextStep());
    this.root
      .querySelector("[data-eit-previous]")
      ?.addEventListener("click", () => this.previousStep());
    this.root.addEventListener("click", (event) => {
      const add = event.target.closest("[data-eit-add-row]");
      const remove = event.target.closest("[data-eit-remove-row]");
      if (add) {
        this.addRepeaterRow(add.closest("[data-eit-repeater]"));
      } else if (remove) {
        remove.closest("[data-eit-repeater-row]")?.remove();
        this.dirty = true;
      }
    });
  }

  values() {
    return readEntryValues(this.root);
  }

  applyConditions() {
    const values = this.values();
    this.updateCalculations(values);
    const state = Object.fromEntries(
      this.contract.fields.map((field) => [
        field.id,
        { visible: true, required: Boolean(field.validation?.required) },
      ]),
    );
    for (const condition of this.contract.conditions || []) {
      if (!state[condition.target_field_id]) continue;
      const matched = this.matches(
        values[condition.source_field_id],
        condition.operator,
        condition.value,
      );
      if ("show" === condition.effect)
        state[condition.target_field_id].visible &&= matched;
      if ("hide" === condition.effect && matched)
        state[condition.target_field_id].visible = false;
      if ("require" === condition.effect && matched)
        state[condition.target_field_id].required = true;
    }
    for (const [fieldId, fieldState] of Object.entries(state)) {
      const wrapper = this.root.querySelector(
        `[data-eit-entry-field="${fieldId}"]`,
      );
      if (!wrapper) continue;
      wrapper.hidden = !fieldState.visible;
      wrapper.querySelectorAll("input, textarea, select").forEach((input) => {
        input.disabled = !fieldState.visible;
      });
      const control = wrapper.querySelector(
        'input:not([type="hidden"]), textarea, select',
      );
      if (control) {
        const hasMedia =
          ["image", "gallery", "file"].includes(wrapper.dataset.fieldType) &&
          Boolean(
            wrapper
              .querySelector("[data-eit-media-value]")
              ?.value?.replace(/\[\]|null/, ""),
          );
        control.required =
          fieldState.visible && fieldState.required && !hasMedia;
      }
    }
  }

  updateCalculations(values) {
    for (const field of this.contract.fields.filter(
      (candidate) => "calculated" === candidate.type,
    )) {
      const output = this.root.querySelector(
        `[data-eit-entry-field="${field.id}"] [data-eit-calculated]`,
      );
      if (!output) continue;
      const result = evaluateEntryExpression(
        field.validation?.expression,
        values,
      );
      output.value = null === result ? "" : String(result);
      output.textContent =
        null === result
          ? window.eitConfig.i18n.entryCalculationWaiting
          : String(result);
    }
  }

  matches(actual, operator, expected) {
    if ("empty" === operator || "not_empty" === operator) {
      const empty =
        null == actual ||
        "" === actual ||
        (Array.isArray(actual) && 0 === actual.length);
      return "empty" === operator ? empty : !empty;
    }
    if ("in" === operator || "not_in" === operator) {
      const actualList = Array.isArray(actual)
        ? actual.map(String)
        : [String(actual)];
      const expectedList = (
        Array.isArray(expected) ? expected : [expected]
      ).map(String);
      const found = actualList.some((value) => expectedList.includes(value));
      return "in" === operator ? found : !found;
    }
    if (["gt", "gte", "lt", "lte"].includes(operator)) {
      const left = Number(actual);
      const right = Number(expected);
      if (!Number.isFinite(left) || !Number.isFinite(right)) return false;
      return {
        gt: left > right,
        gte: left >= right,
        lt: left < right,
        lte: left <= right,
      }[operator];
    }
    const equal = String(actual) === String(expected);
    return "not_equals" === operator ? !equal : equal;
  }

  nextStep() {
    const current = this.steps()[this.step];
    const invalid = [
      ...current.querySelectorAll("input, textarea, select"),
    ].find((input) => !input.disabled && !input.checkValidity());
    if (invalid) {
      invalid.reportValidity();
      invalid.focus();
      return;
    }
    this.step = Math.min(this.steps().length - 1, this.step + 1);
    this.updateStep(true);
  }

  previousStep() {
    this.step = Math.max(0, this.step - 1);
    this.updateStep(true);
  }

  updateStep(focus = false) {
    const steps = this.steps();
    steps.forEach((step, index) => (step.hidden = index !== this.step));
    const previous = this.root.querySelector("[data-eit-previous]");
    const next = this.root.querySelector("[data-eit-next]");
    const submits = this.root.querySelector("[data-eit-submit-actions]");
    if (previous) previous.hidden = 0 === this.step;
    if (next) next.hidden = this.step === steps.length - 1;
    if (submits) submits.hidden = this.step !== steps.length - 1;
    const progress = this.root.querySelector("[data-eit-step-progress]");
    const label = this.root.querySelector("[data-eit-step-label]");
    if (progress) progress.value = this.step + 1;
    if (label) label.textContent = `${this.step + 1} / ${steps.length}`;
    if (focus) steps[this.step].querySelector("h3")?.focus();
  }

  async submit(event) {
    event.preventDefault();
    const intent = event.submitter?.dataset.eitIntent || "default";
    if (!["archive", "restore"].includes(intent) && !this.form.reportValidity())
      return;
    await this.send(intent);
  }

  async send(intent) {
    if (this.busy) return;
    this.abortController?.abort();
    this.abortController = new AbortController();
    this.setBusy(true, window.eitConfig.i18n.entrySaving);
    this.clearErrors();
    const body = {
      surface_id: this.contract.surface_id,
      item_id: Number(this.root.dataset.itemId) || 0,
      intent,
      values: this.values(),
      content:
        this.root.querySelector("[data-eit-editorial-content]")?.value ?? null,
      idempotency_key: this.key,
      form_token: this.root.querySelector("[data-eit-form-token]")?.value || "",
      company_website:
        this.root.querySelector("[data-eit-honeypot]")?.value || "",
    };
    try {
      const response = await fetch(
        `${window.eitConfig.entryRestUrl}/entry-submissions`,
        {
          method: "POST",
          credentials: "same-origin",
          headers: this.headers(),
          body: JSON.stringify(body),
          signal: this.abortController.signal,
        },
      );
      const result = await response.json();
      if (!response.ok) throw result;
      this.root.dataset.itemId = result.item_id;
      this.contract.item = {
        ...(this.contract.item || {}),
        id: result.item_id,
        status: result.status,
      };
      const defaultAction = this.root.querySelector(
        '[data-eit-intent="default"]',
      );
      if (defaultAction)
        defaultAction.textContent = window.eitConfig.i18n.entrySaveChanges;
      this.dirty = false;
      this.key = uniqueEntryKey();
      this.message(
        "autosave" === intent
          ? window.eitConfig.i18n.entryAutosaved
          : window.eitConfig.i18n.entrySaved,
        "success",
      );
      const redirect = result.actions?.find(
        (action) => action.redirect,
      )?.redirect;
      if (redirect) window.location.assign(redirect);
    } catch (error) {
      if ("AbortError" === error?.name) return;
      this.key = uniqueEntryKey();
      this.showErrors(error);
    } finally {
      this.setBusy(false);
    }
  }

  async uploadMedia(input) {
    if (!input.files?.length) return;
    this.setBusy(true, window.eitConfig.i18n.entryUploading);
    try {
      const uploaded = await uploadEntryMedia({
        input,
        root: this.root,
        contract: this.contract,
        restUrl: window.eitConfig.entryRestUrl,
        nonceHeaders: this.nonceHeaders(),
      });
      if (!uploaded) return;
      const { wrapper, value } = uploaded;
      wrapper.querySelector("[data-eit-media-value]").value =
        JSON.stringify(value);
      input.required = false;
      this.dirty = true;
      this.message(window.eitConfig.i18n.entrySaved, "success");
    } catch (error) {
      const wrapper = input.closest("[data-eit-entry-field]");
      wrapper.querySelector("[data-eit-media-value]").value = "";
      this.showErrors(error);
    } finally {
      this.setBusy(false);
    }
  }

  installAutosave() {
    if (!this.contract.autosave?.enabled || !this.contract.authenticated)
      return;
    const interval =
      Math.max(15, Number(this.contract.autosave.interval_seconds) || 60) *
      1000;
    this.autosaveTimer = window.setInterval(() => {
      const title = this.values()[this.contract.title_field_id];
      if (
        this.dirty &&
        !this.busy &&
        null != title &&
        "" !== String(title).trim()
      )
        this.send("autosave");
    }, interval);
  }

  addRepeaterRow(repeater) {
    const fragment = repeater
      .querySelector("[data-eit-repeater-template]")
      ?.content.cloneNode(true);
    if (fragment)
      repeater.insertBefore(
        fragment,
        repeater.querySelector("[data-eit-repeater-template]"),
      );
    this.dirty = true;
  }

  showErrors(error) {
    this.message(error?.message || window.eitConfig.i18n.entryError, "error");
    const fields = error?.data?.fields || {};
    let firstInvalid = null;
    for (const [fieldId, message] of Object.entries(fields)) {
      const wrapper = this.root.querySelector(
        `[data-eit-entry-field="${fieldId}"]`,
      );
      if (!wrapper) continue;
      wrapper.classList.add("has-error");
      wrapper.querySelector("[data-eit-field-error]").textContent = message;
      firstInvalid ||= wrapper;
    }
    if (firstInvalid) {
      const step = firstInvalid.closest("[data-eit-entry-step]");
      if (step) {
        this.step = Number(step.dataset.eitEntryStep) || 0;
        this.updateStep();
      }
      firstInvalid.querySelector("input, textarea, select")?.focus();
    }
  }

  clearErrors() {
    this.root
      .querySelectorAll(".has-error")
      .forEach((field) => field.classList.remove("has-error"));
    this.root
      .querySelectorAll("[data-eit-field-error]")
      .forEach((error) => (error.textContent = ""));
  }

  setBusy(busy, message = "") {
    const active = document.activeElement;
    if (busy && active?.matches?.("button") && this.root.contains(active)) {
      this.busyFocus = active;
    }
    this.busy = busy;
    this.root.setAttribute("aria-busy", String(busy));
    setEntryButtonsBusy(this.root, busy, this.busyFocus);
    if (!busy && this.busyFocus?.isConnected) {
      this.busyFocus.focus({ preventScroll: true });
      this.busyFocus = null;
    }
    if (message) this.message(message, "info");
  }

  message(text, type) {
    const region = this.root.querySelector("[data-eit-form-message]");
    region.textContent = text;
    region.dataset.state = type;
  }

  headers() {
    return { "Content-Type": "application/json", ...this.nonceHeaders() };
  }

  nonceHeaders() {
    return window.eitConfig.nonce
      ? { "X-WP-Nonce": window.eitConfig.nonce }
      : {};
  }

  steps() {
    return [...this.root.querySelectorAll("[data-eit-entry-step]")];
  }
}
