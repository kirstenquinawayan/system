(() => {
  const form = document.querySelector("#payment-form");
  const verificationForm = document.querySelector("#sms-verification-form");
  const checkoutForm = document.querySelector("#checkout-form");
  const tokenInput = document.querySelector("#receipt-token");
  const sendCodeButton = document.querySelector("#send-code-button");
  const verifyCodeButton = document.querySelector("#verify-code-button");
  const payButton = document.querySelector("#pay-button");
  const message = document.querySelector("#payment-message");
  const receiptLinks = document.querySelector("#receipt-links");
  const confirmationLink = document.querySelector("#confirmation-link");
  const paymentReceiptLink = document.querySelector("#payment-receipt-link");
  let csrfToken = "";
  const params = new URLSearchParams(window.location.search);
  const token = params.get("token");

  function showMessage(text, kind = "") {
    message.textContent = text;
    message.dataset.kind = kind;
  }

  function currentToken() {
    return tokenInput.value.trim();
  }

  function updateReceiptLinks() {
    const receiptToken = currentToken();
    const isValid = /^[a-f0-9]{64}$/.test(receiptToken);
    receiptLinks.hidden = !isValid;
    if (isValid) {
      confirmationLink.href = `/api/public/receipts/${receiptToken}/confirmation.pdf`;
      paymentReceiptLink.href = `/api/public/receipts/${receiptToken}/reservation.pdf`;
    }
  }

  async function getCsrfToken() {
    const response = await fetch("/api/public/payments/csrf", {
      credentials: "same-origin",
      cache: "no-store"
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || typeof result.token !== "string" || !result.token) {
      throw new Error(result.message || "Could not start a secure payment session. Refresh and retry.");
    }
    csrfToken = result.token;
  }

  async function post(url, body = {}) {
    if (!csrfToken) await getCsrfToken();
    const response = await fetch(url, {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-TOKEN": csrfToken,
        "Accept": "application/json"
      },
      body: JSON.stringify(body)
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(result.message || result.title || "The payment request could not be completed.");
    }
    return result;
  }

  function clearVerification() {
    verificationForm.hidden = true;
    checkoutForm.hidden = true;
    document.querySelector("#sms-code").value = "";
  }

  if (token && /^[a-f0-9]{64}$/.test(token)) {
    tokenInput.value = token;
    updateReceiptLinks();
  }

  if (params.get("result") === "success") {
    showMessage("Checkout returned successfully. Payment is finalized only after Stripe's signed notification is verified; the payment receipt may take a short time to become available.", "success");
  } else if (params.get("result") === "cancelled") {
    showMessage("Checkout was cancelled. No payment has been recorded.");
  }

  tokenInput.addEventListener("input", () => {
    clearVerification();
    updateReceiptLinks();
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!form.reportValidity()) return;

    const receiptToken = currentToken();
    if (!/^[a-f0-9]{64}$/.test(receiptToken)) {
      showMessage("Enter the 64-character receipt token from your booking confirmation.", "error");
      return;
    }

    sendCodeButton.disabled = true;
    showMessage("Sending the verification code…");
    try {
      const result = await post(`/api/public/payments/${encodeURIComponent(receiptToken)}/sms-code`);
      verificationForm.hidden = false;
      checkoutForm.hidden = true;
      showMessage(result.message || "A verification code has been sent.", "success");
      document.querySelector("#sms-code").focus();
    } catch (error) {
      showMessage(error instanceof Error ? error.message : "SMS verification is temporarily unavailable.", "error");
    } finally {
      sendCodeButton.disabled = false;
    }
  });

  verificationForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!verificationForm.reportValidity()) return;

    const receiptToken = currentToken();
    verifyCodeButton.disabled = true;
    showMessage("Verifying the mobile number…");
    try {
      const result = await post(
        `/api/public/payments/${encodeURIComponent(receiptToken)}/sms-verify`,
        { code: document.querySelector("#sms-code").value.trim() }
      );
      verificationForm.hidden = true;
      checkoutForm.hidden = false;
      showMessage(result.message || "Mobile number verified. You can continue to checkout.", "success");
      payButton.focus();
    } catch (error) {
      showMessage(error instanceof Error ? error.message : "The code could not be verified.", "error");
    } finally {
      verifyCodeButton.disabled = false;
    }
  });

  checkoutForm.addEventListener("submit", async (event) => {
    event.preventDefault();
    const receiptToken = currentToken();
    payButton.disabled = true;
    showMessage("Preparing secure checkout…");
    try {
      const result = await post(`/api/public/payments/${encodeURIComponent(receiptToken)}/checkout`);
      if (typeof result.checkoutUrl !== "string") {
        throw new Error("Checkout is temporarily unavailable.");
      }

      window.location.assign(result.checkoutUrl);
    } catch (error) {
      showMessage(error instanceof Error ? error.message : "Checkout is temporarily unavailable.", "error");
      payButton.disabled = false;
    }
  });
})();
