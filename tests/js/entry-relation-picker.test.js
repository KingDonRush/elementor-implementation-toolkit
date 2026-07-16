import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { EntryRelationPicker } from "../../assets/src/frontend/entry-relation-picker.js";

function markup() {
  document.body.innerHTML = `<div data-eit-relation-picker data-collection-id="aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa"><input type="search" data-eit-relation-search><select multiple data-eit-relation-select><option value="9" selected>Selected agent</option></select><p data-eit-relation-status></p><button type="button" data-eit-relation-more hidden>More</button></div>`;
  return document.querySelector("[data-eit-relation-picker]");
}

beforeEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  window.eitConfig = {
    collectionRestUrl: "/wp-json/eit/v1/collections",
    nonce: "nonce",
    i18n: { entryRelationCount: "%d options available." },
  };
});

afterEach(() => {
  vi.useRealTimers();
});

describe("Entry relation picker", () => {
  it("loads authorized Collection pages without losing an existing selection", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          items: [
            { id: "1", title: "Ana" },
            { id: "2", title: "Bruno" },
          ],
          pagination: { page: 1, pages: 2, total: 3 },
        }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({
          items: [{ id: "3", title: "Carla" }],
          pagination: { page: 2, pages: 2, total: 3 },
        }),
      });
    vi.stubGlobal("fetch", fetchMock);
    const root = markup();
    const picker = new EntryRelationPicker(root);

    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    await vi.waitFor(() =>
      expect([...picker.select.options].map((option) => option.value)).toEqual([
        "9",
        "1",
        "2",
      ]),
    );
    expect(picker.select.selectedOptions[0].value).toBe("9");
    expect(picker.more.hidden).toBe(false);

    picker.more.click();
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
    await vi.waitFor(() =>
      expect([...picker.select.options].map((option) => option.value)).toEqual([
        "9",
        "1",
        "2",
        "3",
      ]),
    );
    expect(picker.more.hidden).toBe(true);
    expect(root.getAttribute("aria-busy")).toBe("false");
  });

  it("preserves options on failure and aborts work when destroyed", async () => {
    let rejectRequest;
    const pending = new Promise((resolve, reject) => {
      rejectRequest = reject;
    });
    vi.stubGlobal("fetch", vi.fn(() => pending));
    const root = markup();
    const picker = new EntryRelationPicker(root);
    const abort = vi.spyOn(picker.abortController, "abort");

    picker.destroy();
    rejectRequest({ name: "AbortError" });
    await Promise.resolve();

    expect(abort).toHaveBeenCalledOnce();
    expect(picker.select.selectedOptions[0].textContent).toBe("Selected agent");
    expect(root.getAttribute("aria-busy")).toBe("false");
  });

  it("reports a recoverable error without replacing the selected labels", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue({
        ok: false,
        json: async () => ({ message: "Collection access denied" }),
      }),
    );
    const root = markup();
    const picker = new EntryRelationPicker(root);

    await vi.waitFor(() => expect(root.dataset.state).toBe("error"));

    expect(picker.select.selectedOptions[0].textContent).toBe("Selected agent");
    expect(picker.status.textContent).toBe("Collection access denied");
    expect(root.getAttribute("aria-busy")).toBe("false");
  });

  it("debounces search into the closed Collection request grammar", async () => {
    vi.useFakeTimers();
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({
        items: [],
        pagination: { page: 1, pages: 1, total: 0 },
      }),
    });
    vi.stubGlobal("fetch", fetchMock);
    const root = markup();
    const picker = new EntryRelationPicker(root);
    await vi.advanceTimersByTimeAsync(0);
    picker.search.value = "  Ana  ";
    picker.search.dispatchEvent(new Event("input", { bubbles: true }));

    await vi.advanceTimersByTimeAsync(249);
    expect(fetchMock).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(1);
    expect(fetchMock).toHaveBeenCalledTimes(2);

    const request = JSON.parse(fetchMock.mock.calls[1][1].body);
    expect(request).toEqual({
      page: 1,
      per_page: 24,
      filters: [],
      facets: [],
      search: "Ana",
    });
    picker.destroy();
  });
});
