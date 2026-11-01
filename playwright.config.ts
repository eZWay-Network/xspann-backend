import { defineConfig, devices } from "@playwright/test";
export default defineConfig({
  testDir: "tests/browser/staff",
  workers: 1,
  timeout: 30_000,
  use: {
    baseURL: process.env.XPN_STAFF_BASE_URL || "http://127.0.0.1:8765",
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  projects: [
    {
      name: "desktop-chromium",
      use: {
        ...devices["Desktop Chrome"],
        viewport: { width: 1440, height: 1050 },
      },
    },
    { name: "mobile-webkit", use: { ...devices["iPhone 13"] } },
  ],
});
