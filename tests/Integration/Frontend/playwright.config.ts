import { defineConfig, devices } from "@playwright/test";
import { tmpdir } from "node:os";
import { join } from "node:path";

// Shared with worker processes through the environment so tests can start a
// second backend on the same database.
process.env.NEURON_FIXTURE_DB ??= join(tmpdir(), `neuron-frontend-${process.pid}.sqlite`);
const fixtureDatabase = process.env.NEURON_FIXTURE_DB;

export default defineConfig({
  testDir: "specs",
  workers: 1,
  reporter: [["list"]],
  use: { trace: "retain-on-failure" },
  projects: [
    { name: "agui", testMatch: /agui\/.*\.spec\.ts/ },
    { name: "vercel", testMatch: /vercel\/.*\.spec\.ts/, use: { ...devices["Desktop Chrome"] } },
    { name: "copilotkit", testMatch: /copilotkit\/.*\.spec\.ts/, use: { ...devices["Desktop Chrome"] } },
  ],
  webServer: [
    {
      command: "php -S 127.0.0.1:8787 backend/router.php",
      url: "http://127.0.0.1:8787/_test/health",
      timeout: 60_000,
      env: { NEURON_FIXTURE_DB: fixtureDatabase },
      reuseExistingServer: false,
    },
    {
      command: "node fixtures/copilotkit/server.mjs",
      url: "http://127.0.0.1:4000/health",
      env: { COPILOTKIT_TELEMETRY_DISABLED: "true" },
      timeout: 120_000,
      reuseExistingServer: false,
    },
    {
      command: "npx vite --port 5173 --strictPort",
      url: "http://127.0.0.1:5173/vercel/",
      timeout: 240_000,
      reuseExistingServer: false,
    },
  ],
});
