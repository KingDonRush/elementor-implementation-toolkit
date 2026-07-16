import { randomBytes } from "node:crypto";
import { spawnSync } from "node:child_process";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const repositoryRoot = resolve(
  dirname(fileURLToPath(import.meta.url)),
  "../..",
);
const studioRoot = resolve(repositoryRoot, "../../..");
const compose = [
  "compose",
  "-f",
  "wordpress/docker-compose.yml",
  "-f",
  "operations/wordpress/docker-compose.products.yml",
];

function runDocker(args, environment) {
  const result = spawnSync("docker", [...compose, ...args], {
    cwd: studioRoot,
    env: { ...process.env, ...environment },
    encoding: "utf8",
  });

  if (result.status !== 0) {
    throw new Error(
      `E2E fixture command failed: ${result.stderr || result.stdout}`,
    );
  }

  return result.stdout.trim();
}

export function runFixture(mode, environment) {
  return runDocker(
    [
      "run",
      "--rm",
      "-T",
      "-e",
      "EIT_E2E_MODE",
      "-e",
      "EIT_E2E_TOKEN",
      "-e",
      "EIT_E2E_USER",
      "-e",
      "EIT_E2E_EMAIL",
      "-e",
      "EIT_E2E_PASSWORD",
      "wpcli",
      "eval-file",
      "wp-content/plugins/elementor-implementation-toolkit/scripts/e2e-fixture.php",
    ],
    { ...environment, EIT_E2E_MODE: mode },
  );
}

export default async function globalSetup() {
  runDocker(["up", "-d", "db", "wordpress"], {});

  const token = randomBytes(6).toString("hex");
  const environment = {
    EIT_E2E_TOKEN: token,
    EIT_E2E_USER: `eit_e2e_${token}`,
    EIT_E2E_EMAIL: `eit-e2e-${token}@example.test`,
    EIT_E2E_PASSWORD: randomBytes(24).toString("base64url"),
  };
  const fixture = JSON.parse(
    runFixture("setup", environment).split("\n").at(-1),
  );

  process.env.EIT_E2E_USER = environment.EIT_E2E_USER;
  process.env.EIT_E2E_EMAIL = environment.EIT_E2E_EMAIL;
  process.env.EIT_E2E_PASSWORD = environment.EIT_E2E_PASSWORD;
  process.env.EIT_E2E_TOKEN = environment.EIT_E2E_TOKEN;
  process.env.EIT_E2E_FRONTEND_PATH = fixture.frontendPath;
  process.env.EIT_E2E_LEGACY_EDITOR_PATH = fixture.legacyEditorPath;
  process.env.EIT_E2E_CONNECTOR_EDITOR_PATH = fixture.connectorEditorPath;
  process.env.EIT_E2E_ENTRY_EDITOR_PATH = fixture.entryEditorPath;
  process.env.EIT_E2E_ENTRY_PATH = fixture.entryPath;
  process.env.EIT_E2E_COLLECTION_PATH = fixture.collectionPath;

}
