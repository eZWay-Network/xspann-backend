// Opt-in test against two mutual fixture users on a shared Wi-Fi API.
// Pass web, android or ios. Native peers print XPN_CHAT_OPEN/AWAY and the supplied marker.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const { chromium } = require("playwright");
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function until(check, description, timeout = 30000) {
  const end = Date.now() + timeout;
  do {
    if (await check()) return;
    await wait(150);
  } while (Date.now() < end);
  throw new Error("Timed out waiting for " + description);
}

async function run() {
  const env = process.env;
  const target = process.argv[2];
  assert.ok(["ios", "android", "web"].includes(target));
  const api = new URL(env.XPN_READ_API).href.replace(/\/$/, "");
  const web = new URL(env.XPN_READ_WEB).origin;
  const marker = env.XPN_READ_MARKER;
  assert.ok(
    marker &&
      env.XPN_READ_SENDER &&
      env.XPN_READ_RECIPIENT &&
      env.XPN_READ_PASSWORD,
  );
  const sessions = [];
  async function request(path, token, method = "GET", payload) {
    const headers = {
      accept: "application/json",
      "content-type": "application/json",
    };
    if (token) headers.authorization = "Bearer " + token;
    const response = await fetch(api + path, {
      method,
      headers,
      body: JSON.stringify(payload),
    });
    assert.ok(
      response.ok,
      method + " " + path + " returned " + response.status,
    );
    return (await response.json()).data;
  }
  const browser = await chromium.launch({ channel: "chrome" });
  try {
    for (const email of [env.XPN_READ_SENDER, env.XPN_READ_RECIPIENT]) {
      sessions.push(
        await request("/auth/login", null, "POST", {
          email,
          password: env.XPN_READ_PASSWORD,
        }),
      );
    }
    const [sender, reader] = sessions;
    const threads = await request("/conversations", reader.token);
    const thread = threads.find((t) => t.recipient_user_id === sender.user.id);
    assert.ok(
      thread?.can_send,
      "The two selected fixture users need an existing writable conversation",
    );
    const path = "/conversations/" + thread.id;
    const detail = () => request(path, reader.token);
    const summary = () => request("/me/communication-summary", reader.token);
    async function pageFor(session, route) {
      const page = await browser.newPage();
      await page.addInitScript(
        ({ token, expires }) => {
          localStorage.setItem("xspann_vertical_token", token);
          localStorage.setItem("xspann_vertical_token_expires_at", expires);
        },
        { token: session.token, expires: session.token_expires_at },
      );
      await page.goto(web + route, { timeout: 60000 });
      return page;
    }
    const watch = await pageFor(reader, "/inbox");
    const row = watch.locator(`a[href="/inbox/${thread.id}"]`);
    await row.waitFor();
    let webCounts;
    watch.on("response", async (response) => {
      if (
        new URL(response.url()).pathname.endsWith(
          "/me/communication-summary",
        ) &&
        response.ok()
      ) {
        try {
          webCounts = (await response.json()).data;
        } catch {
          /* Navigation can cancel a response. */
        }
      }
    });
    async function send(label) {
      const result = await request(path + "/messages", sender.token, "POST", {
        body: marker + " " + label,
        client_message_id: crypto.randomUUID(),
      });
      return result.id;
    }
    async function stage(value) {
      const needle = "XPN_CHAT_" + value + " " + marker;
      await until(
        () =>
          fs.existsSync(env.XPN_READ_STAGE_LOG) &&
          fs.readFileSync(env.XPN_READ_STAGE_LOG, "utf8").includes(needle),
        needle,
        240000,
      );
    }
    let chat;
    if (target === "web") {
      chat = await pageFor(reader, "/inbox/" + thread.id);
      await chat.getByText("Live messaging", { exact: true }).waitFor();
    } else {
      await stage("OPEN");
    }
    await until(
      async () => (await detail()).unread_count === 0,
      "open chat to catch up",
    );
    const baseline = await summary();
    async function counts(message, expected, read) {
      const state = await detail();
      const counts = await summary();
      return (
        (read
          ? state.last_read_message_id >= message
          : state.last_read_message_id < message) &&
        state.unread_count === expected &&
        counts.unread_messages === baseline.unread_messages + expected &&
        counts.unread_notifications === baseline.unread_notifications + expected
      );
    }
    async function webMatches(expected) {
      return (
        webCounts?.unread_messages === baseline.unread_messages + expected &&
        webCounts?.unread_notifications ===
          baseline.unread_notifications + expected &&
        (await row.locator('[aria-label$="unread messages"]').count()) ===
          (expected ? 1 : 0)
      );
    }
    const live = await send("live");
    await until(() => counts(live, 0, true), target + " visible read");
    if (chat) {
      await chat.goto(web + "/inbox");
      await chat.getByRole("searchbox").waitFor();
    } else {
      await stage("AWAY");
    }
    const missed = await send("resume");
    await until(
      () => counts(missed, 1, false),
      "unread message and activity at rest",
    );
    await until(
      () => webMatches(1),
      "other web session to display increased counts",
    );
    await wait(700);
    assert.ok(
      await counts(missed, 1, false),
      "Background or closed chat advanced its read cursor",
    );
    console.log(
      target,
      "kept the away message unread; another session displayed the incoming count",
    );
    if (chat) {
      await chat.locator(`a[href="/inbox/${thread.id}"]`).click();
      await chat
        .getByText(marker + " resume", { exact: false })
        .first()
        .waitFor();
    }
    await until(() => counts(missed, 0, true), target + " resumed read", 45000);
    await until(
      () => webMatches(0),
      "other web session to clear the badge and DM activity",
    );
    console.log(
      target,
      "passed; thread unread 0 → 1 → 0, both summary totals returned to baseline",
      baseline,
    );
  } finally {
    await browser.close();
    for (const session of sessions)
      await request("/auth/logout", session.token, "POST", {});
  }
}
run().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
