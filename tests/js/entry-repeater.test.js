import { describe, expect, it } from "vitest";
import { EntryWorkspace } from "../../assets/src/frontend/entry-workspace.js";
import { syncEntryRepeater } from "../../assets/src/frontend/entry-repeater.js";
import { readEntryValues } from "../../assets/src/frontend/entry-values.js";

describe("typed Entry repeaters", () => {
  it("serializes checkbox children as booleans", () => {
    document.body.innerHTML = `
      <div data-eit-entry-field="repeatable" data-field-type="repeatable_group">
        <div data-eit-repeater-row>
          <input type="checkbox" data-eit-repeater-child="enabled" checked>
          <input type="number" data-eit-repeater-child="price" value="0">
        </div>
      </div>`;

    expect(readEntryValues(document.body)).toEqual({
      repeatable: [{ enabled: true, price: "0" }],
    });
  });

  it("does not add rows beyond the compiled maximum", () => {
    document.body.innerHTML = `
      <div data-eit-repeater data-eit-min-rows="0" data-eit-max-rows="1">
        <template data-eit-repeater-template>
          <div data-eit-repeater-row><input data-eit-repeater-child="name"></div>
        </template>
        <button type="button" data-eit-add-row>Add</button>
      </div>`;
    const repeater = document.querySelector("[data-eit-repeater]");
    const workspace = {
      dirty: false,
      syncRepeater: syncEntryRepeater,
    };

    EntryWorkspace.prototype.addRepeaterRow.call(workspace, repeater);
    EntryWorkspace.prototype.addRepeaterRow.call(workspace, repeater);

    expect(
      repeater.querySelectorAll(":scope > [data-eit-repeater-row]"),
    ).toHaveLength(1);
    expect(repeater.querySelector("[data-eit-add-row]").disabled).toBe(true);
  });
});
