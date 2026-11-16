(() => {
  let csrfToken = "";

  async function getJson(url) {
    const response = await fetch(url, { credentials: "same-origin", cache: "no-store" });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || data.title || "Something went wrong. Please try again.");
    return data;
  }

  async function loadCsrfToken() {
    const data = await getJson("/api/customer/csrf");
    csrfToken = data.token || "";
    if (!csrfToken) throw new Error("Could not start a secure session. Refresh the page and try again.");
  }

  async function postJson(url, payload = {}) {
    if (!csrfToken) await loadCsrfToken();
    const response = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken },
      body: JSON.stringify(payload)
    });
    const data = response.status === 204 ? {} : await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.message || data.title || "Something went wrong. Please try again.");
    return data;
  }

  window.customerApi = { getJson, postJson, loadCsrfToken };
})();
