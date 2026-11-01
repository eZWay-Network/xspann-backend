import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
import { createHmac } from "node:crypto";
const fixture =
  process.env.XPN_STAFF_FIXTURE || "/private/tmp/xpn-staff-browser";
const control = (action: string, user = "admin") =>
  execFileSync(
    "php",
    ["tests/browser/staff/control.php", fixture, action, user],
    { encoding: "utf8" },
  ).trim();
function totp(secret: string) {
  const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  const bits = [...secret]
    .map((char) => alphabet.indexOf(char).toString(2).padStart(5, "0"))
    .join("");
  const key = Buffer.from(
    bits.match(/.{8}/g)!.map((byte) => parseInt(byte, 2)),
  );
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const digest = createHmac("sha1", key).update(counter).digest();
  return String(
    (digest.readUInt32BE(digest[19] & 15) & 0x7fffffff) % 1000000,
  ).padStart(6, "0");
}
async function login(
  page: Page,
  user = "admin",
  startPath = "/login",
  heading = "Workspace overview",
) {
  control("reset-replay", user);
  await page.goto(startPath);
  await page
    .getByLabel("Email address", { exact: true })
    .fill(`${user}@example.test`);
  await page
    .getByLabel("Password", { exact: true })
    .fill("StaffFixturePassword123!");
  await page.getByRole("button", { name: "Continue securely" }).click();
  await expect(
    page.getByRole("heading", { name: "Verify it’s you" }),
  ).toBeVisible();
  await page
    .getByLabel("Authenticator code", { exact: true })
    .fill(control("code", user));
  await page.getByRole("button", { name: "Verify and continue" }).click();
  await expect(page.getByRole("heading", { name: heading })).toBeVisible();
}

test("cookie login, overview, keyboard search, date controls and responsive shell", async ({
  page,
}, testInfo) => {
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  const initial = await page.goto("/");
  await expect(page).toHaveURL(/\/login$/);
  expect(initial?.headers()["cache-control"]).toContain("no-store");
  await expect(
    page.getByRole("heading", { name: "Welcome back" }),
  ).toBeVisible();
  await page.screenshot({
    path: testInfo.outputPath("login.png"),
    fullPage: true,
  });
  const before = (await page.context().cookies()).find(
    (cookie) => cookie.name === "spark_session",
  );
  expect(before?.httpOnly).toBeTruthy();
  expect(before?.sameSite).toBe("Lax");
  await login(page);
  const after = (await page.context().cookies()).find(
    (cookie) => cookie.name === "spark_session",
  );
  expect(after?.value).not.toBe(before?.value);
  await page.getByRole("combobox", { name: "Reporting period" }).click();
  await page.getByRole("option", { name: "Last 30 days" }).click();
  await expect(page).toHaveURL(/period=30/);
  await expect(
    page.getByText("Retained registrations", { exact: true }),
  ).toBeVisible();
  await page.screenshot({
    path: testInfo.outputPath("overview.png"),
    fullPage: true,
  });
  await page.getByRole("button", { name: "View data" }).click();
  await expect(
    page.getByRole("columnheader", { name: "UTC date" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Show chart" }).click();
  await page.getByRole("button", { name: /–/ }).click();
  await expect(page.getByText("Reporting dates · UTC")).toBeVisible();
  await page.keyboard.press("Escape");
  await page.getByRole("button", { name: "Search navigation" }).click();
  await page.getByRole("textbox", { name: "Search pages" }).fill("security");
  await page.keyboard.press("ArrowDown");
  await page.keyboard.press("Enter");
  await expect(
    page.getByRole("heading", { name: "Active sessions" }),
  ).toBeVisible();
  if (testInfo.project.name.includes("mobile")) {
    await page.getByRole("button", { name: "Open navigation" }).click();
    await expect(
      page.getByRole("dialog", { name: "Navigation" }),
    ).toBeVisible();
    await page.screenshot({
      path: testInfo.outputPath("mobile-navigation.png"),
    });
    await page.keyboard.press("Escape");
  }
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= window.innerWidth,
    ),
  ).toBeTruthy();
  expect(errors).toEqual([]);
  await page.getByRole("button", { name: "Account menu" }).click();
  await page.getByRole("menuitem", { name: "Sign out" }).click();
  await expect(
    page.getByRole("heading", { name: "Welcome back" }),
  ).toBeVisible();
});

test("profile preferences persist and expired MFA blocks page props", async ({
  page,
}) => {
  await login(page, "moderator");
  await page.goto("/settings/profile");
  await page.getByLabel("Display name", { exact: true }).fill("Jamie Rivera");
  await page.getByRole("button", { name: "Save profile" }).click();
  await expect(
    page.getByText("Your display name has been saved.", { exact: true }),
  ).toBeVisible();
  await page.getByRole("combobox", { name: "Rows per page" }).click();
  await page.getByRole("option", { name: "10 rows" }).click();
  await page.getByRole("button", { name: "Save preferences" }).click();
  await expect(
    page.getByText("Your workspace preferences have been saved.", {
      exact: true,
    }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("combobox", { name: "Rows per page" }),
  ).toContainText("10 rows");
  control("expire-mfa", "moderator");
  await page.goto("/");
  await expect(
    page.getByRole("heading", { name: "Verify it’s you" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Workspace overview" }),
  ).toHaveCount(0);
  await page.getByRole("button", { name: "Cancel and sign out" }).click();
  await expect(
    page.getByRole("heading", { name: "Welcome back" }),
  ).toBeVisible();
});

test("team invitation enrollment and role revocation", async ({
  page,
  browser,
}, testInfo) => {
  if (testInfo.project.name !== "desktop-chromium") test.skip();
  control("reset-invitee", "invitee");
  await login(page);
  await page.goto("/settings/team");
  await page.getByRole("button", { name: "Invite staff member" }).click();
  await page.getByLabel("Existing account email").fill("invitee@example.test");
  await page
    .getByLabel("Reason for access")
    .fill("Help the community support team");
  await page.getByRole("button", { name: "Create invitation" }).click();
  await expect(
    page.getByRole("dialog", { name: "Invite a staff member" }),
  ).toHaveCount(0);
  const link = await page.locator("code").textContent();
  expect(link).toContain("/enroll/");
  const other = await browser.newContext();
  const invited = await other.newPage();
  await invited.goto(link!);
  await invited
    .getByLabel("Invited email address")
    .fill("invitee@example.test");
  await invited
    .getByLabel("Password", { exact: true })
    .fill("StaffFixturePassword123!");
  await invited.getByRole("button", { name: "Verify account" }).click();
  await expect(invited.locator("code")).toBeVisible();
  const secret = await invited.locator("code").textContent();
  await invited.getByLabel("New authenticator code").fill(totp(secret!));
  await invited.getByRole("button", { name: "Activate staff access" }).click();
  await expect(
    invited.getByRole("heading", { name: "Workspace overview" }),
  ).toBeVisible();
  await page.reload();
  await page.getByRole("button", { name: "Manage Taylor Brooks" }).click();
  await page.getByRole("combobox", { name: "Action", exact: true }).click();
  await page.getByRole("option", { name: "Disable staff access" }).click();
  await page
    .getByLabel("Reason", { exact: true })
    .fill("End temporary support access");
  await page.getByRole("button", { name: "Apply access change" }).click();
  await expect(
    page.getByRole("dialog", { name: "Manage Taylor Brooks" }),
  ).toHaveCount(0);
  await invited.goto(new URL("/", link!).href);
  await expect(
    invited.getByRole("heading", { name: "Welcome back" }),
  ).toBeVisible();
  await other.close();
});

test("protected root-level deep link survives sign-in", async ({ page }) => {
  await login(page, "analyst", "/settings/security", "Active sessions");
  await expect(page).toHaveURL(/\/settings\/security$/);
  await page.getByRole("button", { name: "Account menu" }).click();
  await page.getByRole("menuitem", { name: "Sign out" }).click();
  await expect(page).toHaveURL(/\/login$/);
});
