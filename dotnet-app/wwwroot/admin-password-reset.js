(() => {
  "use strict";
  const $ = (selector) => document.querySelector(selector);
  const notice = $("#recovery-notice");
  let csrfToken = "";
  let csrfPromise;
  let resetToken = new URLSearchParams(location.search).get("token") || "";

  function show(message, error = false) {
    notice.textContent = message;
    notice.className = `notice${error ? " error" : ""}`;
    notice.hidden = false;
  }

  async function getCsrf() {
    const response = await fetch("/api/admin/csrf", { credentials: "same-origin" });
    if (!response.ok) throw new Error("Could not start a secure session. Refresh and retry.");
    csrfToken = (await response.json()).token;
  }

  async function post(url, body) {
    if (!csrfToken && !(await csrfPromise)) throw new Error("Could not start a secure session. Refresh and retry.");
    const response = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken },
      body: JSON.stringify(body)
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.message || result.title || "The request could not be completed.");
    return result;
  }

  const hasToken = /^[a-f0-9]{64}$/.test(resetToken);
  $("#page-title").textContent = hasToken ? "Choose a new password" : "Forgot your password?";
  $("#page-description").textContent = hasToken
    ? "Reset links can be used once and expire after one hour."
    : "Enter your username or recovery email. For privacy, the response is the same whether or not an account matches.";
  $("#request-form").hidden = hasToken;
  $("#reset-form").hidden = !hasToken;
  if (hasToken) history.replaceState(null, "", location.pathname);
  else resetToken = "";

  csrfPromise = getCsrf().then(() => true).catch((error) => {
    show(error.message, true);
    return false;
  });

  $("#request-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    try {
      const result = await post("/api/admin-password-reset/request", { identifier: $("#identifier").value });
      show(result.message);
      $("#request-form").reset();
    } catch (error) { show(error.message, true); }
  });

  $("#reset-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    if ($("#new-password").value !== $("#confirm-password").value) {
      show("The passwords must match.", true);
      return;
    }
    try {
      const result = await post("/api/admin-password-reset/reset", {
        token: resetToken, password: $("#new-password").value, confirmPassword: $("#confirm-password").value
      });
      show(result.message);
      $("#reset-form").reset();
      resetToken = "";
      window.setTimeout(() => { location.href = "admin-login.html"; }, 1200);
    } catch (error) { show(error.message, true); }
  });
})();
