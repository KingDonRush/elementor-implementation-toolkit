import { beforeEach, describe, expect, it, vi } from "vitest";
import { ActionConnector } from "../../assets/src/frontend/connector-actions.js";

const surfaceId = "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa";

beforeEach(() => {
  window.eitConfig = {
    i18n: {
      entrySurfaceMissing: "Surface missing",
      entryActionUnavailable: "Action unavailable",
    },
  };
  document.body.innerHTML = `<div data-eit-toolkit-action><button data-eit-action-surface="${surfaceId}" data-eit-action-intent="default" aria-describedby="action-status">Save</button><span id="action-status" data-eit-action-status hidden></span></div>`;
});

describe("Toolkit Action connector", () => {
  it("signals a missing surface and mirrors the connected workspace busy state", () => {
    const button = document.querySelector("button");
    const status = document.querySelector("[data-eit-action-status]");
    const connector = new ActionConnector(button);

    expect(button.dataset.eitActionState).toBe("missing");
    expect(button.getAttribute("aria-invalid")).toBe("true");
    expect(status.hidden).toBe(false);
    expect(status.textContent).toBe("Surface missing");

    button.click();
    expect(button.dataset.eitActionState).toBe("missing");
    expect(status.textContent).toBe("Surface missing");

    document.body.insertAdjacentHTML(
      "beforeend",
      `<section id="entry-unique" data-eit-entry-workspace data-surface-id="${surfaceId}" aria-busy="false"><form data-eit-entry-form><button type="submit" data-eit-intent="default">Internal save</button></form></section>`,
    );
    document
      .querySelector("[data-eit-entry-workspace]")
      .dispatchEvent(
        new CustomEvent("eit:entry-ready", {
          bubbles: true,
          detail: { surfaceId },
        }),
      );

    expect(button.getAttribute("aria-controls")).toBe("entry-unique");
    expect(button.getAttribute("aria-invalid")).toBe("false");
    expect(status.hidden).toBe(true);

    document
      .querySelector("[data-eit-entry-workspace]")
      .dispatchEvent(
        new CustomEvent("eit:entry-busy", {
          bubbles: true,
          detail: { surfaceId, busy: true },
        }),
      );
    expect(button.getAttribute("aria-busy")).toBe("true");
    expect(button.getAttribute("aria-disabled")).toBe("true");

    connector.destroy();
    document.dispatchEvent(
      new CustomEvent("eit:entry-busy", {
        detail: { surfaceId, busy: true },
      }),
    );
    expect(button.getAttribute("aria-busy")).toBe("false");
  });

  it("submits only an available governed action", () => {
    const button = document.querySelector("button");
    document.body.insertAdjacentHTML(
      "beforeend",
      `<section id="entry-unique" data-eit-entry-workspace data-surface-id="${surfaceId}" aria-busy="false"><form data-eit-entry-form><button type="submit" data-eit-intent="default">Internal save</button></form></section>`,
    );
    const form = document.querySelector("form");
    form.requestSubmit = vi.fn();
    const connector = new ActionConnector(button);

    button.click();
    expect(form.requestSubmit).toHaveBeenCalledWith(
      form.querySelector('[data-eit-intent="default"]'),
    );
    connector.destroy();
  });
});
