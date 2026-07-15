export function uniqueEntryKey() {
  if (window.crypto?.randomUUID) {
    return `entry:${window.crypto.randomUUID()}`;
  }
  return `entry:${Date.now()}:${Math.random().toString(36).slice(2)}`;
}

export function setEntryButtonsBusy(root, busy, focusedButton) {
  root.querySelectorAll("button").forEach((button) => {
    const keepsFocus = busy && button === focusedButton;
    button.disabled = busy && !keepsFocus;
    if (keepsFocus) {
      button.setAttribute("aria-disabled", "true");
    } else {
      button.removeAttribute("aria-disabled");
    }
  });
}
