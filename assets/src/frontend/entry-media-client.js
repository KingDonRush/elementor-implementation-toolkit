const previewObjectUrls = new WeakMap();

function preparePreview(files) {
  const fragment = document.createDocumentFragment();
  const urls = [];
  [...files].forEach((file) => {
    const item = document.createElement("span");
    if (file.type.startsWith("image/")) {
      const url = URL.createObjectURL(file);
      urls.push(url);
      item.innerHTML = `<img src="${url}" alt=""><small></small>`;
    }
    item.querySelector("small")?.append(document.createTextNode(file.name));
    fragment.append(item);
  });
  return { fragment, urls };
}

function discardPreview(prepared) {
  prepared.urls.forEach((url) => URL.revokeObjectURL(url));
}

function commitPreview(preview, prepared) {
  (previewObjectUrls.get(preview) || []).forEach((url) =>
    URL.revokeObjectURL(url),
  );
  preview.replaceChildren(prepared.fragment);
  previewObjectUrls.set(preview, prepared.urls);
}

export async function uploadEntryMedia({
  input,
  root,
  contract,
  restUrl,
  nonceHeaders,
  signal,
}) {
  if (!input.files?.length) return null;
  const wrapper = input.closest("[data-eit-entry-field]");
  const preview = wrapper.querySelector("[data-eit-media-preview]");
  const preparedPreview = preparePreview(input.files);
  const uploaded = [];
  try {
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
        signal,
      });
      const result = await response.json();
      if (!response.ok) throw result;
      uploaded.push(result);
    }
  } catch (error) {
    discardPreview(preparedPreview);
    throw error;
  }
  commitPreview(preview, preparedPreview);
  return {
    wrapper,
    value: "gallery" === wrapper.dataset.fieldType ? uploaded : uploaded[0],
  };
}
