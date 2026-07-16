import { expect, test } from "@playwright/test";
import { expectNoAxeViolations, login } from "./helpers.js";

test.describe.serial("Toolkit Collection surfaces", () => {
  test.afterEach(async ({ page }) => {
    const blueprintId = new URL(page.url()).searchParams.get("system");
    if (!blueprintId) return;
    await page.evaluate(async (id) => {
      await window.wp.apiFetch({
        path: `/eit/v1/blueprints/${id}`,
        method: "DELETE",
      });
    }, blueprintId);
  });

  test("Systems derives Collection and Filter Surface decisions from Field capabilities", async ({ page }) => {
    await login(page);
    await page.goto("/wp-admin/admin.php?page=eit-toolkit");
    await page.getByRole("button", { name: "Create system" }).click();
    const dialog = page.getByRole("dialog", { name: "Create an executable system" });
    await dialog.getByRole("textbox", { name: "System name" }).fill("E2E Collection decisions");
    await dialog.getByRole("button", { name: "Create system" }).click();
    await expect(page.getByRole("heading", { level: 2, name: "E2E Collection decisions" })).toBeVisible();
    const ids = await page.evaluate(() => {
      const state = window.wp.data.select("eit/systems").getState();
      const document = state.document;
      const entity = document.nodes.find((node) => "entity" === node.type);
      const group = document.nodes.find((node) => "field_group" === node.type);
      const field = group.config.fields[0];
      field.name = "Searchable title";
      field.exposure.public = true;
      field.indexing.filter = true;
      field.indexing.sort = true;
      field.capabilities.filter = true;
      field.capabilities.sort = true;
      const collectionId = window.crypto.randomUUID();
      const filterId = window.crypto.randomUUID();
      const collection = {
        id: collectionId,
        type: "collection",
        lane: "experience",
        name: "Public catalog",
        config: {
          page_size: 24,
          access: "authenticated",
          cache: { enabled: true, ttl_seconds: 300 },
          explain: true,
        },
        position: { x: 320, y: 0 },
      };
      const filters = {
        id: filterId,
        type: "filter_surface",
        lane: "experience",
        name: "Catalog filters",
        config: {
          fields: [field.id],
          facet_fields: [],
          url_state: true,
          active_chips: true,
        },
        position: { x: 320, y: 190 },
      };
      window.wp.data.dispatch("eit/systems").updateDocument({
        ...document,
        nodes: document.nodes.concat(collection, filters),
        connections: document.connections.concat(
          {
            id: window.crypto.randomUUID(),
            type: "collection_for",
            from: entity.id,
            to: collectionId,
          },
          {
            id: window.crypto.randomUUID(),
            type: "filters",
            from: collectionId,
            to: filterId,
          },
        ),
      });
      return { collectionId, filterId };
    });

    await expect(page.locator(".eit-system-node")).toHaveCount(4);
    await page.locator(`.react-flow__node[data-node-id="${ids.collectionId}"]`).click();
    const inspector = page.locator(".eit-system-inspector");
    await expect(inspector.getByRole("combobox", { name: "Audience" })).toHaveValue("authenticated");
    await expect(inspector.getByRole("spinbutton", { name: "Items per page" })).toHaveValue("24");
    await expect(inspector.getByRole("combobox", { name: "Default order" })).toContainText("Searchable title");
    await page.locator(`.react-flow__node[data-node-id="${ids.filterId}"]`).click();
    await expect(
      inspector.getByRole("group", { name: "Available filters" }).getByRole("checkbox", { name: "Searchable title" }),
    ).toBeChecked();
    await expect(inspector.getByRole("checkbox", { name: "Keep filter state in the URL" })).toBeChecked();
    await expectNoAxeViolations(page, ".eit-system-workspace");
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.locator(".eit-system-workspace").screenshot({
      path: "/tmp/eit-v080-collections-map.png",
    });
  });

  test("Elementor bridge renders compiled filters and facets without raw mapping", async ({ page }) => {
    await login(page);
    const firstQuery = page.waitForResponse(
      (response) =>
        response.url().includes("/wp-json/eit/v1/collections/") &&
        response.url().endsWith("/query") &&
        200 === response.status(),
    );
    await page.goto(process.env.EIT_E2E_COLLECTION_PATH);
    const response = await firstQuery;
    const payload = response.request().postDataJSON();
    expect(payload).not.toHaveProperty("provider");
    expect(payload).not.toHaveProperty("storage_key");
    expect(payload).not.toHaveProperty("targetSelector");

    const controller = page.locator(".eit-toolkit-filter-surface");
    const collection = page.locator(".eit-toolkit-collection");
    await expect(controller).toHaveAttribute("aria-busy", "false");
    await expect(controller.getByRole("combobox", { name: "Service tier" })).toBeVisible();
    await expect(collection.locator("[data-eit-collection-item]")).toHaveCount(2);
    await expect(controller.locator("[data-eit-result-count]")).toContainText("2 results");
    const filtered = page.waitForResponse(
      (candidate) =>
        candidate.url().includes("/wp-json/eit/v1/collections/") &&
        candidate.url().endsWith("/query") &&
        200 === candidate.status(),
    );
    await controller.getByRole("combobox", { name: "Service tier" }).selectOption("basic");
    await filtered;
    await expect(collection.locator("[data-eit-collection-item]")).toHaveCount(1);
    await expect(collection).toContainText("Basic listing");
    await expect(collection).not.toContainText("Premium listing");
    await expectNoAxeViolations(page, ".eit-filter-controller");
    await page.locator(".elementor-element-e2ecollectionwrap").screenshot({
      path: "/tmp/eit-v100rc-connector-surfaces.png",
    });
  });
});
