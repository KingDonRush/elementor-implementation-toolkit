import { expect, test } from "@playwright/test";
import { dismissWordPressPointer, expectNoAxeViolations, login } from "./helpers.js";

test.describe.serial("Toolkit trust baseline", () => {
  test.afterEach(async ({ page }) => {
    const blueprintId = new URL(page.url()).searchParams.get("system");
    if (!blueprintId) return;
    await page.evaluate(async (id) => {
      try {
        await window.wp.apiFetch({
          path: `/eit/v1/blueprints/${id}`,
          method: "DELETE",
        });
      } catch (error) {
        // Published fixtures intentionally cannot be deleted; this suite never publishes one.
      }
    }, blueprintId);
  });

  test("wp-admin Systems map is executable and mechanically accessible", async ({
    page,
  }) => {
    const runtimeErrors = [];
    page.on("pageerror", (error) => runtimeErrors.push(error.message));
    page.on("console", (message) => {
      if ("error" === message.type()) runtimeErrors.push(message.text());
    });
    await login(page);
    await page.goto("/wp-admin/admin.php?page=eit-toolkit");

    await expect(
      page.getByRole("heading", { level: 1, name: "Systems" }),
    ).toBeVisible();
    await expect(page.locator(".eit-native-tabs").getByRole("link")).toHaveText(
      ["Systems", "Runs", "Diagnostics", "Settings"],
    );
    await expect(
      page.locator('#adminmenu > li > a[href*="post_type=__imoveis"]'),
    ).toHaveCount(0);
    await expect(
      page.locator('#adminmenu > li > a[href*="page=eit-cct-items-"]'),
    ).toHaveCount(0);
    await expect(page.locator('script[src*="eit-systems.js"]')).toHaveCount(1);
    await expect(
      page.locator(
        'script[src*="/elementor-implementation-toolkit/assets/"], link[href*="/elementor-implementation-toolkit/assets/"]',
      ),
    ).toHaveCount(4);
    await expect(
      page.getByRole("button", { name: "Create system" }),
    ).toBeVisible();
    await expectNoAxeViolations(page, ".eit-admin");

    await page.getByRole("button", { name: "Create system" }).click();
    const dialog = page.getByRole("dialog", {
      name: "Create an executable system",
    });
    await dialog
      .getByRole("textbox", { name: "System name" })
      .fill("E2E executable system");
    await dialog.getByRole("button", { name: "Create system" }).click();

    await expect.poll(() => runtimeErrors).toEqual([]);
    await expect(
      page.getByRole("heading", { level: 2, name: "E2E executable system" }),
    ).toBeVisible();
    await expect(page.locator(".react-flow")).toBeVisible();
    await expect(page.locator(".eit-system-node")).toHaveCount(2);
    await expect(
      page.getByRole("heading", { level: 3, name: /What this node does/ }),
    ).toBeVisible();
    await expect(page.locator(".eit-system-recovery")).toHaveCount(0);
    await expectNoAxeViolations(page, ".eit-system-workspace");

    const publicContent = page.getByRole("checkbox", {
      name: "Public content",
    });
    await publicContent.click();
    await publicContent.click();
    await dismissWordPressPointer(page);
    await page
      .locator(".eit-system-breadcrumb")
      .getByRole("button", { name: "Systems" })
      .click();
    const discard = page.getByRole("dialog", {
      name: "Discard unsaved draft changes?",
    });
    await expect(discard).toBeVisible();
    await discard.getByRole("button", { name: "Keep editing" }).click();

    await page
      .locator(".eit-system-palette")
      .getByText("Data", { exact: true })
      .click();
    await page
      .locator(".eit-system-palette")
      .getByRole("button", { name: "Relation" })
      .click();
    await page.getByRole("button", { name: "Validate" }).click();
    await expect(page.getByText("Publication blocked")).toBeVisible();
    await expect(
      page
        .locator(".eit-system-inspector")
        .getByRole("heading", { level: 2, name: "Relation" }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Remove draft node" }).click();
    const remove = page.getByRole("dialog", {
      name: "Remove this draft node?",
    });
    await remove.getByRole("button", { name: "Remove draft node" }).click();

    await page.getByRole("tab", { name: "Outline" }).focus();
    await page.keyboard.press("Enter");
    await expect(page.getByLabel("System outline")).toBeVisible();
    await expect(
      page.getByRole("button", { name: /Content.*WordPress data definition/ }),
    ).toBeVisible();
    await page.getByRole("tab", { name: "Map" }).focus();
    await page.keyboard.press("Enter");

    await page.getByRole("button", { name: "Validate" }).click();
    await expect(page.getByText("Validation passed")).toBeVisible();
    await page.getByRole("button", { name: "Review impact" }).click();
    const impact = page.getByRole("dialog", { name: "Review compiler impact" });
    await expect(
      impact.getByText("This preview was produced by the server compiler"),
    ).toBeVisible();
    await expect(
      impact.getByRole("button", { name: "Publish version 1" }),
    ).toBeVisible();
    const affectedParts = impact.getByRole("list", {
      name: "Affected system parts",
    });
    await expect(affectedParts).toContainText("Content");
    await expect(affectedParts).toContainText("Content fields");
    await expect(
      affectedParts.getByText("Technical identity"),
    ).toHaveCount(2);
    await expect(affectedParts.locator("details[open]")).toHaveCount(0);
    await impact.getByRole("button", { name: "Cancel" }).click();

    expect(runtimeErrors).toEqual([]);
  });

  test("admin navigation keeps operations factual and Systems-only assets scoped", async ({
    page,
  }) => {
    await login(page);
    await dismissWordPressPointer(page);
    await page.goto("/wp-admin/admin.php?page=eit-runs");
    await expect(
      page.getByRole("heading", { level: 1, name: "Runs" }),
    ).toBeVisible();
    await expect(page.getByText("Execution history")).toBeVisible();
    await expect(page.locator('script[src*="eit-systems.js"]')).toHaveCount(0);

    await page
      .locator(".eit-native-tabs")
      .getByRole("link", { name: "Diagnostics" })
      .click();
    await expect(
      page.getByRole("heading", { level: 1, name: "Diagnostics" }),
    ).toBeVisible();
    await dismissWordPressPointer(page);
    await expect(
      page.getByRole("heading", { name: "Blueprint infrastructure" }),
    ).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Elementor runtime" }),
    ).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Legacy shadow migration" }),
    ).toBeVisible();
    await expect(page.getByText(/Pilot: __imoveis 1\/1 records; projects 6\/6 records/)).toBeVisible();
    await expect(page.locator("[data-eit-migration-source]:checked")).toHaveCount(0);
    await expect(
      page.getByRole("heading", { name: "QA Scenario Runner" }),
    ).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Factual handoff notes" }),
    ).toBeVisible();
    await expect(
      page.getByRole("textbox", { name: "Generated factual handoff notes" }),
    ).toHaveValue(/Human browser approval is external evidence/);
    await expect(page.locator('script[src*="eit-diagnostics.js"]')).toHaveCount(1);

    for (const [type, key] of [
      ["cpt", "__imoveis"],
      ["cct", "projects"],
      ["elementor_document", "301"],
      ["elementor_document", "340"],
      ["elementor_document", "479"],
    ]) {
      await page
        .locator(
          `[data-eit-migration-source][data-source-type="${type}"][data-source-key="${key}"]`,
        )
        .check();
    }
    await expect(page.locator("[data-eit-migration-source]:checked")).toHaveCount(5);
    await page.getByRole("button", { name: "Prepare selected drafts" }).click();
    const confirmation = page.locator("[data-eit-migration-confirm]");
    await expect(
      confirmation.getByRole("heading", { name: "Confirm shadow import" }),
    ).toBeVisible();
    await expect(confirmation).toContainText("5 draft candidates are ready");
    await expect(
      confirmation.getByRole("button", { name: "Create drafts and compare" }),
    ).toBeFocused();
    await expectNoAxeViolations(page, ".eit-admin");
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.locator(".eit-admin").screenshot({
      path: "/tmp/eit-v100rc-diagnostics.png",
      animations: "disabled",
    });
    await confirmation.getByRole("button", { name: "Cancel" }).click();
    await expect(
      page.getByRole("button", { name: "Prepare selected drafts" }),
    ).toBeFocused();

    await page
      .locator(".eit-native-tabs")
      .getByRole("link", { name: "Settings" })
      .click();
    await expect(
      page.getByRole("heading", { level: 1, name: "Settings" }),
    ).toBeVisible();
    await expect(
      page.getByRole("heading", { name: "Legacy recovery surfaces" }),
    ).toBeVisible();
    await expect(
      page.getByRole("link", { name: "Open legacy post types" }),
    ).toBeVisible();
    await expectNoAxeViolations(page, ".eit-admin");
  });

  test("frontend filter keeps explicit state and accessible controls", async ({
    page,
  }) => {
    await page.goto(process.env.EIT_E2E_FRONTEND_PATH);
    const controller = page.locator(".eit-filter-controller");
    const select = controller.getByRole("combobox", { name: "Kind" });

    await expect(controller).toHaveAttribute("aria-busy", "false");
    await select.selectOption("site");
    await expect(page.locator('[data-eit-client-id="alpha"]')).toBeHidden();
    await expect(page.locator('[data-eit-client-id="beta"]')).toBeVisible();
    await expect(controller.locator("[data-eit-result-count]")).toContainText(
	  "1 result",
    );
    await expectNoAxeViolations(page, ".eit-filter-controller");
  });

  test("frontend failure preserves results and exposes the error", async ({
    page,
  }) => {
    await page.goto(process.env.EIT_E2E_FRONTEND_PATH);
    const controller = page.locator(".eit-filter-controller");
    await expect(controller).toHaveAttribute("aria-busy", "false");

    await page.route("**/wp-json/eit/v1/filter", (route) =>
      route.fulfill({
        status: 500,
        contentType: "application/json",
        body: JSON.stringify({ message: "Intentional browser-test failure." }),
      }),
    );
    await controller
      .getByRole("combobox", { name: "Kind" })
      .selectOption("plugin");

    const error = controller.locator("[data-eit-error]");
    await expect(error).toContainText("Intentional browser-test failure.");
    await expect(error).toBeFocused();
    await expect(page.locator('[data-eit-client-id="alpha"]')).toBeVisible();
    await expect(page.locator('[data-eit-client-id="beta"]')).toBeVisible();
  });

  test("frontend Entry workspace supports governed multi-step authoring", async ({
    page,
  }) => {
    await login(page);
    await page.goto(process.env.EIT_E2E_ENTRY_PATH);
    const workspace = page.locator("[data-eit-entry-workspace]");
    await expect(
      workspace.getByRole("heading", { name: "Create a listing" }),
    ).toBeVisible();
    await expect(workspace).toHaveAttribute("aria-busy", "false");
    await workspace
      .getByRole("textbox", { name: "Listing title" })
      .fill("Browser listing");
    await workspace
      .getByRole("combobox", { name: "Service tier" })
      .selectOption("premium");
    await workspace.getByRole("button", { name: "Continue" }).focus();
    await page.keyboard.press("Enter");
    await expect(
      workspace.getByRole("heading", { name: "Delivery details" }),
    ).toBeFocused();
    await workspace.getByRole("spinbutton", { name: "Quantity" }).fill("0");
    await workspace
      .getByRole("textbox", { name: "Premium instructions" })
      .fill("Keep the conditional detail editable.");
    await workspace.getByRole("button", { name: "Add row" }).click();
    await workspace
      .getByRole("textbox", { name: "Row label" })
      .fill("First delivery row");

    const saved = page.waitForResponse(
      (response) =>
        response.url().includes("/wp-json/eit/v1/entry-submissions") &&
        200 === response.status(),
    );
    await workspace.getByRole("button", { name: "Save draft" }).click();
    await saved;
    await expect(workspace.locator("[data-eit-form-message]")).toContainText(
      "Your changes were saved.",
    );
    const saveChanges = workspace.getByRole("button", { name: "Save changes" });
    await expect(saveChanges).toBeFocused();
    await expect(workspace).not.toHaveAttribute("data-item-id", "0");
    await expectNoAxeViolations(page, ".eit-entry-workspace");
    await expect(saveChanges).toBeFocused();

    await page.setViewportSize({ width: 390, height: 844 });
    await expect
      .poll(() =>
        page.evaluate(
          () =>
            document.documentElement.scrollWidth <=
            document.documentElement.clientWidth,
        ),
      )
      .toBe(true);
    await expect(saveChanges).toBeFocused();
    await workspace.screenshot({
      path: "/tmp/eit-v070-entry-workspace.png",
      style: ".gp-skip-link { display: none !important; }",
    });

    await workspace.getByRole("button", { name: "Previous" }).click();
    await workspace
      .getByRole("textbox", { name: "Listing title" })
      .fill("Preserved browser title");
    await workspace.getByRole("button", { name: "Continue" }).click();
    const titleFieldId = await workspace
      .locator("[data-eit-entry-field]")
      .first()
      .getAttribute("data-eit-entry-field");
    await page.route("**/wp-json/eit/v1/entry-submissions", (route) =>
      route.fulfill({
        status: 422,
        contentType: "application/json",
        body: JSON.stringify({
          code: "eit_entry_validation_failed",
          message: "Intentional Entry failure.",
          data: {
            status: 422,
            fields: { [titleFieldId]: "Title needs review." },
          },
        }),
      }),
    );
    await workspace.getByRole("button", { name: "Save changes" }).click();
    await expect(workspace.locator("[data-eit-form-message]")).toContainText(
      "Intentional Entry failure.",
    );
    await expect(
      workspace.getByRole("heading", { name: "Identity" }),
    ).toBeVisible();
    await expect(
      workspace.getByRole("textbox", { name: "Listing title" }),
    ).toHaveValue("Preserved browser title");
  });

  test("Elementor editor loads the Toolkit integration without polling UI", async ({
    page,
  }) => {
    await login(page);
    await page.goto(process.env.EIT_E2E_EDITOR_PATH, {
      waitUntil: "domcontentloaded",
    });
    await expect(page.locator("#elementor-panel")).toBeVisible({
      timeout: 60_000,
    });
    await expect(page.locator('script[src*="eit-editor.js"]')).toHaveCount(1);

    const canvas = page.frameLocator("#elementor-preview-iframe");
    await expect(
      canvas.locator(".elementor-widget-eit-filter-controller"),
    ).toBeVisible({ timeout: 60_000 });
    await page.waitForFunction(() =>
      window.elementor?.getContainer?.("e2efilter"),
    );
    await page.evaluate(() =>
      window.$e.run("document/elements/select", {
        container: window.elementor.getContainer("e2efilter"),
      }),
    );
    await expect(page.locator(".eit-editor-targets")).toBeVisible();
    await expectNoAxeViolations(page, ".eit-editor-targets");
  });
});
