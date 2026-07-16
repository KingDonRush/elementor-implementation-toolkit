import { beforeEach, describe, expect, it, vi } from "vitest";
import { EntryWorkspace } from "../../assets/src/frontend/entry-workspace.js";
import { matchesEntryCondition } from "../../assets/src/frontend/entry-condition.js";
import { evaluateEntryExpression } from "../../assets/src/frontend/entry-expression.js";

const titleId = "11111111-1111-4111-8111-111111111111";
const toggleId = "22222222-2222-4222-8222-222222222222";
const detailsId = "33333333-3333-4333-8333-333333333333";
const choiceId = "44444444-4444-4444-8444-444444444444";

function contract() {
  return {
    surface_id: "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa",
    title_field_id: titleId,
    fields: [
      { id: titleId, type: "short_text", validation: { required: true } },
      { id: toggleId, type: "short_text", validation: {} },
      { id: detailsId, type: "short_text", validation: { required: true } },
      { id: choiceId, type: "multiple_choice", validation: { required: true } },
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
  return `<div data-eit-entry-field="${id}" data-field-type="short_text"><input value="${value}" aria-describedby="${id}-error" aria-invalid="false"><p id="${id}-error" data-eit-field-error></p></div>`;
}

function choiceField() {
  return `<div data-eit-entry-field="${choiceId}" data-field-type="multiple_choice"><fieldset data-eit-choice-group aria-describedby="${choiceId}-error"><label><input type="checkbox" value="one" aria-describedby="${choiceId}-error" aria-invalid="false"> One</label><label><input type="checkbox" value="two" aria-describedby="${choiceId}-error" aria-invalid="false"> Two</label></fieldset><p id="${choiceId}-error" data-eit-field-error></p></div>`;
}

beforeEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  window.eitConfig = {
    entryRestUrl: "/wp-json/eit/v1",
    nonce: "nonce",
    i18n: {
      entrySaving: "Saving",
      entrySaved: "Saved",
      entryAutosaved: "Autosaved",
      entryError: "Preserved",
      entryUploading: "Uploading",
      entryChoiceRequired: "Choose one",
    },
  };
  document.body.innerHTML = `<section id="entry-instance" data-eit-entry-workspace data-surface-id="aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa" data-item-id="0"><form data-eit-entry-form><div data-eit-form-message></div><section data-eit-entry-step="0"><h3 tabindex="-1">Details</h3>${field(titleId, "0")}${field(toggleId, "no")}${field(detailsId, "keep me")}${choiceField()}</section><input data-eit-form-token><input data-eit-honeypot><footer><button data-eit-next hidden></button><button data-eit-previous hidden></button><div data-eit-submit-actions><button type="submit" data-eit-intent="save_draft">Save</button></div></footer></form><script type="application/json" data-eit-entry-contract>${JSON.stringify(contract())}</script></section>`;
});

describe("Entry workspace", () => {
  it("evaluates the browser preview with the same bounded arithmetic grammar", () => {
    expect(
      evaluateEntryExpression(`{${titleId}} * 2 + 1`, { [titleId]: "3" }),
    ).toBe(7);
    expect(evaluateEntryExpression("alert(1)", {})).toBeNull();
    expect(evaluateEntryExpression("2 / 0", {})).toBeNull();
    expect(evaluateEntryExpression(`{${titleId}} + 1`, { [titleId]: "" })).toBeNull();
    expect(evaluateEntryExpression(`{${titleId}} + 1`, { [titleId]: false })).toBeNull();
  });

  it("matches numeric and relation conditions with server-compatible values", () => {
    expect(matchesEntryCondition("", "gte", 0)).toBe(false);
    expect(matchesEntryCondition({ id: 42 }, "equals", "42")).toBe(true);
    expect(
      matchesEntryCondition([{ id: 42 }, { id: 7 }], "in", ["42"]),
    ).toBe(true);
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

  it("validates required multiple choices as one group and synchronizes ARIA errors", () => {
    const workspace = new EntryWorkspace(
      document.querySelector("[data-eit-entry-workspace]"),
    );
    const wrapper = document.querySelector(
      `[data-eit-entry-field="${choiceId}"]`,
    );
    const [first, second] = wrapper.querySelectorAll('input[type="checkbox"]');

    expect(first.required).toBe(false);
    expect(second.required).toBe(false);
    expect(first.validationMessage).toBe("Choose one");
    expect(workspace.form.checkValidity()).toBe(false);

    second.checked = true;
    second.dispatchEvent(new Event("input", { bubbles: true }));
    expect(workspace.form.checkValidity()).toBe(true);

    workspace.showErrors({
      message: "Review fields",
      data: { fields: { [choiceId]: "Choose a supported tier" } },
    });
    expect(first.getAttribute("aria-invalid")).toBe("true");
    expect(wrapper.querySelector("fieldset").getAttribute("aria-invalid")).toBe(
      "true",
    );
    expect(wrapper.querySelector("[data-eit-field-error]").textContent).toBe(
      "Choose a supported tier",
    );
    workspace.clearErrors();
    expect(first.getAttribute("aria-invalid")).toBe("false");
  });

  it("removes form handlers and aborts pending work when Elementor destroys it", () => {
    const workspace = new EntryWorkspace(
      document.querySelector("[data-eit-entry-workspace]"),
    );
    const abort = vi.fn();
    workspace.abortController = { abort };
    workspace.dirty = false;

    workspace.destroy();
    document
      .querySelector(`[data-eit-entry-field="${titleId}"] input`)
      .dispatchEvent(new Event("input", { bubbles: true }));

    expect(workspace.destroyed).toBe(true);
    expect(workspace.dirty).toBe(false);
    expect(abort).toHaveBeenCalledOnce();
  });

  it("does not mark content dirty when the implementer only searches relation options", () => {
    document.querySelector("[data-eit-entry-form]").insertAdjacentHTML(
      "afterbegin",
      '<div data-eit-relation-picker data-collection-id="aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"><input data-eit-relation-search><select data-eit-relation-select></select><p data-eit-relation-status></p><button data-eit-relation-more></button></div>',
    );
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({
        ok: true,
        json: async () => ({ items: [], pagination: { page: 1, pages: 1, total: 0 } }),
      }),
    );
    const workspace = new EntryWorkspace(
      document.querySelector("[data-eit-entry-workspace]"),
    );
    const search = document.querySelector("[data-eit-relation-search]");
    workspace.dirty = false;

    search.dispatchEvent(new Event("input", { bubbles: true }));
    search.dispatchEvent(new Event("change", { bubbles: true }));

    expect(workspace.dirty).toBe(false);
    workspace.destroy();
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

  it("reuses an idempotency key after an ambiguous transport failure", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("offline")));
    const workspace = new EntryWorkspace(
      document.querySelector("[data-eit-entry-workspace]"),
    );
    const key = workspace.key;

    await workspace.send("save_draft");

    expect(workspace.key).toBe(key);
  });

  it("keeps the saved media value and preview when replacement upload fails", async () => {
    const form = document.querySelector("[data-eit-entry-form]");
    form.insertAdjacentHTML(
      "afterbegin",
      '<div data-eit-entry-field="media" data-field-type="image"><input type="file"><input type="hidden" data-eit-media-value value=\'{"id":99}\'><div data-eit-media-preview><span>Existing image</span></div></div>',
    );
    const input = form.querySelector('[data-eit-entry-field="media"] input[type="file"]');
    Object.defineProperty(input, "files", {
      value: [new File(["image"], "replacement.png", { type: "image/png" })],
    });
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({
      ok: false,
      json: async () => ({ message: "Upload rejected" }),
    }));
    vi.stubGlobal("URL", {
      ...URL,
      createObjectURL: vi.fn(() => "blob:replacement"),
      revokeObjectURL: vi.fn(),
    });
    const workspace = new EntryWorkspace(
      document.querySelector("[data-eit-entry-workspace]"),
    );

    await workspace.uploadMedia(input);

    expect(form.querySelector("[data-eit-media-value]").value).toBe('{"id":99}');
    expect(form.querySelector("[data-eit-media-preview]").textContent).toContain(
      "Existing image",
    );
  });
});
