import AxeBuilder from "@axe-core/playwright";
import { expect } from "@playwright/test";

export async function login(page) {
  await page.goto("/wp-login.php");
  await page.locator("#user_login").fill(process.env.EIT_E2E_USER);
  await page.locator("#user_pass").fill(process.env.EIT_E2E_PASSWORD);
  await Promise.all([
    page.waitForURL(/\/wp-admin\//),
    page.locator("#wp-submit").click(),
  ]);
}

export async function expectNoAxeViolations(page, selector) {
  const results = await new AxeBuilder({ page }).include(selector).analyze();
  expect(results.violations).toEqual([]);
}

export async function dismissWordPressPointer(page) {
  await page
    .locator(".wp-pointer")
    .evaluateAll((pointers) => pointers.forEach((pointer) => pointer.remove()));
}
