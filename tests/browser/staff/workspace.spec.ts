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
  password = "StaffFixturePassword123!",
) {
  control("reset-replay", user);
  await page.goto(startPath);
  await page
    .getByLabel("Email address", { exact: true })
    .fill(`${user}@example.test`);
  await page.getByLabel("Password", { exact: true }).fill(password);
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

async function expectNoOverflow(page: Page) {
  expect(
    await page.evaluate(() =>
      [
        document.documentElement,
        ...document.querySelectorAll(
          'main, nav[aria-label="Settings navigation"]',
        ),
      ].every((node) => node.scrollWidth <= node.clientWidth + 1),
    ),
  ).toBe(true);
}

async function openRail(page: Page) {
  await expect(page.getByRole("main")).toBeVisible();
  const toggle = page.getByRole("button", { name: "Open navigation" });
  if (await toggle.isVisible()) await toggle.click();
  return page.getByRole("navigation", { name: "Main navigation" });
}

test("Audio drafts, record ownership, manual collapse and cursor navigation", async ({
  page,
  isMobile,
}) => {
  control("clear-audio");
  await login(page, "admin", "/content/audios", "No audio yet");
  await expect(page.getByRole("button", { name: "Next" })).toHaveCount(0);
  const record = JSON.parse(control("seed-audio")) as {
    id: number;
    owner: number;
    title: string;
  };
  await page.reload();
  const nav = await openRail(page);
  const content = nav.getByRole("button", { name: "Content", exact: true });
  await expect(content).toHaveAttribute("aria-expanded", "true");
  for (const key of ["Enter", "Space"]) {
    await content.press(key);
    await expect(content).toHaveAttribute("aria-expanded", "false");
    await content.press(key);
    await expect(content).toHaveAttribute("aria-expanded", "true");
  }
  await content.click();
  if (isMobile) await page.keyboard.press("Escape");
  const search = page.getByRole("searchbox");
  const owner = page.getByRole("textbox", { name: "Owner ID" });
  await search.fill("Midnight");
  await owner.fill(String(record.owner));
  await page.getByRole("combobox", { name: "Status", exact: true }).click();
  await page.getByRole("option", { name: "Ready", exact: true }).click();
  await expect(page).toHaveURL(/\/content\/audios$/);
  await expect(search).toHaveValue("Midnight");
  await owner.press("Enter");
  await expect(page).toHaveURL(/status=ready/);
  const committed = new URL(page.url()).searchParams;
  expect(committed.get("owner_id")).toBe(String(record.owner));
  expect(committed.get("q")).toBe("Midnight");
  await openRail(page);
  await expect(content).toHaveAttribute("aria-expanded", "false");
  if (isMobile) await page.keyboard.press("Escape");
  await page.reload();
  await expect(owner).toHaveValue(String(record.owner));
  await expect(search).toHaveValue("Midnight");
  await page.getByRole("link", { name: "Open " + record.title }).click();
  await expect(
    page.getByRole("heading", { name: "Audio #" + record.id, exact: true }),
  ).toBeVisible();
  await page.reload();
  await openRail(page);
  await expect(content).toHaveAttribute("aria-expanded", "true");
  await expect(
    nav.getByRole("link", { name: "Audio", exact: true }),
  ).toHaveAttribute("aria-current", "location");
  if (isMobile) await page.keyboard.press("Escape");
  await page.goBack();
  await expect(search).toHaveValue("Midnight");
  await search.fill("No such recording");
  await page.getByRole("button", { name: "Apply filters" }).click();
  await expect(
    page.getByRole("heading", { name: "No matching audio" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Clear filters" }).click();
  await expect(page).toHaveURL(/\/content\/audios$/);
  await expect(search).toHaveValue("");
  await expect(owner).toHaveValue("");
  await search.fill("Unsaved");
  await page.getByRole("button", { name: "Reset", exact: true }).click();
  await expect(search).toHaveValue("");

  await page.goto("/users?per_page=1");
  await search.fill("Artist");
  await search.press("Enter");
  await expect(page).toHaveURL(/q=Artist/);
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page).toHaveURL(/page=2/);
  expect(new URL(page.url()).searchParams.get("q")).toBe("Artist");

  await page.goto("/operations/audit?per_page=1");
  await search.fill("staff.challenge_completed");
  await page.getByRole("combobox", { name: "Actor type" }).click();
  await page.getByRole("option", { name: "Staff", exact: true }).click();
  await page.getByRole("button", { name: "Apply filters" }).click();
  await expect(page).toHaveURL(/actor_type=staff/);
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page).toHaveURL(/cursor=/);
  expect(new URL(page.url()).searchParams.get("q")).toBe(
    "staff.challenge_completed",
  );
  await page.getByRole("button", { name: "Previous", exact: true }).click();
  await page.getByRole("button", { name: "Reset", exact: true }).click();
  await expect(page).toHaveURL(/\/operations\/audit$/);
});

test("Settings sections fit, retain their owner and stay searchable", async ({
  page,
  isMobile,
}, info) => {
  await login(page, "admin", "/settings", "Your profile");
  await expect(page).toHaveURL(/\/settings\/profile$/);
  const sectionNav = page.getByRole("navigation", {
    name: "Settings navigation",
  });
  if (!isMobile) {
    for (const width of [320, 390, 768, 1023, 1024, 1280, 1440, 1904]) {
      await page.setViewportSize({ width, height: 889 });
      await expectNoOverflow(page);
      const menuButton = page.getByRole("button", { name: "Open navigation" });
      expect(await menuButton.isVisible()).toBe(width < 1024);
      expect(await page.locator("aside").isVisible()).toBe(width >= 1024);
      const compact = width < 416;
      if (compact) await expect(sectionNav.getByRole("combobox")).toBeVisible();
      else await expect(sectionNav.getByRole("link")).toHaveCount(3);
    }
    await page.setViewportSize({ width: 1280, height: 460 });
    const rail = page.getByRole("navigation", { name: "Main navigation" });
    const gap = await rail.evaluate((el) => {
      const boxes = [...el.querySelectorAll("a,button")].map((link) =>
        link.getBoundingClientRect(),
      );
      return boxes.slice(1).map((box, index) => box.top - boxes[index].bottom);
    });
    expect(gap.every((value) => Math.abs(value - 4) <= 1)).toBe(true);
    const footer = page.getByRole("navigation", {
      name: "Workspace navigation",
    });
    await expect(footer.getByRole("link")).toHaveCount(1);
    await expect(footer.getByRole("link")).toBeInViewport();
    await expect(footer.getByRole("link")).toHaveAttribute(
      "aria-current",
      "location",
    );
  }
  const opener = page.getByRole("button", { name: "Search navigation" });
  await opener.click();
  const input = page.getByRole("textbox", { name: "Search pages" });
  await input.fill("nothing-matches-this");
  await expect(page.getByText(/No pages found/)).toBeVisible();
  await page.keyboard.press("Control+k");
  await expect(input).toHaveCount(0);
  await expect(opener).toBeFocused();
  await page.keyboard.press("Meta+k");
  await expect(input).toHaveValue("");
  await page.keyboard.press("Escape");
  await opener.click();
  await input.fill("taxonomy");
  await expect(page.getByRole("dialog").getByRole("link")).toHaveCount(1);
  await input.press("Enter");
  await expect(page).toHaveURL(/\/settings\/taxonomy$/);
  await expect(page.locator("h1")).toBeFocused();
  await expect(page.locator("h1")).toHaveCount(1);
  await page.getByRole("link", { name: "Back to Workspace" }).click();
  if (isMobile) {
    await sectionNav.getByRole("combobox").click();
    await page.getByRole("option", { name: "Team & access" }).click();
  } else await sectionNav.getByRole("link", { name: "Team & access" }).click();
  await page.getByRole("link", { name: "Alex Morgan" }).click();
  await expect(
    page.getByRole("link", { name: "Back to Team & access" }),
  ).toBeVisible();
  await expect(page.locator("h1")).toHaveCount(1);
  await page.goto("/promotions/placements/create");
  const rail = await openRail(page);
  await expect(rail.getByRole("link", { name: "Placements" })).toHaveAttribute(
    "aria-current",
    "location",
  );
  if (isMobile) await page.keyboard.press("Escape");
  await page.goto("/settings/profile");
  await expectNoOverflow(page);
  await page.screenshot({ path: info.outputPath("settings-account.png") });
});

test("Local chart periods remain visible and reconcile with daily values", async ({
  page,
  isMobile,
}, info) => {
  await login(page);
  for (const days of [7, 30, 90]) {
    await page.getByRole("combobox", { name: "Reporting period" }).click();
    await page.getByRole("option", { name: "Last " + days + " days" }).click();
    await expect(page).toHaveURL(new RegExp("period=" + days));
    const chart = page.getByRole("img", { name: /Retained registrations/ });
    await expect(chart).toHaveAttribute("tabindex", "0");
    await expect(chart.locator('[tabindex="0"]')).toHaveCount(0);
    if (days === 90 && !isMobile)
      await page.setViewportSize({ width: 1280, height: 900 });
    const bars = chart.locator('[aria-label$="records"]');
    await expect(bars.first()).toBeVisible();
    const counts = await bars.evaluateAll((nodes) =>
      nodes.map((node) => ({
        width: node.getBoundingClientRect().width,
        count: Number(
          node.getAttribute("aria-label")!.match(/(\d+) records$/)![1],
        ),
      })),
    );
    expect(counts.every((bar) => bar.width >= 5)).toBe(true);
    if (days === 90) {
      expect(counts.length).toBeLessThan(90);
      await chart.scrollIntoViewIfNeeded();
      await page.screenshot({ path: info.outputPath("overview-90-days.png") });
    }
    await page.getByRole("button", { name: "View data" }).click();
    const table = page.getByRole("table", { name: /by day in/ });
    const daily = await table.locator("tbody tr").allTextContents();
    expect(daily).toHaveLength(days);
    const values = await table
      .locator("tbody tr td:last-child")
      .allTextContents();
    expect(counts.reduce((sum, bar) => sum + bar.count, 0)).toBe(
      values.reduce((sum, value) => sum + Number(value), 0),
    );
    await page.getByRole("button", { name: "Show chart" }).click();
  }
  await page.goto("/?period=custom&from=2001-01-01&to=2001-01-07");
  await expect(page.getByText("No records in this range.")).toBeVisible();
});

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
  const logo = page.getByRole("img", { name: "XPN Social", exact: true });
  await expect(logo).toBeVisible();
  await expect(logo).toHaveJSProperty("naturalWidth", 256);
  await expect(page.getByRole("complementary")).toHaveCount(0);
  await expect(
    page.getByRole("button", { name: "Continue securely" }),
  ).toHaveCSS("background-color", "rgb(117, 69, 232)");
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
    page.getByRole("columnheader", { name: "Date", exact: true }),
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
  await expect(
    page
      .getByRole("navigation", { name: "Settings navigation" })
      .getByRole("heading", { name: "Account" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Search navigation" }).click();
  await page.getByRole("textbox", { name: "Search pages" }).fill("taxonomy");
  await expect(page.getByText(/No pages found/)).toBeVisible();
  await page.keyboard.press("Escape");
  const name = page.getByLabel("Display name", { exact: true });
  await expect(
    page.getByRole("button", { name: "Save profile" }),
  ).toBeDisabled();
  await name.fill(
    (await name.inputValue()) === "Jamie Rivera" ? "Jamie R." : "Jamie Rivera",
  );
  await page.getByRole("button", { name: "Save profile" }).click();
  await expect(
    page.getByText("Your display name has been saved.", { exact: true }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Preferences", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Save preferences" }),
  ).toBeDisabled();
  const rows = page.getByRole("combobox", { name: "Rows per page" });
  const newSize = (await rows.innerText()).includes("10")
    ? "20 rows"
    : "10 rows";
  await rows.click();
  await page.getByRole("option", { name: newSize }).click();
  await page.getByRole("combobox", { name: "Display timezone" }).click();
  await page.getByRole("option", { name: "Asia/Dhaka", exact: true }).click();
  await page.getByRole("button", { name: "Save preferences" }).click();
  await expect(
    page.getByText("Your workspace preferences have been saved.", {
      exact: true,
    }),
  ).toBeVisible();
  await page.reload();
  await expect(
    page.getByRole("combobox", { name: "Rows per page" }),
  ).toContainText(newSize);
  await page.goto("/settings/security");
  const lastActive = page.locator("time").first();
  const timestamp = await lastActive.getAttribute("datetime");
  await expect(lastActive).toHaveAttribute("title", "Asia/Dhaka");
  await expect(lastActive).toHaveText(
    await page.evaluate(
      (value) =>
        new Intl.DateTimeFormat("en", {
          dateStyle: "medium",
          timeStyle: "short",
          timeZone: "Asia/Dhaka",
        }).format(new Date(value!)),
      timestamp,
    ),
  );
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

test("staff settings, attention and dialogs fit the shared responsive shell", async ({
  page,
}, testInfo) => {
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await login(page);

  for (const [path, name] of [
    ["/settings/profile", "profile"],
    ["/settings/security", "security"],
    ["/settings/team", "team"],
    ["/attention", "attention"],
  ]) {
    await page.goto(path);
    await expect(page.getByRole("main")).toBeVisible();
    await expect(page.locator("h1")).toHaveCount(1);
    await expectNoOverflow(page);
    await page.screenshot({ path: testInfo.outputPath(`${name}.png`) });
  }

  await page.goto("/settings/team");
  await page.getByRole("button", { name: "Invite staff member" }).click();
  await expect(
    page.getByRole("dialog", { name: "Invite a staff member" }),
  ).toBeVisible();
  await expect(
    page.locator("[data-base-ui-portal] .transition-opacity"),
  ).toHaveCSS("opacity", "1");
  await expect(
    page.getByRole("button", { name: "Create invitation" }),
  ).toBeInViewport();
  await page.screenshot({ path: testInfo.outputPath("invitation-dialog.png") });
  await page.keyboard.press("Escape");
  await expect(
    page.getByRole("button", { name: "Invite staff member" }),
  ).toBeFocused();

  if (testInfo.project.name === "desktop-chromium") {
    await page.setViewportSize({ width: 820, height: 1180 });
    await page.goto("/");
    await expect(
      page.getByRole("button", { name: "Open navigation" }),
    ).toBeVisible();
    await page.screenshot({ path: testInfo.outputPath("tablet-overview.png") });
  }
  expect(errors).toEqual([]);
});

test("identity confirmation, authenticator replacement and password change revoke sessions", async ({
  page,
  browser,
}, testInfo) => {
  test.skip(testInfo.project.name !== "desktop-chromium");
  await login(page, "support");
  const other = await browser.newContext();
  const second = await other.newPage();

  try {
    await login(second, "support");
    await page.goto("/settings/security");
    await page.getByRole("link", { name: "Confirm identity" }).click();
    control("reset-replay", "support");
    await page
      .getByLabel("Password", { exact: true })
      .fill("StaffFixturePassword123!");
    await page
      .getByLabel("Authenticator code", { exact: true })
      .fill(control("code", "support"));
    await page.getByRole("button", { name: "Verify and continue" }).click();
    await expect(page).toHaveURL(/\/settings\/security$/);

    control("reset-replay", "support");
    await page
      .getByRole("button", { name: "Replace authenticator", exact: true })
      .click();
    await page
      .getByLabel("Password", { exact: true })
      .fill("StaffFixturePassword123!");
    await page
      .getByLabel("Current authenticator code")
      .fill(control("code", "support"));
    await page.getByRole("button", { name: "Set up replacement" }).click();
    const secret = await page.locator("code").textContent();
    expect(secret).toBeTruthy();
    const code = page.getByLabel("New authenticator code");
    await expect(code).toBeFocused();
    await page.reload();
    await expect(code).toBeVisible();
    await expect(page.locator("code")).toHaveCount(0);
    const invalid = String((Number(totp(secret!)) + 1) % 1000000).padStart(
      6,
      "0",
    );
    await code.fill(invalid);
    await page
      .getByRole("button", { name: "Replace authenticator and sign out" })
      .click();
    await expect(code).toHaveAttribute("aria-invalid", "true");
    await expect(page.getByRole("alert")).toContainText(
      "Enter the code from your new authenticator.",
    );
    await code.fill(totp(secret!));
    await page
      .getByRole("button", { name: "Replace authenticator and sign out" })
      .click();
    await expect(page).toHaveURL(/\/login$/);
    await second.goto("/");
    await expect(second).toHaveURL(/\/login$/);

    await login(page, "support", "/settings/security", "Active sessions");
    control("reset-replay", "support");
    await page
      .getByRole("button", { name: "Change password", exact: true })
      .click();
    await page
      .getByLabel("Current password", { exact: true })
      .fill("StaffFixturePassword123!");
    await page
      .getByLabel("New password", { exact: true })
      .fill("UpdatedFixturePassword123!");
    await page
      .getByLabel("Fresh authenticator code")
      .fill(control("code", "support"));
    await page
      .getByRole("button", { name: "Change password and sign out" })
      .click();
    await expect(page).toHaveURL(/\/login$/);
    await page.goBack();
    await expect(
      page.getByRole("heading", { name: "Active sessions" }),
    ).toHaveCount(0);
    await login(
      page,
      "support",
      "/settings/security",
      "Active sessions",
      "UpdatedFixturePassword123!",
    );
  } finally {
    await other.close();
  }
});

test.describe("Scheduling in the staff timezone", () => {
  test.use({ timezoneId: "Asia/Tokyo" });

  test("Today uses the saved timezone and an ambiguous incident round-trips through storage", async ({
    page,
  }) => {
    // Simulate a device whose installed zone database disagrees with the server.
    await page.addInitScript(() => {
      Intl.DateTimeFormat = new Proxy(Intl.DateTimeFormat, {
        construct(target, [locales, options]) {
          const deviceOptions =
            options?.timeZone === "America/New_York"
              ? { ...options, timeZone: "Etc/GMT+5" }
              : options;
          return new target(locales, deviceOptions);
        },
      });
    });
    await login(
      page,
      "operations",
      "/settings/preferences",
      "Workspace preferences",
    );
    const zone = page.getByRole("combobox", { name: "Display timezone" });
    if (!(await zone.innerText()).includes("America/New York")) {
      await zone.click();
      await page
        .getByRole("option", { name: "America/New York", exact: true })
        .click();
      await page.getByRole("button", { name: "Save preferences" }).click();
      await expect(
        page.getByText("Your workspace preferences have been saved.", {
          exact: true,
        }),
      ).toBeVisible();
    }
    await page.goto("/operations/jobs");
    await expect(page.getByText(/Observed .+America\/New_York/)).toBeVisible();
    await page.goto("/promotions/surface");
    const day = page.getByRole("button", { name: /^Incident start:/ });
    const clock = page.locator(
      'input[type=time][aria-label="Incident start time"]',
    );
    await page.evaluate((instant) => {
      Date.now = () => instant;
    }, Date.parse("2024-03-11T02:00:00Z"));
    await day.click();
    await page.getByRole("button", { name: "Today", exact: true }).click();
    await expect(day).toContainText("Mar 10, 2024");
    await clock.fill("02:30");
    await expect(page.getByRole("alert")).toContainText(
      "This time does not exist",
    );
    expect(
      await clock.evaluate((el: HTMLInputElement) => el.checkValidity()),
    ).toBe(false);

    await page.evaluate((instant) => {
      Date.now = () => instant;
    }, Date.parse("2024-11-04T02:00:00Z"));
    await day.click();
    await page.getByRole("button", { name: "Today", exact: true }).click();
    await expect(day).toContainText("Nov 3, 2024");
    await clock.fill("01:30");
    expect(
      await clock.evaluate((el: HTMLInputElement) => el.checkValidity()),
    ).toBe(false);
    await page
      .getByRole("combobox", { name: "Incident start occurrence" })
      .click();
    await expect(
      page.getByRole("option", { name: "First · -04:00" }),
    ).toBeVisible();
    await page.getByRole("option", { name: "Second · -05:00" }).click();
    expect(
      await clock.evaluate((el: HTMLInputElement) => el.checkValidity()),
    ).toBe(true);
    const reference = "tz-check-" + Date.now();
    await page
      .getByLabel("Independent observation or incident reference")
      .fill(reference);
    await page
      .getByLabel("Observation note")
      .fill("Verified historical incident window");
    const submission = page.waitForRequest(
      (req) =>
        req.method() === "POST" && req.url().includes("/promotions/surface"),
    );
    await page
      .getByRole("button", { name: "Record observation", exact: true })
      .click();
    expect((await submission).postDataJSON().starts_at).toBe(
      "2024-11-03T06:30:00Z",
    );
    await expect(
      page.getByText("Surface observation recorded.", { exact: true }),
    ).toBeVisible();
    await page.reload();
    const record = page.getByRole("row").filter({ hasText: reference });
    await expect(record).toContainText(
      /Nov 3, 2024(?:,| at) 1:30 AM \(-05:00\)/,
    );
    await expectNoOverflow(page);
  });
});
