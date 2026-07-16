import { runFixture } from "./global-setup.js";

export default async function globalTeardown() {
  const environment = {
    EIT_E2E_TOKEN: process.env.EIT_E2E_TOKEN,
    EIT_E2E_USER: process.env.EIT_E2E_USER,
    EIT_E2E_EMAIL: process.env.EIT_E2E_EMAIL,
    EIT_E2E_PASSWORD: process.env.EIT_E2E_PASSWORD,
  };

  if (Object.values(environment).every(Boolean)) {
    runFixture("cleanup", environment);
  }
}
