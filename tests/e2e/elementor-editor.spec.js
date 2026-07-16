import { expect, test } from "@playwright/test";
import { expectNoAxeViolations, login } from "./helpers.js";

test("Elementor editor exposes contract connectors without raw mappings", async ({
  page,
}) => {
  await login(page);
  await page.goto(process.env.EIT_E2E_CONNECTOR_EDITOR_PATH, {
    waitUntil: "domcontentloaded",
  });
  await expect(page.locator("#elementor-panel")).toBeVisible({
    timeout: 60_000,
  });
  await expect(page.locator('script[src*="eit-editor.js"]')).toHaveCount(1);

  const canvas = page.frameLocator("#elementor-preview-iframe");
  await expect(
    canvas.locator(".elementor-widget-eit-toolkit-filter-surface"),
  ).toBeVisible({ timeout: 60_000 });
  await expect(
    canvas.locator(".elementor-widget-eit-toolkit-collection-surface"),
  ).toBeVisible({ timeout: 60_000 });
  await page.waitForFunction(() =>
    window.elementor?.getContainer?.("e2etoolkitfilter"),
  );
  await page.evaluate(() =>
    window.$e.run("document/elements/select", {
      container: window.elementor.getContainer("e2etoolkitfilter"),
    }),
  );
  await expect(page.locator(".elementor-control-collection_id select")).not.toHaveValue("");
  await expect(page.locator(".elementor-control-target_selector")).toHaveCount(0);
  await expect(page.locator(".elementor-control-item_selector")).toHaveCount(0);
  await expectNoAxeViolations(page, ".elementor-control-collection_id");
  await page.screenshot({
    path: "/tmp/eit-v100rc-connector-editor.png",
    animations: "disabled",
  });

  await page.goto(process.env.EIT_E2E_LEGACY_EDITOR_PATH, {
    waitUntil: "domcontentloaded",
  });
  await expect(page.locator("#elementor-panel")).toBeVisible({
    timeout: 60_000,
  });
  const legacyCanvas = page.frameLocator("#elementor-preview-iframe");
  await expect(
    legacyCanvas.locator(".elementor-widget-eit-filter-controller"),
  ).toBeVisible({ timeout: 60_000 });
  await page.waitForFunction(() => window.elementor?.getContainer?.("e2efilter"));
  await page.evaluate(() =>
    window.$e.run("document/elements/select", {
      container: window.elementor.getContainer("e2efilter"),
    }),
  );
  await expect(page.locator(".eit-editor-targets")).toBeVisible();
  await expectNoAxeViolations(page, ".eit-editor-targets");
});
