// Keep a real Next conversation open as the peer for both native platform engines.
const { chromium } = require("playwright");
const { readFileSync } = require("node:fs");
const path = require("node:path");
const { web, api } = JSON.parse(
  readFileSync(path.join(process.argv[2], "ready.json")),
);
(async () => {
  const res = await fetch(api + "/api/v1/auth/login", {
    method: "POST",
    headers: { "Content-Type": "application/json", Accept: "application/json" },
    body: JSON.stringify({ email: "bob@example.com", password: "password123" }),
  });
  if (!res.ok) throw Error("Fixture login failed " + res.status);
  const session = (await res.json()).data;
  const browser = await chromium.launch({ channel: "chrome" });
  try {
    const page = await browser.newPage();
    await page.route("**/*", (r) =>
      new URL(r.request().url()).hostname === "127.0.0.1"
        ? r.continue()
        : r.abort(),
    );
    await page.addInitScript(({ token, token_expires_at }) => {
      localStorage.setItem("xspann_vertical_token", token);
      localStorage.setItem(
        "xspann_vertical_token_expires_at",
        token_expires_at,
      );
    }, session);
    await page.goto(web + "/inbox/1");
    await page.getByText("Live messaging", { exact: true }).waitFor();
    const old = (
      await page.locator("[data-incoming]").allTextContents()
    ).flatMap(
      (text) => text.match(/native-(?:live|away)-[0-9a-f-]{36}/g) || [],
    );
    const handled = new Set(old);
    let replies = 0;
    const until = Date.now() + 480_000;
    while (replies < 4 && Date.now() < until) {
      const incoming = await page.locator("[data-incoming]").allTextContents();
      for (const text of incoming) {
        const tag = text.match(/native-(?:live|away)-[0-9a-f-]{36}/)?.[0];
        if (!tag || handled.has(tag)) continue;
        const reply = tag.replace("native-", "web-");
        await page.getByPlaceholder("Write a message…").fill(reply);
        await page.getByRole("button", { name: "Send message" }).click();
        await page.getByText(reply, { exact: false }).first().waitFor();
        handled.add(tag);
        replies++;
        console.log("Replied through the conversation UI", replies);
      }
      await page.waitForTimeout(100);
    }
    if (replies !== 4) throw Error("Missing native round trips");
  } finally {
    await browser.close();
  }
})().catch((err) => {
  console.error(err);
  process.exitCode = 1;
});
