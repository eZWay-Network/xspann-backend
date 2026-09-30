(() => {
  "use strict";
  const reference = JSON.parse(
    document.getElementById("reference-data").textContent,
  );
  const endpoints = Object.values(reference.groups).flat();
  const byId = new Map(endpoints.map((endpoint) => [endpoint.id, endpoint]));
  const cards = [...document.querySelectorAll(".endpoint")];
  const search = document.getElementById("search");
  const filter = document.getElementById("access-filter");
  const sidebar = document.getElementById("sidebar");
  const menu = document.getElementById("menu-toggle");
  const toast = document.getElementById("toast");
  let toastTimer;
  const notify = (message) => {
    toast.textContent = message;
    toast.classList.add("visible");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove("visible"), 2500);
  };
  const shellQuote = (value) =>
    "'" + String(value).replace(/'/g, "'\\''") + "'";
  const requestUrl = (endpoint) => {
    const values = {
      username: "alex",
      provider: "google",
      hash: "<email-hash>",
    };
    let path = endpoint.uri
      .replace("/api/v1", "")
      .replace(/\{([^}]+)\}/g, (_, key) => values[key] || "1");
    let query = "";
    if (endpoint.limit) query = "?page=1&limit=" + endpoint.limit;
    if (endpoint.id === "authcontroller-verifyemail")
      query = "?expires=<expires>&signature=<signature>";
    return reference.baseUrl.replace(/\/$/, "") + path + query;
  };
  const requestExample = (endpoint, language) => {
    const url = requestUrl(endpoint);
    const body = endpoint.example;
    const multipart = endpoint.contentType === "multipart/form-data";
    const headers = { Accept: "application/json" };
    if (endpoint.auth) headers.Authorization = "Bearer <access-token>";
    if (endpoint.contentType === "application/json")
      headers["Content-Type"] = "application/json";
    const entries = Object.entries(body);
    const signedNote =
      endpoint.id === "authcontroller-verifyemail"
        ? "// Use the exact signed URL from the verification email.\n"
        : "";
    if (language === "javascript") {
      let setup = signedNote;
      if (multipart) {
        setup +=
          "// selectedFile is a File; for chunks, use the matching Blob slice.\nconst form = new FormData();\n";
        for (const [key, value] of entries) {
          setup += `form.append(${JSON.stringify(key)}, ${["file", "chunk"].includes(key) ? (key === "chunk" ? 'chunkBlob, "chunk.part"' : "selectedFile") : JSON.stringify(String(value))});\n`;
        }
        setup += "\n";
      }
      const options = [
        `  method: ${JSON.stringify(endpoint.method)}`,
        `  headers: ${JSON.stringify(headers, null, 2).replace(/\n/g, "\n  ")}`,
      ];
      if (multipart) options.push("  body: form");
      else if (entries.length)
        options.push(
          `  body: JSON.stringify(${JSON.stringify(body, null, 2).replace(/\n/g, "\n  ")})`,
        );
      return (
        setup +
        `const response = await fetch(${JSON.stringify(url)}, {\n${options.join(",\n")}\n});\nconst payload = await response.json();\nif (!response.ok) {\n  // payload.errors may contain field-level validation errors.\n  throw new Error(payload.message || \`HTTP \${response.status}\`);\n}\nconsole.log(payload.data);`
      );
    }
    const lines = [`curl --request ${endpoint.method} ${shellQuote(url)}`];
    for (const [key, value] of Object.entries(headers))
      lines.push(`  --header ${shellQuote(key + ": " + value)}`);
    if (multipart) {
      for (const [key, value] of entries)
        lines.push(`  --form ${shellQuote(key + "=" + value)}`);
    } else if (entries.length)
      lines.push(`  --data ${shellQuote(JSON.stringify(body, null, 2))}`);
    return (
      (signedNote
        ? "# Use the exact signed URL from the verification email.\n"
        : "") + lines.join(" \\\n")
    );
  };
  document.querySelectorAll(".request-example").forEach((panel) => {
    const endpoint = byId.get(panel.dataset.id);
    const select = panel.querySelector("select");
    const output = panel.querySelector("pre");
    const render = () => {
      output.textContent = requestExample(endpoint, select.value);
    };
    select.addEventListener("change", render);
    render();
  });
  const updateFilter = () => {
    const terms = search.value
      .trim()
      .toLowerCase()
      .split(/\s+/)
      .filter(Boolean);
    let visible = 0;
    cards.forEach((card) => {
      const matches =
        terms.every((term) => card.dataset.search.includes(term)) &&
        (filter.value === "all" || filter.value === card.dataset.auth);
      card.hidden = !matches;
      document.querySelector(`[data-endpoint="${card.id}"]`).hidden = !matches;
      if (matches) visible++;
    });
    document.querySelectorAll(".endpoint-group").forEach((group) => {
      group.hidden = ![...group.querySelectorAll(".endpoint")].some(
        (card) => !card.hidden,
      );
    });
    document.querySelectorAll(".nav-group").forEach((group) => {
      group.hidden = ![...group.querySelectorAll(".endpoint-link")].some(
        (link) => !link.hidden,
      );
    });
    document.getElementById("result-count").textContent =
      `${visible} of ${cards.length} endpoints`;
    document.getElementById("empty-state").hidden = visible > 0;
  };
  search.addEventListener("input", updateFilter);
  filter.addEventListener("change", updateFilter);
  document.getElementById("clear-filters").addEventListener("click", () => {
    search.value = "";
    filter.value = "all";
    updateFilter();
    search.focus();
  });
  const closeMenu = () => {
    sidebar.classList.remove("is-open");
    menu.setAttribute("aria-expanded", "false");
  };
  menu.hidden = false;
  menu.addEventListener("click", () => {
    const open = sidebar.classList.toggle("is-open");
    menu.setAttribute("aria-expanded", String(open));
  });
  sidebar
    .querySelectorAll("a")
    .forEach((link) => link.addEventListener("click", closeMenu));
  document.addEventListener("keydown", (event) => {
    if (
      event.key === "/" &&
      !event.ctrlKey &&
      !event.metaKey &&
      !["INPUT", "TEXTAREA", "SELECT"].includes(document.activeElement.tagName)
    ) {
      event.preventDefault();
      search.focus();
      sidebar.classList.add("is-open");
      menu.setAttribute("aria-expanded", "true");
    }
    if (event.key === "Escape") closeMenu();
  });
  const revealHash = () => {
    const id = location.hash.slice(1);
    const card = document.getElementById(id);
    if (card?.classList.contains("endpoint")) {
      if (card.hidden) {
        search.value = "";
        filter.value = "all";
        updateFilter();
      }
      card.open = true;
      requestAnimationFrame(() => card.scrollIntoView({ block: "start" }));
    }
    document.querySelectorAll(".endpoint-link").forEach((link) => {
      const active = link.dataset.endpoint === id;
      link.classList.toggle("active", active);
      if (active) link.setAttribute("aria-current", "location");
      else link.removeAttribute("aria-current");
    });
  };
  window.addEventListener("hashchange", revealHash);
  // A repeated click on the current anchor should reopen a collapsed endpoint too.
  document.querySelectorAll('a[href^="#"]').forEach((link) =>
    link.addEventListener("click", () => {
      const target = document.getElementById(link.hash.slice(1));
      if (target?.classList.contains("endpoint")) target.open = true;
    }),
  );
  revealHash();
  document.querySelectorAll("[data-copy-target]").forEach((button) =>
    button.addEventListener("click", async () => {
      const text = document
        .getElementById(button.dataset.copyTarget)
        .textContent.trim();
      try {
        if (navigator.clipboard && window.isSecureContext)
          await navigator.clipboard.writeText(text);
        else {
          const field = document.createElement("textarea");
          field.value = text;
          field.style.cssText = "position:fixed;top:-9999px";
          document.body.append(field);
          field.select();
          const copied = document.execCommand("copy");
          field.remove();
          button.focus();
          if (!copied) throw new Error("Clipboard unavailable");
        }
        notify("Copied to clipboard");
      } catch {
        notify("Copy unavailable. Select and copy the example manually.");
      }
    }),
  );
  const exportButton = document.getElementById("export-docs");
  exportButton.hidden = false;
  exportButton.addEventListener("click", () => {
    const blob = new Blob(
      [
        JSON.stringify(
          { title: "XSpann API reference", version: "v1", ...reference },
          null,
          2,
        ),
      ],
      { type: "application/json" },
    );
    const url = URL.createObjectURL(blob);
    const link = document.createElement("a");
    link.href = url;
    link.download = "xspann-api-reference.json";
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    notify("API reference exported");
  });
})();
