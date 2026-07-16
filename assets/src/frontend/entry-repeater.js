export function syncEntryRepeater(repeater) {
  if (!repeater) return;
  const rows = [
    ...repeater.querySelectorAll(":scope > [data-eit-repeater-row]"),
  ];
  const minimum = Math.max(0, Number(repeater.dataset.eitMinRows) || 0);
  const maximum = Math.max(1, Number(repeater.dataset.eitMaxRows) || 100);
  repeater
    .querySelector("[data-eit-add-row]")
    ?.toggleAttribute("disabled", rows.length >= maximum);
  rows.forEach((row) =>
    row
      .querySelector("[data-eit-remove-row]")
      ?.toggleAttribute("disabled", rows.length <= minimum),
  );
}

export function addEntryRepeaterRow(repeater) {
  const maximum = Math.max(
    1,
    Number(repeater?.dataset.eitMaxRows) || 100,
  );
  const rows =
    repeater?.querySelectorAll(":scope > [data-eit-repeater-row]") || [];
  if (!repeater || rows.length >= maximum) return false;
  const fragment = repeater
    .querySelector("[data-eit-repeater-template]")
    ?.content.cloneNode(true);
  if (!fragment) return false;
  repeater.insertBefore(
    fragment,
    repeater.querySelector("[data-eit-repeater-template]"),
  );
  syncEntryRepeater(repeater);
  [...repeater.querySelectorAll(":scope > [data-eit-repeater-row]")]
    .at(-1)
    ?.querySelector("input, select, textarea")
    ?.focus();
  return true;
}
