import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

async function login(page) {
  await page.goto('/wp-login.php');
  await page.locator('#user_login').fill(process.env.EIT_E2E_USER);
  await page.locator('#user_pass').fill(process.env.EIT_E2E_PASSWORD);
  await Promise.all([
    page.waitForURL(/\/wp-admin\//),
    page.locator('#wp-submit').click(),
  ]);
}

async function expectNoAxeViolations(page, selector) {
  const results = await new AxeBuilder({ page }).include(selector).analyze();
  expect(results.violations).toEqual([]);
}

test.describe.serial('Toolkit trust baseline', () => {
  test('wp-admin surface is factual and mechanically accessible', async ({ page }) => {
    await login(page);
    await page.goto('/wp-admin/admin.php?page=eit-toolkit');

    await expect(page.getByRole('heading', { level: 1, name: 'Implementation Toolkit' })).toBeVisible();
    await expect(page.getByText('WordPress enrichment')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Manage presets' })).toBeVisible();
    await expectNoAxeViolations(page, '.eit-admin');
  });

  test('frontend filter keeps explicit state and accessible controls', async ({ page }) => {
    await page.goto(process.env.EIT_E2E_FRONTEND_PATH);
    const controller = page.locator('.eit-filter-controller');
    const select = controller.getByRole('combobox', { name: 'Kind' });

    await expect(controller).toHaveAttribute('aria-busy', 'false');
    await select.selectOption('site');
    await expect(page.locator('[data-eit-client-id="alpha"]')).toBeHidden();
    await expect(page.locator('[data-eit-client-id="beta"]')).toBeVisible();
    await expect(controller.locator('[data-eit-result-count]')).toContainText('1 results');
    await expectNoAxeViolations(page, '.eit-filter-controller');
  });

  test('frontend failure preserves results and exposes the error', async ({ page }) => {
    await page.goto(process.env.EIT_E2E_FRONTEND_PATH);
    const controller = page.locator('.eit-filter-controller');
    await expect(controller).toHaveAttribute('aria-busy', 'false');

    await page.route('**/wp-json/eit/v1/filter', (route) => route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Intentional browser-test failure.' }),
    }));
    await controller.getByRole('combobox', { name: 'Kind' }).selectOption('plugin');

    const error = controller.locator('[data-eit-error]');
    await expect(error).toContainText('Intentional browser-test failure.');
    await expect(error).toBeFocused();
    await expect(page.locator('[data-eit-client-id="alpha"]')).toBeVisible();
    await expect(page.locator('[data-eit-client-id="beta"]')).toBeVisible();
  });

  test('Elementor editor loads the Toolkit integration without polling UI', async ({ page }) => {
    await login(page);
    await page.goto(process.env.EIT_E2E_EDITOR_PATH, { waitUntil: 'domcontentloaded' });
    await expect(page.locator('#elementor-panel')).toBeVisible({ timeout: 60_000 });
    await expect(page.locator('script[src*="eit-editor.js"]')).toHaveCount(1);

    const canvas = page.frameLocator('#elementor-preview-iframe');
    await expect(canvas.locator('.elementor-widget-eit-filter-controller')).toBeVisible({ timeout: 60_000 });
    await page.waitForFunction(() => window.elementor?.getContainer?.('e2efilter'));
    await page.evaluate(() => window.$e.run('document/elements/select', {
      container: window.elementor.getContainer('e2efilter'),
    }));
    await expect(page.locator('.eit-editor-targets')).toBeVisible();
    await expectNoAxeViolations(page, '.eit-editor-targets');
  });
});
