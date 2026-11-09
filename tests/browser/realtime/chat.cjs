// Run against server.php and the Next frontend configured to proxy its disposable API.
const { chromium } = require("playwright");
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const control = process.argv[2];
const { web, api } = JSON.parse(
  fs.readFileSync(path.join(control, "ready.json")),
);
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
async function request(route, token, method = "GET", body) {
  const res = await fetch(api + "/api/v1" + route, {
    method,
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      ...(token ? { Authorization: "Bearer " + token } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  assert.ok(res.ok, method + " " + route + " " + res.status);
  return (await res.json()).data;
}
async function run() {
  const login = (name) =>
    request("/auth/login", null, "POST", {
      email: name + "@example.com",
      password: "password123",
    });
  const [alice, bob, carol] = await Promise.all(
    ["alice", "bob", "carol"].map(login),
  );
  const browser = await chromium.launch({ channel: "chrome", headless: true });
  try {
    const page = await browser.newPage();
    let registrationAttempts = 0;
    await page.route("**/*", (route) => {
      const u = new URL(route.request().url());
      if (u.hostname !== "127.0.0.1") return route.abort();
      if (
        u.pathname.endsWith("/realtime/session") &&
        ++registrationAttempts === 1
      )
        return route.fulfill({
          status: 503,
          contentType: "application/json",
          body: "{}",
        });
      return route.continue();
    });
    await page.addInitScript(
      ({ token, expires }) => {
        localStorage.setItem("xspann_vertical_token", token);
        localStorage.setItem("xspann_vertical_token_expires_at", expires);
      },
      { token: bob.token, expires: bob.token_expires_at },
    );
    const frames = [],
      requests = [];
    page.on(
      "websocket",
      (ws) =>
        ws.url().includes("/app/") &&
        ws.on("framereceived", (frame) => {
          const { event, data } = JSON.parse(frame.payload);
          if (!event.startsWith("pusher"))
            frames.push({
              event,
              data: typeof data === "string" ? JSON.parse(data) : data,
            });
        }),
    );
    page.on("request", (req) =>
      requests.push(req.method() + " " + new URL(req.url()).pathname),
    );
    await page.goto(web + "/inbox/1", { timeout: 60000 });
    await page.getByText("Live messaging", { exact: true }).waitFor();
    assert.ok(registrationAttempts >= 2);
    await page.waitForLoadState("networkidle");
    const delta = page.waitForResponse(
      (response) => {
        const url = new URL(response.url());
        return (
          response.ok() &&
          url.pathname === "/api/v1/conversations/1/messages" &&
          url.searchParams.has("after_id")
        );
      },
      { timeout: 15000 },
    );
    const send = (thread, body, token) =>
      request("/conversations/" + thread + "/messages", token, "POST", {
        body,
        client_message_id: crypto.randomUUID(),
      });
    const received = await send(1, "journey inbound", alice.token);
    await page
      .getByText("journey inbound", { exact: false })
      .first()
      .waitFor({ timeout: 8000 });
    await delta;
    assert.ok(
      frames.some(
        (f) =>
          f.event === "message.created" &&
          f.data.resource_id === received.id &&
          f.data.conversation_id === 1,
      ),
    );
    const summary = () => request("/me/communication-summary", bob.token);
    for (let i = 0; i < 40 && (await summary()).unread_messages; i++)
      await delay(150);
    assert.equal((await summary()).unread_messages, 0);
    await delay(1500);
    requests.length = 0;
    for (let i = 0; i < 20; i++)
      await send(2, "other thread " + i, carol.token);
    await page
      .getByLabel("20 unread messages", { exact: true })
      .first()
      .waitFor({ timeout: 8000 });
    assert.deepEqual(await summary(), {
      unread_messages: 20,
      unread_notifications: 20,
    });
    assert.ok(frames.some((f) => f.event === "notification.created"));
    assert.equal(
      requests.filter((r) =>
        /^GET .*\/conversations\/1(?:\/messages)?$/.test(r),
      ).length,
      0,
    );
    fs.writeFileSync(path.join(control, "restrict"), "");
    await page
      .getByText("Message unavailable", { exact: true })
      .first()
      .waitFor({ timeout: 8000 });
    assert.equal(
      await page.getByText("journey inbound", { exact: false }).count(),
      0,
    );
    const reconnect = page.waitForEvent("websocket", {
      predicate: (ws) => ws.url().includes("/app/"),
      timeout: 20000,
    });
    fs.writeFileSync(path.join(control, "restart"), "");
    await reconnect;
    await page.getByText("Live messaging", { exact: true }).waitFor();
    await send(1, "journey after restart", alice.token);
    await page
      .getByText("journey after restart", { exact: false })
      .first()
      .waitFor({ timeout: 8000 });
    await page.getByPlaceholder("Write a message…").fill("journey outgoing");
    await page.getByRole("button", { name: "Send message" }).click();
    await page
      .getByText("journey outgoing", { exact: false })
      .first()
      .waitFor();
    assert.ok(
      (await request("/conversations/1/messages", alice.token)).some(
        (m) => m.body === "journey outgoing",
      ),
    );
    // Simulate a visible but unfocused window. Its message is rendered without advancing the read cursor.
    await page.evaluate(() =>
      Object.defineProperty(document, "hasFocus", {
        configurable: true,
        value: () => false,
      }),
    );
    await send(1, "journey unfocused", alice.token);
    await page
      .getByText("journey unfocused", { exact: false })
      .first()
      .waitFor({ timeout: 8000 });
    await delay(650);
    assert.ok((await request("/conversations/1", bob.token)).unread_count > 0);
    await page.evaluate(() => {
      delete document.hasFocus;
      window.dispatchEvent(new Event("focus"));
    });
    for (
      let i = 0;
      i < 40 && (await request("/conversations/1", bob.token)).unread_count;
      i++
    )
      await delay(150);
    assert.equal(
      (await request("/conversations/1", bob.token)).unread_count,
      0,
    );
    await page.goto(web + "/activity");
    await page.getByRole("button", { name: "Mark all as read" }).waitFor();
    await page.waitForLoadState("networkidle");
    await request("/users/" + bob.user.id + "/follow", carol.token, "DELETE");
    await request("/users/" + bob.user.id + "/follow", carol.token, "POST");
    await page
      .getByText(/started following you/i)
      .first()
      .waitFor({ timeout: 10000 });
    await page.getByRole("button", { name: "Mark all as read" }).click();
    for (let i = 0; i < 40 && (await summary()).unread_notifications; i++)
      await delay(150);
    assert.deepEqual(await summary(), {
      unread_messages: 20,
      unread_notifications: 0,
    });
    await page.getByRole("button", { name: "Clear read activity" }).click();
    for (
      let i = 0;
      i < 40 && (await request("/notifications", bob.token)).length;
      i++
    )
      await delay(150);
    assert.deepEqual(await request("/notifications", bob.token), []);
    console.log(
      "Chrome passed registration retry, private chat in both directions, both unread totals, 20 unrelated DMs with zero thread GETs, moderation, Relay restart, visible-only reads, live social activity, read-all and clear.",
    );
  } finally {
    await browser.close();
  }
}
run().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
