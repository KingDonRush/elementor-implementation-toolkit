import AxeBuilder from "@axe-core/playwright";
import { expect } from "@playwright/test";

export async function login(page) {
  const credentials = {
    username: process.env.EIT_E2E_USER,
    password: process.env.EIT_E2E_PASSWORD,
  };
  if (!credentials.username || !credentials.password) {
    throw new Error("E2E login credentials are unavailable.");
  }
  await page.goto("/wp-login.php");
  await Promise.all([
    page.waitForURL(/\/wp-admin\//),
    page.locator("#loginform").evaluate((form, values) => {
      form.elements.log.value = values.username;
      form.elements.pwd.value = values.password;
      form.requestSubmit(form.querySelector("#wp-submit"));
    }, credentials),
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
