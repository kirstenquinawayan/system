(() => {
  const status = document.querySelector("#account-status");
  const signedOut = document.querySelector("#signed-out");
  const signedIn = document.querySelector("#signed-in");
  const resetSection = document.querySelector("#reset-section");
  const bookingsList = document.querySelector("#bookings-list");
  const notificationsList = document.querySelector("#notifications-list");
  const { getJson, postJson, loadCsrfToken } = window.customerApi;
  let recoveryToken = "";

  function setStatus(message, isError = false) {
    status.textContent = message;
    status.dataset.error = isError ? "true" : "false";
  }

  function createElement(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  }

  function formatDate(value) {
    return new Intl.DateTimeFormat(undefined, {
      dateStyle: "medium",
      timeStyle: "short",
      timeZone: "Asia/Manila"
    }).format(new Date(value));
  }

  function formatMoney(value) {
    return new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(value);
  }

  function emptyList(target, message) {
    target.replaceChildren(createElement("p", "empty-account-list", message));
  }

  async function loadBookings() {
    bookingsList.classList.add("is-loading");
    try {
      const bookings = await getJson("/api/customer/bookings");
      bookingsList.replaceChildren();
      if (!bookings.length) {
        emptyList(bookingsList, "No bookings are linked to your account yet. Sign in before booking to see future appointments here.");
        return;
      }

      for (const booking of bookings) {
        const item = createElement("article", "account-list-item");
        const summary = createElement("div");
        summary.append(
          createElement("h3", "", `${booking.code} · ${booking.statusLabel}`),
          createElement("p", "", `${formatDate(booking.schedule)} · ${booking.vehicle || "Vehicle"}`),
          createElement("p", "", `${formatMoney(booking.total)} · ${booking.paymentStatus}${booking.archived ? " · Archived" : ""}`),
          createElement("p", "", `Estimated service duration: ${booking.totalDurationMinutes} minutes${booking.serviceStartedAt ? ` · Started ${formatDate(booking.serviceStartedAt)}` : ""}`)
        );
        const actions = createElement("div", "item-actions");
        if (booking.receiptToken) {
          const paymentLink = createElement("a", "secondary-button", "Payment and receipt");
          paymentLink.href = `/payment.html?token=${encodeURIComponent(booking.receiptToken)}`;
          actions.append(paymentLink);
        }
        const detailsButton = createElement("button", "secondary-button", "View details");
        detailsButton.type = "button";
        detailsButton.addEventListener("click", () => loadBookingDetails(booking.id, item, detailsButton));
        const dismissButton = createElement("button", "secondary-button", "Remove from history");
        dismissButton.type = "button";
        dismissButton.addEventListener("click", () => dismissBooking(booking.id, item, dismissButton));
        actions.append(detailsButton, dismissButton);
        item.append(summary, actions);
        bookingsList.append(item);
      }
    } catch (error) {
      emptyList(bookingsList, error.message);
    } finally {
      bookingsList.classList.remove("is-loading");
    }
  }

  async function loadBookingDetails(bookingId, item, button) {
    const previous = item.querySelector(".booking-detail");
    if (previous) {
      previous.remove();
      button.textContent = "View details";
      return;
    }

    async function dismissBooking(bookingId, item, button) {
      if (!window.confirm("Remove this booking from your account history? This will not cancel the appointment.")) return;
      button.disabled = true;
      try {
        await postJson(`/api/customer/bookings/${encodeURIComponent(bookingId)}/dismiss`);
        item.remove();
        if (!bookingsList.querySelector(".account-list-item")) {
          emptyList(bookingsList, "No bookings are linked to your account yet.");
        }
        setStatus("The booking was removed from your history. The appointment was not cancelled.");
      } catch (error) {
        setStatus(error.message, true);
        button.disabled = false;
      }
    }

    async function loadAdminRecoveryStatus() {
      const status = document.querySelector("#admin-recovery-status");
      const resetForm = document.querySelector("#admin-recovery-complete-form");
      try {
        const data = await getJson("/api/customer/recovery/admin-status");
        const request = data.request;
        resetForm.hidden = !request?.approvalActive;
        if (!request) {
          status.textContent = "No recent admin password-reset request.";
        } else if (request.approvalActive) {
          status.textContent = `Approved until ${formatDate(request.expiresAt)}.`;
        } else if (request.status === "pending") {
          status.textContent = "Your request is waiting for admin review.";
        } else if (request.status === "rejected") {
          status.textContent = "Your latest request was declined. You can submit a new request.";
        } else if (request.status === "completed") {
          status.textContent = "Your approved password reset is complete.";
        } else {
          status.textContent = "Your previous approval has expired. You can submit a new request.";
        }
      } catch (error) {
        status.textContent = error.message;
        status.dataset.error = "true";
      }
    }

    button.disabled = true;
    try {
      const response = await getJson(`/api/customer/bookings/${encodeURIComponent(bookingId)}`);
      const detail = createElement("div", "booking-detail");
      const booking = response.booking;
      detail.append(
        createElement("p", "", `${booking.name} · ${booking.contact}`),
        createElement("p", "", `${booking.plateNumber} · ${booking.email}`),
        createElement("p", "", booking.address),
        createElement("p", "", `Reservation fee: ${formatMoney(booking.reservationFee)} · ${booking.reservationFeePaymentStatus}`)
      );
      const services = createElement("ul");
      for (const service of response.services) {
        services.append(createElement("li", "", `${service.name} · ${formatMoney(service.price)} · ${service.durationMinutes} min`));
      }
      detail.append(services);
      item.append(detail);
      button.textContent = "Hide details";
    } catch (error) {
      setStatus(error.message, true);
    } finally {
      button.disabled = false;
    }
  }

  async function loadNotifications() {
    notificationsList.classList.add("is-loading");
    try {
      const notifications = await getJson("/api/customer/notifications");
      notificationsList.replaceChildren();
      if (!notifications.length) {
        emptyList(notificationsList, "You have no new booking notifications.");
        return;
      }

      for (const notification of notifications) {
        const item = createElement("article", `account-list-item${notification.read ? "" : " notification-unread"}`);
        const summary = createElement("div");
        summary.append(
          createElement("h3", "", notification.message),
          createElement("p", "", `Booking #${notification.bookingId} · ${formatDate(notification.appointmentAt)}`),
          createElement("p", "", `${formatDate(notification.createdAt)} · ${notification.read ? "Read" : "Unread"}`)
        );
        const actions = createElement("div", "item-actions");
        if (!notification.read) {
          const readButton = createElement("button", "secondary-button", "Mark read");
          readButton.type = "button";
          readButton.addEventListener("click", () => updateNotification(notification.id, "read"));
          actions.append(readButton);
        }
        const dismissButton = createElement("button", "secondary-button", "Dismiss");
        dismissButton.type = "button";
        dismissButton.addEventListener("click", () => updateNotification(notification.id, "dismiss"));
        actions.append(dismissButton);
        item.append(summary, actions);
        notificationsList.append(item);
      }
    } catch (error) {
      emptyList(notificationsList, error.message);
    } finally {
      notificationsList.classList.remove("is-loading");
    }
  }

  async function updateNotification(id, action) {
    try {
      await postJson(`/api/customer/notifications/${encodeURIComponent(id)}/${action}`);
      await loadNotifications();
      setStatus(action === "read" ? "Notification marked as read." : "Notification dismissed.");
    } catch (error) {
      setStatus(error.message, true);
    }
  }

  async function refreshSession() {
    const session = await getJson("/api/customer/session");
    signedOut.hidden = session.authenticated || Boolean(recoveryToken);
    signedIn.hidden = !session.authenticated || Boolean(recoveryToken);
    resetSection.hidden = !recoveryToken;
    if (session.authenticated) {
      document.querySelector("#customer-name").textContent = session.name || "Customer";
      document.querySelector("#customer-email").textContent = session.email || "";
      await Promise.all([loadBookings(), loadNotifications(), loadAdminRecoveryStatus()]);
    }
  }

  document.querySelector("#login-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    try {
      const result = await postJson("/api/customer/login", {
        email: fields.get("email"),
        password: fields.get("password")
      });
      form.reset();
      setStatus(`Signed in as ${result.name}.`);
      await refreshSession();
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  document.querySelector("#register-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    try {
      const result = await postJson("/api/customer/register", {
        fullName: fields.get("fullName"),
        email: fields.get("email"),
        contact: fields.get("contact"),
        password: fields.get("password")
      });
      form.reset();
      setStatus(`Account created. Welcome, ${result.name}.`);
      await refreshSession();
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  document.querySelector("#recovery-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      await postJson("/api/customer/recovery", { email: new FormData(form).get("email") });
      form.reset();
      setStatus("If an account matches that email address, a recovery link will be sent shortly.");
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  document.querySelector("#sms-recovery-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      await postJson("/api/customer/recovery/sms", { contact: new FormData(form).get("contact") });
      form.reset();
      setStatus("If an account matches that mobile number, a recovery code will be sent shortly.");
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  document.querySelector("#sms-reset-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    try {
      const result = await postJson("/api/customer/recovery/sms/complete", {
        contact: fields.get("contact"),
        code: fields.get("code"),
        password: fields.get("password"),
        confirmPassword: fields.get("confirmPassword")
      });
      form.reset();
      setStatus(result.message);
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  document.querySelector("#admin-recovery-request-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    try {
      const result = await postJson("/api/customer/recovery/admin-request");
      setStatus(result.message);
      await loadAdminRecoveryStatus();
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  document.querySelector("#admin-recovery-complete-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    try {
      const result = await postJson("/api/customer/recovery/admin-complete", {
        password: fields.get("password"),
        confirmPassword: fields.get("confirmPassword")
      });
      form.reset();
      await refreshSession();
      setStatus(result.message);
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  document.querySelector("#reset-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    try {
      const result = await postJson("/api/customer/recovery/complete", {
        token: recoveryToken,
        password: new FormData(form).get("password")
      });
      form.reset();
      recoveryToken = "";
      history.replaceState(null, "", `${location.pathname}${location.search}`);
      await refreshSession();
      setStatus(result.message);
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  document.querySelector("#change-password-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const fields = new FormData(form);
    try {
      const result = await postJson("/api/customer/password", {
        currentPassword: fields.get("currentPassword"),
        newPassword: fields.get("newPassword")
      });
      form.reset();
      setStatus(result.message);
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  document.querySelector("#logout-button").addEventListener("click", async () => {
    try {
      await postJson("/api/customer/logout");
      setStatus("You have signed out.");
      await refreshSession();
    } catch (error) {
      setStatus(error.message, true);
    }
  });

  async function initialize() {
    const tokenMatch = location.hash.match(/^#reset=([a-f\d]{64})$/i);
    if (tokenMatch) {
      recoveryToken = tokenMatch[1];
      history.replaceState(null, "", `${location.pathname}${location.search}`);
    }

    try {
      await loadCsrfToken();
      await refreshSession();
    } catch (error) {
      setStatus(error.message, true);
    }
  }

  initialize();
})();
