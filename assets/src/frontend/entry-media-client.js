function previewFiles(preview, files) {
  preview.innerHTML = "";
  [...files].forEach((file) => {
    const item = document.createElement("span");
    if (file.type.startsWith("image/")) {
      item.innerHTML = `<img src="${URL.createObjectURL(file)}" alt=""><small></small>`;
    }
    item.querySelector("small")?.append(document.createTextNode(file.name));
    preview.append(item);
  });
}

export async function uploadEntryMedia({
  input,
  root,
  contract,
  restUrl,
  nonceHeaders,
}) {
  if (!input.files?.length) return null;
  const wrapper = input.closest("[data-eit-entry-field]");
  previewFiles(wrapper.querySelector("[data-eit-media-preview]"), input.files);
  const uploaded = [];
  for (const file of input.files) {
    const data = new FormData();
    data.append("file", file);
    data.append("surface_id", contract.surface_id);
    data.append("field_id", wrapper.dataset.eitEntryField);
    data.append("item_id", root.dataset.itemId || "0");
    data.append(
      "form_token",
      root.querySelector("[data-eit-form-token]")?.value || "",
    );
    data.append(
      "company_website",
      root.querySelector("[data-eit-honeypot]")?.value || "",
    );
    const response = await fetch(`${restUrl}/entry-media`, {
      method: "POST",
      credentials: "same-origin",
      headers: nonceHeaders,
      body: data,
    });
    const result = await response.json();
    if (!response.ok) throw result;
    uploaded.push(result);
  }
  return {
    wrapper,
    value: "gallery" === wrapper.dataset.fieldType ? uploaded : uploaded[0],
  };
}
