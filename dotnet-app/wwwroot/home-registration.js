(() => {
  const form = document.querySelector("#home-register-form");
  if (!form) return;

  const submitButton = form.querySelector('button[type="submit"]');
  const status = document.querySelector("#home-registration-status");
  const accountLink = document.querySelector("#home-registration-account-link");

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const fields = new FormData(form);
    submitButton.disabled = true;
    form.setAttribute("aria-busy", "true");

    try {
      const result = await window.customerApi.postJson("/api/customer/register", {
        fullName: fields.get("fullName"),
        email: fields.get("email"),
        contact: fields.get("contact"),
        password: fields.get("password")
      });
      form.reset();
      status.textContent = `Account created. Welcome, ${result.name}. You are now signed in.`;
      status.dataset.error = "false";
      accountLink.hidden = false;
    } catch (error) {
      status.textContent = error.message;
      status.dataset.error = "true";
    } finally {
      submitButton.disabled = false;
      form.removeAttribute("aria-busy");
    }
  });
})();
