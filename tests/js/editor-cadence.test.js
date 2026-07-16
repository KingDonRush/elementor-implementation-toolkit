import jquery from "jquery";
import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";

let installCadence;
let uninstallCadence;

beforeAll(async () => {
  window.jQuery = jquery;
  ({ installCadence, uninstallCadence } = await import(
    "../../assets/src/editor/cadence.js"
  ));
});

beforeEach(() => {
  uninstallCadence?.();
  document.body.innerHTML = `<div id="elementor-panel"><div id="elementor-panel-content-wrapper"><div id="elementor-controls"><div class="elementor-control-filters"><select data-setting="type"><option value="search" selected>Search</option></select></div></div></div></div>`;
});

describe("legacy editor cadence fallback", () => {
  it("observes only the active controls subtree and disconnects on cleanup", async () => {
    const observe = vi.fn();
    const disconnect = vi.fn();
    let observerCallback = null;
    class Observer {
      constructor(callback) {
        this.callback = callback;
        observerCallback = callback;
      }
      observe(...args) {
        observe(...args);
      }
      disconnect() {
        disconnect();
      }
    }
    window.MutationObserver = Observer;
    const addAction = vi.fn();
    const removeAction = vi.fn();
    const container = { settings: { get: () => "" } };
    let widgetType = "eit-filter-controller";
    const view = {
      model: { get: (key) => ("widgetType" === key ? widgetType : "") },
      getContainer: () => container,
    };
    window.elementor = {
      hooks: { addAction, removeAction },
      getPanelView: () => ({
        getCurrentPageView: () => ({ getOption: () => view }),
      }),
    };

    const cleanup = installCadence(vi.fn());
    await vi.waitFor(() => expect(observe).toHaveBeenCalled());

    expect(observe).toHaveBeenCalledWith(
      document.querySelector("#elementor-panel-content-wrapper"),
      { childList: true, subtree: true },
    );
    widgetType = "heading";
    observerCallback();
    await vi.waitFor(() => expect(disconnect).toHaveBeenCalledOnce());
    cleanup();
    expect(disconnect).toHaveBeenCalledOnce();
    expect(removeAction).toHaveBeenCalledWith(
      "panel/open_editor/widget/eit-filter-controller",
      expect.any(Function),
    );
  });
});
