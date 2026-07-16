import jquery from "jquery";
import { beforeAll, describe, expect, it, vi } from "vitest";

let installElementorLifecycle;

beforeAll(async () => {
  window.jQuery = jquery;
  ({ installElementorLifecycle } = await import(
    "../../assets/src/frontend/elementor-lifecycle.js"
  ));
});

describe("Elementor connector lifecycle", () => {
  it("registers each interactive widget handler once through Elementor frontend hooks", () => {
    class BaseHandler {
      constructor(options) {
        this.$element = options.$element;
      }
      onInit() {}
      onDestroy() {}
    }
    const attachHandler = vi.fn();
    window.elementorModules = { frontend: { handlers: { Base: BaseHandler } } };
    window.elementorFrontend = { elementsHandler: { attachHandler } };

    installElementorLifecycle();
    installElementorLifecycle();

    expect(attachHandler.mock.calls.map(([name]) => name)).toEqual([
      "eit-filter-controller",
      "eit-toolkit-filter-surface",
      "eit-toolkit-entry-surface",
      "eit-toolkit-action",
    ]);

    const ActionHandler = attachHandler.mock.calls.find(
      ([name]) => "eit-toolkit-action" === name,
    )[1];
    const scope = jquery(
      `<div><div data-eit-toolkit-action><button data-eit-action-surface="surface" data-eit-action-intent="default"></button><span data-eit-action-status hidden></span></div></div>`,
    );
    document.body.append(scope.get(0));
    const button = scope.find("button").get(0);
    const handler = new ActionHandler({ $element: scope });
    handler.onInit();
    expect(button.dataset.eitActionState).toBe("missing");

    handler.onDestroy();
    document.body.insertAdjacentHTML(
      "beforeend",
      `<section id="late-entry" data-eit-entry-workspace data-surface-id="surface"></section>`,
    );
    document
      .querySelector("#late-entry")
      .dispatchEvent(
        new CustomEvent("eit:entry-ready", {
          bubbles: true,
          detail: { surfaceId: "surface" },
        }),
      );
    expect(button.dataset.eitActionState).toBe("missing");
  });
});
