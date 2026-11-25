import { expect, test, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";

const fixture =
  process.env.XPN_STAFF_FIXTURE || "/private/tmp/xpn-staff-browser";
const control = (action: string, user = "admin") =>
  execFileSync(
    "php",
    ["tests/browser/staff/control.php", fixture, action, user],
    { encoding: "utf8" },
  );
const seed = () =>
  JSON.parse(
    execFileSync(
      "php",
      ["tests/browser/staff/community-fixture.php", fixture],
      { encoding: "utf8" },
    ),
  ) as { user: number; video: number; playlist: number; report: number };

async function login(page: Page, role = "admin") {
  control("reset-login", role);
  await page.goto("/login");
  await page
    .getByLabel("Email address", { exact: true })
    .fill(`${role}@example.test`);
  await page
    .getByLabel("Password", { exact: true })
    .fill("StaffFixturePassword123!");
  await page.getByRole("button", { name: "Continue securely" }).click();
  await expect(
    page.getByRole("heading", { name: "Dashboard", exact: true }),
  ).toBeVisible();
  await expect(page.getByLabel(/authenticator/i)).toHaveCount(0);
}

async function action(page: Page, name: string, reason: string) {
  await page.getByRole("combobox", { name: "Action", exact: true }).click();
  await page.getByRole("option", { name, exact: true }).click();
  await page
    .getByRole("combobox", { name: "Reason code", exact: true })
    .click();
  await page.getByRole("option", { name: "Safety", exact: true }).click();
  await page.getByLabel("Admin explanation", { exact: true }).fill(reason);
  await page
    .getByRole("button", { name: "Review action", exact: true })
    .click();
  await expect(
    page.getByRole("dialog", { name: "Confirm moderation action" }),
  ).toContainText(reason);
  await page
    .getByRole("button", { name: "Confirm action", exact: true })
    .click();
  await expect(
    page.getByRole("dialog", { name: "Confirm moderation action" }),
  ).toHaveCount(0);
  await expect(page.getByRole("alert")).toHaveCount(0);
}

async function restore(page: Page, prefix: string, reason: string) {
  await page.getByRole("combobox", { name: "Action", exact: true }).click();
  const name = await page
    .getByRole("option", { name: new RegExp(`^${prefix} · decision #`) })
    .first()
    .innerText();
  await page.keyboard.press("Escape");
  await action(page, name, reason);
}

test("password login, grouped navigation and direct content removal", async ({
  page,
  isMobile,
}) => {
  const record = seed();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await login(page);
  if (isMobile)
    await page.getByRole("button", { name: "Open navigation" }).click();
  const nav = page.getByRole("navigation", { name: "Main navigation" });
  for (const name of ["Dashboard", "Analytics", "Attention"]) {
    await expect(nav.getByRole("link", { name, exact: true })).toBeVisible();
  }
  for (const name of [
    "Community",
    "Content",
    "Moderation",
    "Promotions",
    "Billing",
    "Operations",
  ]) {
    await expect(nav.getByRole("button", { name, exact: true })).toBeVisible();
  }
  await expect(
    nav.getByRole("button", { name: "Advanced", exact: true }),
  ).toHaveCount(0);
  await expect(
    nav.getByRole("link", { name: "Admin roles", exact: true }),
  ).toHaveCount(0);
  if (isMobile) await page.keyboard.press("Escape");
  await page.goto(`/content/videos/${record.video}`);
  await action(
    page,
    "Remove / unpublish",
    "Browser-reviewed content policy violation.",
  );
  await expect(page.getByText("removed", { exact: true })).toBeVisible();
  await restore(page, "Restore", "Corrected content is safe to restore.");
  await page.goto(
    `/operations/audit?action=remove&target_type=video&target_id=${record.video}`,
  );
  await expect(
    page.getByText("moderation.decided · remove", { exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth + 1,
    ),
  ).toBe(true);
  expect(errors).toEqual([]);
});

test("ban, soft delete and independent restorations", async ({ page }) => {
  const record = seed();
  await login(page);
  await page.goto(`/users/${record.user}`);
  await action(page, "Permanently ban", "Repeated account policy violations.");
  await expect(page.getByText("banned", { exact: true })).toBeVisible();
  await action(
    page,
    "Soft-delete account",
    "Administrative removal with retained evidence.",
  );
  await expect(page.getByText("deleted", { exact: true })).toBeVisible();
  await restore(page, "Restore", "Restore the retained account for review.");
  await expect(page.getByText("banned", { exact: true })).toBeVisible();
  await restore(page, "Unban", "Independent concerns have been resolved.");
  await expect(page.getByText("active", { exact: true })).toBeVisible();
});

test("moderator can warn but cannot ban or delete accounts", async ({
  page,
}) => {
  const record = seed();
  await login(page, "moderator");
  await page.goto(`/users/${record.user}`);
  await page.getByRole("combobox", { name: "Action", exact: true }).click();
  await expect(
    page.getByRole("option", { name: "Warn", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("option", { name: "Permanently ban", exact: true }),
  ).toHaveCount(0);
  await expect(
    page.getByRole("option", { name: "Soft-delete account", exact: true }),
  ).toHaveCount(0);
  await page.keyboard.press("Escape");
  await action(page, "Warn", "Please follow the community content rules.");
  await expect(page.getByText("active", { exact: true })).toBeVisible();
});

test("senior moderator resolves an independent appeal", async ({ page }) => {
  const record = JSON.parse(
    execFileSync(
      "php",
      ["tests/browser/staff/community-fixture.php", fixture, "appeal"],
      { encoding: "utf8" },
    ),
  ) as { appeal: number; video: number };
  await login(page, "senior_moderator");
  await page.goto(`/moderation/appeals/${record.appeal}`);
  await expect(
    page.getByText("I have permission to publish this work."),
  ).toBeVisible();
  await page.getByRole("combobox", { name: "Decision", exact: true }).click();
  await page.getByRole("option", { name: "overturn", exact: true }).click();
  await page
    .getByRole("combobox", { name: "Reason code", exact: true })
    .click();
  await page.getByRole("option", { name: "Rights", exact: true }).click();
  await page
    .getByLabel("Reason", { exact: true })
    .fill("Verified the owner's permission independently.");
  await page
    .getByRole("button", { name: "Confirm decision", exact: true })
    .click();
  await expect(page.getByText("overturned", { exact: true })).toBeVisible();
  await page.goto(`/content/videos/${record.video}`);
  await expect(page.getByText("Clear", { exact: true })).toBeVisible();
});
