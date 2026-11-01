import { test, expect, type Page } from "@playwright/test";
import { execFileSync } from "node:child_process";
const fixture =
  process.env.XPN_STAFF_FIXTURE || "/private/tmp/xpn-staff-step2-review";
const control = (action: string, user: string) =>
  execFileSync(
    "php",
    ["tests/browser/staff/control.php", fixture, action, user],
    { encoding: "utf8" },
  ).trim();
const seed = () =>
  JSON.parse(
    execFileSync(
      "php",
      ["tests/browser/staff/community-fixture.php", fixture],
      { encoding: "utf8" },
    ),
  ) as {
    report: number;
    user: number;
    video: number;
    artist: number;
    playlist: number;
    boost: number;
  };
async function login(page: Page, role: string, path: string) {
  control("reset-replay", role);
  await page.goto(path);
  await page
    .getByLabel("Email address", { exact: true })
    .fill(`${role}@example.test`);
  await page
    .getByLabel("Password", { exact: true })
    .fill("StaffFixturePassword123!");
  await page.getByRole("button", { name: "Continue securely" }).click();
  await page
    .getByLabel("Authenticator code", { exact: true })
    .fill(control("code", role));
  await page.getByRole("button", { name: "Verify and continue" }).click();
}
test("moderation evidence is explicit and decisions use browser forms", async ({
  page,
}, testInfo) => {
  const record = seed();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await login(page, "moderator", `/moderation/reports/${record.report}`);
  await expect(
    page.getByRole("heading", { name: `Case #${record.report}`, exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Reported context visible only after opening evidence"),
  ).toHaveCount(0);
  await page.getByRole("button", { name: "Open reported evidence" }).click();
  await expect(
    page.getByText("Reported context visible only after opening evidence"),
  ).toBeVisible();
  await page.getByRole("button", { name: "Close", exact: true }).last().click();
  await expect(
    page.getByText("Reported context visible only after opening evidence"),
  ).toHaveCount(0);
  await page.getByRole("combobox", { name: "Priority", exact: true }).click();
  await page.getByRole("option", { name: "high", exact: true }).click();
  await page.getByRole("button", { name: "Update assignment" }).click();
  await expect(page.getByText("Case assignment updated.")).toBeVisible();
  await page.getByRole("combobox", { name: "Decision", exact: true }).click();
  await page.getByRole("option", { name: "restrict", exact: true }).click();
  await page
    .getByLabel("Reason", { exact: true })
    .fill("Rights declaration requires correction.");
  await page.getByRole("button", { name: "Confirm decision" }).click();
  await expect(
    page.getByText("Active restriction", { exact: false }),
  ).toBeVisible();
  await page.screenshot({
    path: testInfo.outputPath("moderation-case.png"),
    fullPage: true,
  });
  expect(errors).toEqual([]);
});
test("directories, disclosure, pagination and promotion date controls", async ({
  page,
}, testInfo) => {
  const record = seed();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await login(page, "admin", "/users");
  await expect(
    page.getByRole("heading", { name: "Users", exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page).toHaveURL(/page=2/);
  await page.goto(`/users/${record.user}`);
  await page.getByRole("button", { name: "Reveal contact" }).click();
  await page.getByLabel("Purpose of contact access").fill("     ");
  await page.getByRole("button", { name: "Reveal email" }).click();
  await expect(
    page.getByRole("alert").filter({ hasText: /reason.*(required|at least)/i }),
  ).toBeVisible();
  await page
    .getByLabel("Purpose of contact access")
    .fill("The customer requested account support.");
  await page.getByRole("button", { name: "Reveal email" }).click();
  await expect(
    page.getByRole("dialog").getByText(/@example.test/),
  ).toBeVisible();
  await page.keyboard.press("Escape");
  await page.goto("/promotions/surface");
  await expect(
    page.getByRole("heading", { name: "Promotion surface", exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Incident start: Choose a date" })
    .click();
  await expect(page.getByRole("grid")).toBeVisible();
  await page.keyboard.press("Escape");
  await page.screenshot({
    path: testInfo.outputPath("promotion-surface.png"),
    fullPage: true,
  });
  await page.goto("/promotions/placements");
  await page.getByRole("tab", { name: "Calendar", exact: true }).click();
  await expect(page).toHaveURL(/view=calendar/);
  await expect(page.getByRole("grid")).toBeVisible();
  await page.getByRole("tab", { name: "Features", exact: true }).click();
  await expect(page).toHaveURL(/type=feature/);
  await page.goto("/promotions/placements/create");
  await expect(
    page.getByRole("heading", {
      name: "Enroll monthly placements",
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Create both obligations" }),
  ).toBeDisabled();
  expect(errors).toEqual([]);
});

test("evidence clears on navigation, history restoration and local MFA expiry", async ({
  page,
}) => {
  const record = seed();
  const evidence = page.getByText(
    "Reported context visible only after opening evidence",
  );
  await page.clock.install();
  await login(page, "moderator", `/moderation/reports/${record.report}`);
  await page.getByRole("button", { name: "Open reported evidence" }).click();
  await expect(evidence).toBeVisible();
  await page.keyboard.press("Escape");
  await page
    .getByRole("link", { name: "Target metadata and dependencies" })
    .click();
  await expect(page).toHaveURL(`/content/videos/${record.video}`);
  await page.goBack();
  await expect(page).toHaveURL(`/moderation/reports/${record.report}`);
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(evidence).toHaveCount(0);
  await page.getByRole("button", { name: "Open reported evidence" }).click();
  await expect(evidence).toBeVisible();
  await page.clock.fastForward(31 * 60 * 1000);
  await expect(page.getByRole("dialog")).toHaveCount(0);
  await expect(evidence).toHaveCount(0);
});

test("playlist and delivery history paginate without losing record context", async ({
  page,
}, testInfo) => {
  const record = seed();
  const errors: string[] = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await login(
    page,
    "admin",
    `/content/playlists/${record.playlist}?per_page=1`,
  );
  await expect(
    page.getByRole("heading", { name: "Playlist items" }),
  ).toBeVisible();
  await expect(
    page.getByRole("link", { name: "A studio performance" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(
    page.getByRole("link", { name: "A second performance" }),
  ).toBeVisible();
  await expect(page).toHaveURL(/page=2/);
  await page.screenshot({
    path: testInfo.outputPath("playlist-items.png"),
    fullPage: true,
  });
  await page.goto(`/promotions/boosts/${record.boost}?per_page=1`);
  await expect(
    page.getByRole("heading", { name: "Recorded delivery intervals" }),
  ).toBeVisible();
  await expect(page.getByRole("row")).toHaveCount(2);
  await page.getByRole("button", { name: "Next", exact: true }).click();
  await expect(page).toHaveURL(/cursor=/);
  await expect(
    page.getByRole("button", { name: "Next", exact: true }),
  ).toBeDisabled();
  await page.getByRole("button", { name: "Previous", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Previous", exact: true }),
  ).toBeDisabled();
  await page.screenshot({
    path: testInfo.outputPath("boost-history.png"),
    fullPage: true,
  });
  expect(errors).toEqual([]);
});
