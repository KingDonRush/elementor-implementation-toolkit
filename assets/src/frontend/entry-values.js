function jsonValue(value, fallback) {
  try {
    return value ? JSON.parse(value) : fallback;
  } catch {
    return fallback;
  }
}

function fieldValue(wrapper) {
  const type = wrapper.dataset.fieldType;
  if ("boolean" === type) {
    return Boolean(wrapper.querySelector('input[type="checkbox"]')?.checked);
  }
  if ("multiple_choice" === type) {
    return [...wrapper.querySelectorAll('input[type="checkbox"]:checked')].map(
      (input) => input.value,
    );
  }
  if (["single_choice", "taxonomy", "relation"].includes(type)) {
    const select = wrapper.querySelector("select");
    if (!select) return [];
    return select.multiple
      ? [...select.selectedOptions].map((option) => option.value)
      : select.value;
  }
  if ("money" === type) {
    return {
      amount: wrapper.querySelector("[data-money-amount]")?.value || "",
      currency: wrapper.querySelector("[data-money-currency]")?.value || "USD",
    };
  }
  if (["image", "gallery", "file"].includes(type)) {
    return jsonValue(
      wrapper.querySelector("[data-eit-media-value]")?.value,
      "gallery" === type ? [] : null,
    );
  }
  if (["repeatable_group", "schedule"].includes(type)) {
    return [...wrapper.querySelectorAll("[data-eit-repeater-row]")]
      .filter((row) => !row.closest("template"))
      .map((row) =>
        Object.fromEntries(
          [...row.querySelectorAll("[data-eit-repeater-child]")].map(
            (input) => [input.dataset.eitRepeaterChild, input.value],
          ),
        ),
      );
  }
  if (["address", "geopoint", "availability"].includes(type)) {
    return Object.fromEntries(
      [...wrapper.querySelectorAll("[data-eit-object-key]")].map((input) => [
        input.dataset.eitObjectKey,
        input.value,
      ]),
    );
  }
  return (
    wrapper.querySelector('input:not([type="hidden"]), textarea, select')
      ?.value ?? ""
  );
}

export function readEntryValues(root) {
  const values = {};
  root.querySelectorAll("[data-eit-entry-field]").forEach((wrapper) => {
    if (wrapper.hidden || "calculated" === wrapper.dataset.fieldType) {
      return;
    }
    values[wrapper.dataset.eitEntryField] = fieldValue(wrapper);
  });
  return values;
}
