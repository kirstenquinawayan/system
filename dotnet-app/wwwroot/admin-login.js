const form = document.querySelector("#login-form");
const errorBox = document.querySelector("#login-error");
let csrfToken = "";

async function loadCsrfToken() {
  const response = await fetch("/api/admin/csrf", { credentials: "same-origin", cache: "no-store" });
  if (!response.ok) throw new Error("Could not start a secure sign-in session. Reload the page and try again.");
  const result = await response.json();
  csrfToken = result.token;
}

form.addEventListener("submit", async (event) => {
  event.preventDefault();
  errorBox.hidden = true;
  const button = form.querySelector("button[type=submit]");
  button.disabled = true;
  button.textContent = "Signing in...";

  try {
    if (!csrfToken) await loadCsrfToken();
    const response = await fetch("/api/admin/login", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken },
      body: JSON.stringify({
        username: form.elements.username.value,
        password: form.elements.password.value
      })
    });
    if (response.ok) {
      window.location.replace("/admin.html");
      return;
    }

    errorBox.textContent = response.status === 401
      ? "The username or password is incorrect."
      : (await response.json()).message || "Sign-in failed. Please try again.";
    errorBox.hidden = false;
  } catch (error) {
    errorBox.textContent = error.message || "Sign-in is temporarily unavailable.";
    errorBox.hidden = false;
  } finally {
    button.disabled = false;
    button.textContent = "Sign in";
  }
});

loadCsrfToken().catch((error) => {
  errorBox.textContent = error.message;
  errorBox.hidden = false;
});
