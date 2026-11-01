import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
const fixture = process.env.XPN_STAFF_FIXTURE || "/private/tmp/xpn-staff-step3-20261101";
const control = (action: string, role: string) => execFileSync("php", ["tests/Browser/staff/control.php", fixture, action, role], { encoding: "utf8" }).trim();
const seed = () => JSON.parse(execFileSync("php", ["tests/Browser/staff/finance-fixture.php", fixture], { encoding: "utf8" })) as { adjustment: number; webhook: number; invoice: number };
const worker = () => execFileSync("php", ["tests/Browser/staff/finance-fixture.php", fixture, "worker"], { encoding: "utf8" });
async function login(page: Page, role: string, path: string) {
  control("reset-replay", role);
  await page.goto(path);
  await page.getByLabel("Email address", { exact: true }).fill(`${role}@example.test`);
  await page.getByLabel("Password", { exact: true }).fill("StaffFixturePassword123!");
  await page.getByRole("button", { name: "Continue securely" }).click();
  await page.getByLabel("Authenticator code", { exact: true }).fill(control("code", role));
  await page.getByRole("button", { name: "Verify and continue" }).click();
}
test("finance allocates a reviewed adjustment and completes a failed receipt retry", async ({ page }, info) => {
  const records = seed();
  const errors: string[] = [];
  page.on("pageerror", error => errors.push(error.message));
  await login(page, "finance", `/billing/adjustments/${records.adjustment}`);
  await expect(page.getByRole("heading", { name: `Adjustment #${records.adjustment}`, exact: true })).toBeVisible();
  await page.getByRole("combobox", { name: "Allocation", exact: true }).click();
  await page.getByRole("option", { name: "no service change", exact: true }).click();
  await page.getByLabel("Review reason (at least 10 characters)").fill("Confirmed partial goodwill refund with no service change.");
  await page.getByRole("button", { name: "Confirm allocation", exact: true }).click();
  await expect(page.getByText("Adjustment allocation recorded.")).toBeVisible();
  await page.screenshot({ path: info.outputPath("adjustment.png"), fullPage: true });
  await page.goto(`/billing/webhooks/${records.webhook}`);
  await page.getByLabel("Reason", { exact: true }).fill("Provider incident resolved and receipt reviewed.");
  await page.getByRole("button", { name: "Queue recovery" }).click();
  await expect(page).toHaveURL(/\/operations\/actions\/\d+/);
  worker();
  await page.getByRole("button", { name: "Refresh outcome" }).click();
  await expect(page.getByText("receipt_processed", { exact: true })).toBeVisible();
  expect(errors).toEqual([]);
});
test("finance report exports through the private download route", async ({ page }, info) => {
  seed();
  await login(page, "finance", "/billing/reports");
  await expect(page.getByRole("heading", { name: "Finance reports", exact: true })).toBeVisible();
  await page.getByRole("combobox", { name: "Reporting period" }).click();
  await page.getByRole("option", { name: "Last 7 days" }).click();
  await expect(page).toHaveURL(/period=7/);
  await page.screenshot({ path: info.outputPath("finance-reports.png"), fullPage: true });
  await page.getByRole("button", { name: "Export report" }).click();
  await expect(page).toHaveURL(/\/exports\/\d+/);
  worker();
  await page.getByRole("button", { name: "Refresh export" }).click();
  await expect(page.getByRole("link", { name: "Download CSV" })).toBeVisible();
  const download = page.waitForEvent("download");
  await page.getByRole("link", { name: "Download CSV" }).click();
  expect((await download).suggestedFilename()).toMatch(/^xpn-report-\d+\.csv$/);
});
test("operations and controlled settings fit the shared responsive interface", async ({ page }, info) => {
  const errors: string[] = [];
  page.on("pageerror", error => errors.push(error.message));
  await login(page, "admin", "/operations/overview");
  await expect(page.getByRole("heading", { name: "Operations overview", exact: true })).toBeVisible();
  await page.screenshot({ path: info.outputPath("operations.png"), fullPage: true });
  for (const path of ["/operations/jobs", "/operations/media", "/operations/communications", "/operations/audit", "/releases", "/settings/catalog", "/settings/policies", "/settings/integrations", "/settings/roles", "/exports"]) {
    await page.goto(path);
    await expect(page.getByRole("main")).toBeVisible();
    await expect(page.getByRole("heading", { name: "Something went wrong" })).toHaveCount(0);
    if (path === "/operations/communications") {
      await expect(page.getByRole("heading", { name: "Announcement delivery", exact: true })).toBeVisible();
      await expect(page.getByText(/pending recipients · Oldest acceptance:/)).toBeVisible();
      await page.screenshot({ path: info.outputPath("communications.png"), fullPage: true });
    }
  }
  await page.goto("/settings/taxonomy");
  await page.getByRole("button", { name: "Create genre" }).click();
  const tag = `${info.project.name}-${Date.now()}`;
  await page.getByLabel("Name", { exact: true }).fill(`Fixture ${tag}`);
  await page.getByLabel("Slug", { exact: true }).fill(tag);
  await page.getByRole("button", { name: "Save", exact: true }).click();
  await expect(page.getByRole("dialog", { name: "Genre details" })).toHaveCount(0);
  await expect(page.getByText(`Fixture ${tag}`, { exact: true })).toBeVisible();
  await page.screenshot({ path: info.outputPath("taxonomy.png"), fullPage: true });
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  expect(errors).toEqual([]);
});
