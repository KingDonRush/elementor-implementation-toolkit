import { beforeEach, describe, expect, it, vi } from "vitest";
import { EntryWorkspace } from "../../assets/src/frontend/entry-workspace.js";
import { evaluateEntryExpression } from "../../assets/src/frontend/entry-expression.js";

const titleId = "11111111-1111-4111-8111-111111111111";
const toggleId = "22222222-2222-4222-8222-222222222222";
const detailsId = "33333333-3333-4333-8333-333333333333";

function contract() {
  return {
    surface_id: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
    title_field_id: titleId,
    fields: [
      { id: titleId, type: "short_text", validation: { required: true } },
      { id: toggleId, type: "short_text", validation: {} },
      { id: detailsId, type: "short_text", validation: { required: true } },
    ],
    conditions: [
      {
        source_field_id: toggleId,
        target_field_id: detailsId,
        operator: "equals",
        value: "yes",
        effect: "show",
      },
    ],
    steps: [
      {
        id: "step",
        name: "Details",
        field_ids: [titleId, toggleId, detailsId],
      },
    ],
    autosave: { enabled: false },
    authenticated: true,
  };
}

function field(id, value = "") {
  return `<div data-eit-entry-field="${id}" data-field-type="short_text"><input value="${value}"><p data-eit-field-error></p></div>`;
}

beforeEach(() => {
  window.eitConfig = {
    entryRestUrl: "/wp-json/eit/v1",
    nonce: "nonce",
    i18n: {
      entrySaving: "Saving",
      entrySaved: "Saved",
      entryAutosaved: "Autosaved",
      entryError: "Preserved",
      entryUploading: "Uploading",
    },
  };
  document.body.innerHTML = `<section data-eit-entry-workspace data-item-id="0"><form data-eit-entry-form><div data-eit-form-message></div><section data-eit-entry-step="0"><h3 tabindex="-1">Details</h3>${field(titleId, "0")}${field(toggleId, "no")}${field(detailsId, "keep me")}</section><input data-eit-form-token><input data-eit-honeypot><footer><button data-eit-next hidden></button><button data-eit-previous hidden></button><div data-eit-submit-actions><button type="submit" data-eit-intent="save_draft">Save</button></div></footer></form><script type="application/json" data-eit-entry-contract>${JSON.stringify(contract())}</script></section>`;
});

describe("Entry workspace", () => {
  it("evaluates the browser preview with the same bounded arithmetic grammar", () => {
    expect(
      evaluateEntryExpression(`{${titleId}} * 2 + 1`, { [titleId]: "3" }),
    ).toBe(7);
    expect(evaluateEntryExpression("alert(1)", {})).toBeNull();
    expect(evaluateEntryExpression("2 / 0", {})).toBeNull();
  });
  it("keeps required zero, applies conditions and does not serialize hidden values", () => {
    const workspace = new EntryWorkspace(
      document.querySelector("[data-eit-entry-workspace]"),
    );
    expect(workspace.values()[titleId]).toBe("0");
    expect(
      document.querySelector(`[data-eit-entry-field="${detailsId}"]`).hidden,
    ).toBe(true);
    expect(workspace.values()).not.toHaveProperty(detailsId);
    document.querySelector(`[data-eit-entry-field="${toggleId}"] input`).value =
      "yes";
    workspace.applyConditions();
    expect(
      document.querySelector(`[data-eit-entry-field="${detailsId}"]`).hidden,
    ).toBe(false);
  });

  it("keeps the submit trigger focused while the workspace is busy", () => {
    const workspace = new EntryWorkspace(
      document.querySelector("[data-eit-entry-workspace]"),
    );
    const submit = document.querySelector('button[type="submit"]');
    submit.focus();

    workspace.setBusy(true, "Saving");
    expect(document.activeElement).toBe(submit);
    expect(submit.disabled).toBe(false);
    expect(submit.getAttribute("aria-disabled")).toBe("true");

    workspace.setBusy(false);
    expect(document.activeElement).toBe(submit);
    expect(submit.hasAttribute("aria-disabled")).toBe(false);
  });

  it("preserves form state and exposes inline server errors", async () => {
    vi.stubGlobal(
      "fetch",
      vi
        .fn()
        .mockResolvedValue({
          ok: false,
          json: async () => ({
            message: "Review fields",
            data: { fields: { [titleId]: "Title is invalid" } },
          }),
        }),
    );
    const workspace = new EntryWorkspace(
      document.querySelector("[data-eit-entry-workspace]"),
    );
    await workspace.send("save_draft");
    expect(
      document.querySelector(`[data-eit-entry-field="${titleId}"] input`).value,
    ).toBe("0");
    expect(
      document
        .querySelector(`[data-eit-entry-field="${titleId}"]`)
        .classList.contains("has-error"),
    ).toBe(true);
    expect(document.querySelector("[data-eit-form-message]").textContent).toBe(
      "Review fields",
    );
  });
});
