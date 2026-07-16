import { exposeClientValidity } from "./entry-validation.js";
import {
  destroyEntryRelations,
  initializeEntryRelations,
} from "./entry-relation-picker.js";

export function bindEntryWorkspace(workspace) {
  workspace.relationPickers = initializeEntryRelations(workspace.root);
  workspace.root
    .querySelectorAll("[data-eit-repeater]")
    .forEach((repeater) => workspace.syncRepeater(repeater));
  workspace.onInput = (event) => {
    if (event.target.matches("[data-eit-relation-search]")) return;
    workspace.dirty = true;
    workspace.applyConditions();
    const wrapper = event.target.closest?.("[data-eit-entry-field]");
    if (wrapper?.classList.contains("has-error")) exposeClientValidity(wrapper);
  };
  workspace.onChange = (event) => {
    if (event.target.matches("[data-eit-relation-search]")) return;
    workspace.dirty = true;
    workspace.applyConditions();
    if (event.target.matches("[data-eit-media-input]")) {
      workspace.uploadMedia(event.target);
    }
  };
  workspace.onSubmit = (event) => workspace.submit(event);
  workspace.onNext = () => workspace.nextStep();
  workspace.onPrevious = () => workspace.previousStep();
  workspace.onRootClick = (event) => {
    const add = event.target.closest("[data-eit-add-row]");
    const remove = event.target.closest("[data-eit-remove-row]");
    if (add) {
      workspace.addRepeaterRow(add.closest("[data-eit-repeater]"));
    } else if (remove) {
      const repeater = remove.closest("[data-eit-repeater]");
      const minimum = Math.max(
        0,
        Number(repeater?.dataset.eitMinRows) || 0,
      );
      const rows =
        repeater?.querySelectorAll(":scope > [data-eit-repeater-row]") || [];
      if (rows.length > minimum) {
        remove.closest("[data-eit-repeater-row]")?.remove();
        workspace.syncRepeater(repeater);
        workspace.dirty = true;
      }
    }
  };
  workspace.form.addEventListener("input", workspace.onInput);
  workspace.form.addEventListener("change", workspace.onChange);
  workspace.form.addEventListener("submit", workspace.onSubmit);
  workspace.root.querySelector("[data-eit-next]")?.addEventListener("click", workspace.onNext);
  workspace.root.querySelector("[data-eit-previous]")?.addEventListener("click", workspace.onPrevious);
  workspace.root.addEventListener("click", workspace.onRootClick);
}

export function destroyEntryWorkspace(workspace) {
  destroyEntryRelations(workspace.relationPickers);
  workspace.relationPickers = [];
  workspace.form.removeEventListener("input", workspace.onInput);
  workspace.form.removeEventListener("change", workspace.onChange);
  workspace.form.removeEventListener("submit", workspace.onSubmit);
  workspace.root.querySelector("[data-eit-next]")?.removeEventListener("click", workspace.onNext);
  workspace.root.querySelector("[data-eit-previous]")?.removeEventListener("click", workspace.onPrevious);
  workspace.root.removeEventListener("click", workspace.onRootClick);
  window.clearInterval(workspace.autosaveTimer);
  workspace.autosaveTimer = null;
  workspace.abortController?.abort();
  workspace.abortController = null;
  workspace.mediaAbortController?.abort();
  workspace.mediaAbortController = null;
  workspace.busy = false;
  workspace.root.setAttribute("aria-busy", "false");
  workspace.root.dispatchEvent(
    new CustomEvent("eit:entry-busy", {
      bubbles: true,
      detail: { surfaceId: workspace.contract.surface_id, busy: false },
    }),
  );
}
