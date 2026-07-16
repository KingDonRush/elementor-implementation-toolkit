import jquery from 'jquery';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

let installCollectionPairing;
let uninstallCollectionPairing;
let installDynamicTagContext;
let uninstallDynamicTagContext;

beforeAll(async () => {
  window.jQuery = jquery;
  ({ installCollectionPairing, uninstallCollectionPairing } = await import(
    '../../assets/src/editor/collection-pairing.js'
  ));
  ({ installDynamicTagContext, uninstallDynamicTagContext } = await import(
    '../../assets/src/editor/dynamic-tag-context.js'
  ));
});

beforeEach(() => {
  uninstallCollectionPairing?.();
  uninstallDynamicTagContext?.();
  document.body.innerHTML = `
    <div id="elementor-panel">
      <div id="elementor-panel-content-wrapper"></div>
    </div>
    <iframe id="elementor-preview-iframe"></iframe>
  `;
});

describe('Elementor context editor lifecycle', () => {
  it('replaces hooks on reinstall and observes the preview only while a Toolkit Surface is active', async () => {
    const observers = [];
    class Observer {
      constructor(callback) {
        this.callback = callback;
        this.disconnect = vi.fn();
        this.observe = vi.fn();
        observers.push(this);
      }
    }
    window.MutationObserver = Observer;

    const actions = new Map();
    const addAction = vi.fn((name, callback) => actions.set(name, callback));
    const removeAction = vi.fn((name, callback) => {
      if (actions.get(name) === callback) actions.delete(name);
    });
    window.elementor = { hooks: { addAction, removeAction } };

    installCollectionPairing();
    const firstWidgetHook = actions.get('panel/open_editor/widget');
    firstWidgetHook(null, { get: () => 'eit-toolkit-filter-surface' });
    await Promise.resolve();

    expect(observers).toHaveLength(1);
    expect(observers[0].observe).toHaveBeenCalledWith(
      document.querySelector('#elementor-preview-iframe').contentDocument.body,
      expect.objectContaining({ childList: true, subtree: true }),
    );

    firstWidgetHook(null, { get: () => 'heading' });
    expect(observers[0].disconnect).toHaveBeenCalledOnce();

    installCollectionPairing();
    expect(removeAction).toHaveBeenCalledWith('panel/open_editor/widget', firstWidgetHook);
    expect(actions.get('panel/open_editor/widget')).not.toBe(firstWidgetHook);

    uninstallCollectionPairing();
    expect(actions.has('panel/open_editor/widget')).toBe(false);
  });

  it('replaces and disconnects the narrowly scoped Dynamic Tag panel observer', () => {
    const observers = [];
    class Observer {
      constructor(callback) {
        this.callback = callback;
        this.disconnect = vi.fn();
        this.observe = vi.fn();
        observers.push(this);
      }
    }
    window.MutationObserver = Observer;

    installDynamicTagContext();
    expect(observers).toHaveLength(1);
    expect(observers[0].observe).toHaveBeenCalledWith(
      document.querySelector('#elementor-panel-content-wrapper'),
      { childList: true, subtree: true },
    );

    installDynamicTagContext();
    expect(observers[0].disconnect).toHaveBeenCalledOnce();
    expect(observers).toHaveLength(2);

    uninstallDynamicTagContext();
    expect(observers[1].disconnect).toHaveBeenCalledOnce();
  });
});
