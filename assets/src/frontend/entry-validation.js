function fieldControls(wrapper) {
  return [
    ...wrapper.querySelectorAll("input:not([type='hidden']), textarea, select"),
  ];
}

export function syncChoiceGroup(wrapper, required, message) {
  const fieldset = wrapper.querySelector("[data-eit-choice-group]");
  const choices = fieldControls(wrapper).filter(
    (control) => "checkbox" === control.type,
  );
  if (!fieldset || !choices.length) return;

  const missing = required && !choices.some((choice) => choice.checked);
  fieldset.setAttribute("aria-required", String(required));
  choices[0].setCustomValidity(missing ? message : "");
}

export function setEntryFieldInvalid(wrapper, message = "") {
  wrapper.classList.add("has-error");
  fieldControls(wrapper).forEach((control) =>
    control.setAttribute("aria-invalid", "true"),
  );
  wrapper
    .querySelector("[data-eit-choice-group]")
    ?.setAttribute("aria-invalid", "true");
  const error = wrapper.querySelector("[data-eit-field-error]");
  if (error) error.textContent = message;
}

export function clearEntryFieldInvalid(wrapper) {
  wrapper.classList.remove("has-error");
  fieldControls(wrapper).forEach((control) =>
    control.setAttribute("aria-invalid", "false"),
  );
  wrapper
    .querySelector("[data-eit-choice-group]")
    ?.setAttribute("aria-invalid", "false");
  const error = wrapper.querySelector("[data-eit-field-error]");
  if (error) error.textContent = "";
}

export function exposeClientValidity(scope) {
  let firstInvalid = null;
  const wrappers = scope.matches?.("[data-eit-entry-field]")
    ? [scope]
    : [...scope.querySelectorAll("[data-eit-entry-field]")];
  wrappers.forEach((wrapper) => {
    if (wrapper.hidden) return;
    const invalid = fieldControls(wrapper).find(
      (control) => !control.disabled && !control.validity.valid,
    );
    if (invalid) {
      setEntryFieldInvalid(wrapper, invalid.validationMessage);
      firstInvalid ||= invalid;
    } else if (wrapper.classList.contains("has-error")) {
      clearEntryFieldInvalid(wrapper);
    }
  });
  return firstInvalid;
}
