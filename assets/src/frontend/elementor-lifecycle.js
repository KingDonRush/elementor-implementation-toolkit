import { destroyAction, initializeAction, installConnectorActions } from "./connector-actions.js";
import { Controller } from "./controller.js";
import { EntryWorkspace } from "./entry-workspace.js";
import { $ } from "./runtime.js";

const filterInstances = new WeakMap();
const entryInstances = new WeakMap();
let handlersInstalled = false;

function elementFromScope(scope) {
  return scope?.get?.(0) || scope?.[0] || scope || null;
}

function findRoot(scope, selector) {
  const element = elementFromScope(scope);
  if (!element) return null;
  return element.matches?.(selector) ? element : element.querySelector(selector);
}

export function initializeFilter(root) {
  if (!root) return null;
  const current = filterInstances.get(root);
  if (current) return current;
  const instance = new Controller(root);
  filterInstances.set(root, instance);
  return instance;
}

export function destroyFilter(root) {
  const instance = root ? filterInstances.get(root) : null;
  instance?.destroy();
  if (root) filterInstances.delete(root);
}

export function initializeEntry(root) {
  if (!root) return null;
  const current = entryInstances.get(root);
  if (current) return current;
  const instance = new EntryWorkspace(root);
  entryInstances.set(root, instance);
  return instance;
}

export function destroyEntry(root) {
  const instance = root ? entryInstances.get(root) : null;
  instance?.destroy();
  if (root) entryInstances.delete(root);
}

function handler(Base, selector, initialize, destroy) {
  return class ToolkitElementorHandler extends Base {
    onInit(...args) {
      super.onInit(...args);
      this.toolkitRoot = findRoot(this.$element, selector);
      this.toolkitInstance = initialize(this.toolkitRoot);
    }

    onDestroy(...args) {
      destroy(this.toolkitRoot);
      this.toolkitRoot = null;
      this.toolkitInstance = null;
      super.onDestroy(...args);
    }
  };
}

function registerHandlers() {
  if (handlersInstalled) return true;
  const frontend = window.elementorFrontend;
  const Base = window.elementorModules?.frontend?.handlers?.Base;
  if (!frontend?.elementsHandler?.attachHandler || !Base) return false;

  const FilterHandler = handler(
    Base,
    ".eit-filter-controller",
    initializeFilter,
    destroyFilter,
  );
  const EntryHandler = handler(
    Base,
    "[data-eit-entry-workspace]",
    initializeEntry,
    destroyEntry,
  );
  const ActionHandler = handler(
    Base,
    "[data-eit-action-surface]",
    initializeAction,
    destroyAction,
  );

  frontend.elementsHandler.attachHandler("eit-filter-controller", FilterHandler);
  frontend.elementsHandler.attachHandler("eit-toolkit-filter-surface", FilterHandler);
  frontend.elementsHandler.attachHandler("eit-toolkit-entry-surface", EntryHandler);
  frontend.elementsHandler.attachHandler("eit-toolkit-action", ActionHandler);
  handlersInstalled = true;
  return true;
}

export function installElementorLifecycle() {
  $(window)
    .off("elementor/frontend/init.eitToolkit")
    .on("elementor/frontend/init.eitToolkit", registerHandlers);
  registerHandlers();
}

export function initializeFrontendFallbacks(scope = document) {
  scope.querySelectorAll(".eit-filter-controller").forEach(initializeFilter);
  scope.querySelectorAll("[data-eit-entry-workspace]").forEach(initializeEntry);
  installConnectorActions(scope);
}
