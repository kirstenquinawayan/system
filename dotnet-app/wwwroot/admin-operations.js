(() => {
  "use strict";

  const $ = (selector, root = document) => root.querySelector(selector);
  const notice = $("#notice");
  let csrfToken = "";
  let role = "";
  let reportArgs = null;
  const today = new Date();
  const localDate = (date) => {
    const copy = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
    return copy.toISOString().slice(0, 10);
  };
  $("#report-end").value = localDate(today);
  $("#report-start").value = localDate(new Date(today.getTime() - 29 * 86400000));

  function showMessage(message, type = "success") {
    notice.textContent = message || "The operation could not be completed.";
    notice.className = `notice ${type}`;
    notice.hidden = false;
    notice.focus?.();
  }

  function escapeHtml(value) {
    return String(value ?? "").replace(/[&<>"']/g, (char) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
    })[char]);
  }

  function dateText(value) {
    if (!value) return "—";
    const date = new Date(value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
  }

  function money(value) {
    return new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(Number(value) || 0);
  }

  function paymentMethodLabel(value) {
    return ({
      demo_online: "Demo online (no real payment)",
      paymongo_qrph: "Demo online (no real payment)",
      stripe: "Stripe test (no real payment)",
      gcash: "GCash transfer reference submitted",
      maya: "Maya transfer reference submitted",
      cash: "Cashier"
    })[value] || value || "—";
  }

  async function api(url, options = {}) {
    const headers = new Headers(options.headers || {});
    if (options.body && !(options.body instanceof FormData)) headers.set("Content-Type", "application/json");
    if (options.method && !["GET", "HEAD"].includes(options.method.toUpperCase())) {
      if (!csrfToken) await getCsrf();
      headers.set("X-CSRF-TOKEN", csrfToken);
    }
    const response = await fetch(url, { credentials: "same-origin", ...options, headers });
    const contentType = response.headers.get("content-type") || "";
    const body = contentType.includes("application/json") ? await response.json() : null;
    if (response.status === 401) {
      $("#operations").hidden = true;
      $("#signed-out").hidden = false;
      throw new Error("Your session has ended. Sign in again.");
    }
    if (!response.ok) throw new Error(body?.message || body?.title || `Request failed (${response.status}).`);
    return body;
  }

  async function getCsrf() {
    const response = await fetch("/api/admin/csrf", { credentials: "same-origin" });
    if (!response.ok) throw new Error("Could not start a secure session. Refresh and try again.");
    const result = await response.json();
    csrfToken = result.token;
  }

  async function initialize() {
    try {
      await getCsrf();
      const session = await api("/api/admin/session");
      if (!session.authenticated || !["admin", "staff"].includes(session.role)) {
        $("#signed-out").hidden = false;
        return;
      }
      role = session.role;
      $("#session-name").textContent = `${session.name} · ${role}`;
      $("#sign-out").hidden = false;
      $("#operations").hidden = false;
      if (role !== "admin") {
        document.querySelectorAll('[data-tab="users"], [data-tab="settings"]').forEach((tab) => tab.hidden = true);
        $("#panel-users").hidden = true;
        $("#panel-settings").hidden = true;
        activateTab("reports");
      } else {
        loadUsers();
        loadSettings();
      }
      loadNotifications();
    } catch (error) {
      $("#signed-out").hidden = false;
      showMessage(error.message, "error");
    }
  }

  function activateTab(name) {
    document.querySelectorAll(".tab").forEach((tab) => tab.classList.toggle("is-active", tab.dataset.tab === name));
    document.querySelectorAll(".panel").forEach((panel) => panel.hidden = panel.id !== `panel-${name}`);
    if (name === "users" && role === "admin") loadUsers();
    if (name === "notifications") loadNotifications();
  }

  document.querySelectorAll(".tab").forEach((tab) => tab.addEventListener("click", () => activateTab(tab.dataset.tab)));
  $("#sign-out").addEventListener("click", async () => {
    try {
      await api("/api/admin/logout", { method: "POST" });
      location.href = "admin-login.html";
    } catch (error) { showMessage(error.message, "error"); }
  });

  async function loadUsers() {
    if (role !== "admin") return;
    const target = $("#users-list");
    target.innerHTML = '<p class="muted">Loading users…</p>';
    try {
      const users = await api("/api/admin-operations/users");
      target.innerHTML = users.length ? users.map((user) => `
        <article class="data-card">
          <h3>${escapeHtml(user.firstName)} ${escapeHtml(user.lastName)} <span class="pill">${escapeHtml(user.role)}</span></h3>
          <p><strong>Username:</strong> ${escapeHtml(user.username)}</p>
          <p><strong>Email:</strong> ${escapeHtml(user.email || "—")}</p>
          <p><strong>Last login:</strong> ${escapeHtml(dateText(user.lastLogin))}</p>
          <div class="actions">
            <button type="button" class="button button-secondary" data-user-edit="${user.id}">Edit</button>
            <button type="button" class="button button-secondary" data-user-delete="${user.id}">Delete</button>
          </div>
        </article>`).join("") : '<p class="muted">No admin or staff accounts found.</p>';
    } catch (error) {
      target.innerHTML = "";
      showMessage(error.message, "error");
    }
  }

  function resetUserForm() {
    $("#user-form").reset();
    $("#user-id").value = "0";
    $("#user-role").value = "staff";
    $("#user-form-title").textContent = "Create user";
    $("#user-password").required = true;
    $("#password-hint").textContent = "(minimum 12 characters)";
  }

  $("#user-reset").addEventListener("click", resetUserForm);
  $("#refresh-users").addEventListener("click", loadUsers);
  $("#user-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const id = Number($("#user-id").value);
    try {
      const result = await api("/api/admin-operations/users", {
        method: "POST",
        body: JSON.stringify({
          id, firstName: $("#first-name").value, lastName: $("#last-name").value,
          username: $("#username").value, email: $("#user-email").value,
          role: $("#user-role").value, password: $("#user-password").value
        })
      });
      showMessage(result.message);
      resetUserForm();
      await loadUsers();
    } catch (error) { showMessage(error.message, "error"); }
  });

  $("#users-list").addEventListener("click", async (event) => {
    const edit = event.target.closest("[data-user-edit]");
    const remove = event.target.closest("[data-user-delete]");
    if (edit) {
      try {
        const users = await api("/api/admin-operations/users");
        const user = users.find((item) => item.id === Number(edit.dataset.userEdit));
        if (!user) throw new Error("User no longer exists.");
        $("#user-id").value = user.id;
        $("#first-name").value = user.firstName;
        $("#last-name").value = user.lastName;
        $("#username").value = user.username;
        $("#user-email").value = user.email;
        $("#user-role").value = user.role;
        $("#user-password").value = "";
        $("#user-password").required = false;
        $("#password-hint").textContent = "(minimum 12 characters; leave blank to keep current)";
        $("#user-form-title").textContent = `Edit ${user.username}`;
        $("#first-name").focus();
      } catch (error) { showMessage(error.message, "error"); }
    }
    if (remove && confirm("Delete this admin/staff account? The last administrator cannot be deleted.")) {
      try {
        await api(`/api/admin-operations/users/${Number(remove.dataset.userDelete)}`, { method: "DELETE" });
        showMessage("User deleted.");
        await loadUsers();
      } catch (error) { showMessage(error.message, "error"); }
    }
  });

  function getReportParams() {
    const params = new URLSearchParams({
      dateType: $("#report-type").value,
      startDate: $("#report-start").value,
      endDate: $("#report-end").value
    });
    return params;
  }

  $("#report-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    reportArgs = getReportParams();
    $("#report-summary").innerHTML = "";
    $("#report-details").innerHTML = '<p class="muted">Loading report…</p>';
    try {
      const report = await api(`/api/admin-operations/reports?${reportArgs}`);
      $("#report-summary").innerHTML = `
        <div class="stat"><span>Bookings</span><strong>${report.summary.bookings}</strong></div>
        <div class="stat"><span>Amount paid</span><strong>${money(report.summary.paidAmount)}</strong></div>
        <div class="stat"><span>Unpaid bookings</span><strong>${report.summary.unpaid}</strong></div>`;
      $("#report-details").innerHTML = report.details.length ? report.details.map((item) => `
        <article class="data-card">
          <h3>${escapeHtml(item.code)} · ${escapeHtml(item.customer)}</h3>
          <p>${escapeHtml(item.vehicle)} ${item.plate ? `· ${escapeHtml(item.plate)}` : ""} · ${escapeHtml(item.statusLabel)}</p>
          <p><strong>Appointment:</strong> ${escapeHtml(dateText(item.schedule))} · <strong>Booked:</strong> ${escapeHtml(dateText(item.bookedAt))}</p>
          <p><strong>Payment:</strong> ${escapeHtml(item.paymentStatus)} ${escapeHtml(item.paymentMethod || "")} · Paid: ${money(item.amountPaid)} · Total: ${money(item.total)}</p>
          <p><strong>Reservation fee:</strong> ${escapeHtml(item.reservationFeeStatus)} ${money(item.reservationFee)}</p>
        </article>`).join("") : '<p class="muted">No bookings match this date range.</p>';
    } catch (error) {
      $("#report-details").innerHTML = "";
      showMessage(error.message, "error");
    }
  });
  $("#export-report").addEventListener("click", () => {
    if (!$("#report-form").reportValidity()) return;
    const params = getReportParams();
    location.href = `/api/admin-operations/reports.csv?${params}`;
  });

  $("#booking-search").addEventListener("submit", async (event) => {
    event.preventDefault();
    await loadBooking(Number($("#booking-id").value));
  });

  async function loadBooking(id) {
    const target = $("#booking-detail");
    target.hidden = false;
    target.innerHTML = "<p>Loading booking details…</p>";
    try {
      const data = await api(`/api/admin-operations/bookings/${id}`);
      const b = data.booking;
      target.innerHTML = `
        <div class="section-title"><div><p class="eyebrow">Booking ${escapeHtml(b.code)}</p><h2>${escapeHtml(b.customer)}</h2></div>
          <span class="pill">${escapeHtml(b.statusLabel)}${b.isArchived ? " · archived" : ""}</span></div>
        <div class="detail-grid">
          <p><strong>ID:</strong> ${b.id}</p><p><strong>Vehicle:</strong> ${escapeHtml(b.vehicle)} · ${escapeHtml(b.plateNumber || "No plate")}</p>
          <p><strong>Appointment:</strong> ${escapeHtml(dateText(b.schedule))}</p><p><strong>Created:</strong> ${escapeHtml(dateText(b.createdAt))}</p>
          <p><strong>Contact:</strong> ${escapeHtml(b.contact)} · ${escapeHtml(b.email)}</p><p><strong>Address:</strong> ${escapeHtml(b.address)}</p>
          <p><strong>Total:</strong> ${money(b.total)} · <strong>Reservation fee:</strong> ${money(b.reservationFee)}</p>
          <p><strong>Payment:</strong> ${escapeHtml(b.paymentStatus)} · ${escapeHtml(paymentMethodLabel(b.paymentMethod))} · ${escapeHtml(dateText(b.paymentPaidAt))}</p>
          <p><strong>Received by:</strong> ${escapeHtml(b.paymentReceivedByName || "—")}</p>
          <p><strong>Reservation fee:</strong> ${escapeHtml(b.reservationFeeStatus)} · ${escapeHtml(paymentMethodLabel(b.reservationFeeMethod))} · ${escapeHtml(b.reservationFeeReference)}</p>
          <p><strong>Verified:</strong> ${escapeHtml(dateText(b.reservationFeeVerifiedAt))} by ${escapeHtml(b.reservationFeeVerifiedBy || "—")} · <strong>Rejected:</strong> ${escapeHtml(dateText(b.reservationFeeRejectedAt))} by ${escapeHtml(b.reservationFeeRejectedBy || "—")}</p>
          <p><strong>Refund:</strong> ${escapeHtml(b.reservationFeeRefundReference || "—")} · ${escapeHtml(dateText(b.reservationFeeRefundedAt))} by ${escapeHtml(b.reservationFeeRefundedBy || "—")}</p>
          <p><strong>Cash balance:</strong> ${money(b.cashBalance)}</p>
        </div>
        <div class="detail-section"><h3>Services</h3>${data.services.length ? data.services.map((s) =>
          `<p>${escapeHtml(s.name)} · ${money(s.price)} · ${s.durationMinutes} min</p>`).join("") : '<p class="muted">No service rows.</p>'}</div>
        <div class="detail-section"><h3>Online payments / transfer reviews</h3>${data.onlinePayments.length ? data.onlinePayments.map((p) => `
          <article class="data-card"><p><strong>${escapeHtml(p.type)} · ${escapeHtml(p.provider)}</strong> · ${escapeHtml(p.status)} · ${money(p.amount)}</p>
          <p>Reference: ${escapeHtml(p.reference || "—")} · Submitted ${escapeHtml(dateText(p.createdAt))}</p>
          <p>Verified ${escapeHtml(dateText(p.verifiedAt))} · Rejected ${escapeHtml(dateText(p.rejectedAt))}</p>
          ${p.status === "pending_verification" ? `<div class="actions"><button class="button button-primary" data-payment="verify" data-payment-id="${p.id}">Verify transfer</button><button class="button button-secondary" data-payment="reject" data-payment-id="${p.id}">Reject transfer</button></div>` : ""}
          </article>`).join("") : '<p class="muted">No online submissions.</p>'}</div>
        <div class="detail-section"><h3>Payment ledger</h3>${data.paymentAttempts.length ? data.paymentAttempts.map((p) =>
          `<p>${escapeHtml(p.provider)} · ${escapeHtml(p.status)} · ${money(p.amount)} ${escapeHtml(p.currency)} · recorded ${escapeHtml(dateText(p.createdAt))} · paid ${escapeHtml(dateText(p.paidAt))}</p>`).join("")
          : '<p class="muted">No C# payment ledger entries.</p>'}</div>
        <div class="detail-section"><h3>OTP / verification status</h3>${data.otpVerification.length ? data.otpVerification.map((item) =>
          `<p>${escapeHtml(item.status)} · attempts ${item.attempts} · expires ${escapeHtml(dateText(item.expiresAt))} · verified ${escapeHtml(dateText(item.verifiedAt))}</p>`).join("") : '<p class="muted">No OTP verification records.</p>'}</div>
        <div class="detail-section"><h3>Actions</h3><div class="actions">
          ${!b.isArchived && b.paymentStatus === "unpaid" && b.status !== 5 ? '<button class="button button-primary" data-booking-action="cash">Record cash payment</button>' : ""}
          ${b.paymentStatus === "paid" && b.paymentMethod === "cash" ? `<a class="button button-secondary" href="/api/admin/receipts/${b.id}/cash.pdf" target="_blank" rel="noopener">Download cash receipt</a>` : ""}
          ${!b.isArchived && b.reservationFeeStatus === "pending_verification" ? '<button class="button button-primary" data-fee-action="verify">Verify reservation fee</button><button class="button button-secondary" data-fee-action="reject">Reject reservation fee</button>' : ""}
          ${!b.isArchived && b.status === 5 && b.paymentStatus === "unpaid" && b.reservationFeeStatus === "paid" ? '<button class="button button-secondary" data-fee-action="refund">Record reservation-fee refund</button>' : ""}
          ${b.isArchived ? '<button class="button button-primary" data-booking-action="restore">Restore booking</button>' : '<button class="button button-secondary" data-booking-action="archive">Archive booking</button>'}
          ${!b.isArchived && b.status === 0 ? '<button class="button button-secondary" data-booking-action="approve">Approve booking</button><button class="button button-secondary" data-booking-action="cancel">Cancel booking</button>' : ""}
        </div></div>`;
      target.dataset.currentId = String(id);
    } catch (error) {
      target.innerHTML = `<p class="muted">${escapeHtml(error.message)}</p>`;
      showMessage(error.message, "error");
    }
  }

  $("#booking-detail").addEventListener("click", async (event) => {
    const button = event.target.closest("button");
    if (!button) return;
    const id = Number($("#booking-detail").dataset.currentId);
    try {
      if (button.dataset.payment) {
        if (!confirm(`${button.dataset.payment === "verify" ? "Verify" : "Reject"} this transfer after checking the payment provider?`)) return;
        await api(`/api/admin-operations/online-payments/${Number(button.dataset.paymentId)}/${button.dataset.payment}`, { method: "POST" });
      } else if (button.dataset.feeAction) {
        let body = {};
        if (button.dataset.feeAction === "refund") {
          const refundReference = prompt("Enter the completed refund transaction reference:");
          if (refundReference === null) return;
          body.refundReference = refundReference;
        }
        if (!confirm("Confirm this reservation-fee action?")) return;
        await api(`/api/admin-operations/bookings/${id}/reservation-fee/${button.dataset.feeAction}`, {
          method: "POST", body: JSON.stringify(body)
        });
      } else if (button.dataset.bookingAction) {
        const action = button.dataset.bookingAction;
        if (action !== "cash" && !confirm(`${action} booking ${id}?`)) return;
        if (action === "cash") {
          if (!confirm("Confirm that the full cash payment was received at the shop?")) return;
          await api(`/api/admin/payments/${id}/cash`, { method: "POST" });
        }
        if (action === "archive" || action === "restore")
          await api(`/api/admin/bookings/${id}/${action}`, { method: "POST" });
        if (action === "approve" || action === "cancel")
          await api(`/api/admin-operations/bookings/${id}/notifications/${action}`, { method: "POST" });
      }
      showMessage("Booking operation completed.");
      await loadBooking(id);
    } catch (error) { showMessage(error.message, "error"); }
  });

  async function loadNotifications() {
    if (role !== "admin" && role !== "staff") return;
    try {
      const queue = await api("/api/admin-operations/notifications");
      $("#recovery-queue").innerHTML = queue.passwordRecovery.length ? queue.passwordRecovery.map((item) => `
        <article class="data-card"><h3>${escapeHtml(item.customer)}</h3>
          <p>${escapeHtml(item.email)} · ${escapeHtml(item.contact)}</p><p>Requested ${escapeHtml(dateText(item.requestedAt))}</p>
          <div class="actions"><button class="button button-primary" data-recovery="approve" data-id="${item.id}">Approve 30 min</button>
            <button class="button button-secondary" data-recovery="reject" data-id="${item.id}">Decline</button></div></article>`).join("")
        : '<p class="muted">No pending password recovery requests.</p>';
      $("#customer-bookings").innerHTML = queue.customerBookings.length ? queue.customerBookings.map((item) => `
        <article class="data-card"><h3>${escapeHtml(item.code)} · ${escapeHtml(item.customer)}</h3>
          <p>${escapeHtml(item.contact)} · ${escapeHtml(dateText(item.schedule))} · ${escapeHtml(item.statusLabel)}</p>
          ${item.status === 0 ? `<div class="actions"><button class="button button-primary" data-booking-decision="approve" data-id="${item.id}">Approve</button>
            <button class="button button-secondary" data-booking-decision="cancel" data-id="${item.id}">Cancel</button></div>` : '<p class="muted">Cancellation is visible to the customer.</p>'}</article>`).join("")
        : '<p class="muted">No customer bookings are waiting for approval or showing cancellation updates.</p>';
    } catch (error) {
      $("#recovery-queue").innerHTML = "";
      $("#customer-bookings").innerHTML = "";
      showMessage(error.message, "error");
    }
  }

  $("#refresh-queue").addEventListener("click", loadNotifications);
  $("#panel-notifications").addEventListener("click", async (event) => {
    const recovery = event.target.closest("[data-recovery]");
    const booking = event.target.closest("[data-booking-decision]");
    try {
      if (recovery) {
        if (!confirm(`${recovery.dataset.recovery} this customer password-recovery request?`)) return;
        await api(`/api/admin-operations/notifications/password-recovery/${Number(recovery.dataset.id)}/${recovery.dataset.recovery}`, { method: "POST" });
      }
      if (booking) {
        if (!confirm(`${booking.dataset.bookingDecision} this customer booking?`)) return;
        await api(`/api/admin-operations/bookings/${Number(booking.dataset.id)}/notifications/${booking.dataset.bookingDecision}`, { method: "POST" });
      }
      if (recovery || booking) {
        showMessage("Decision saved.");
        await loadNotifications();
      }
    } catch (error) { showMessage(error.message, "error"); }
  });

  async function loadSettings() {
    if (role !== "admin") return;
    try {
      const settings = await api("/api/admin-operations/settings");
      $("#site-name").value = settings.name;
      $("#site-short-name").value = settings.shortName;
      $("#site-about").value = settings.aboutUs;
      const images = [];
      if (settings.logo) images.push({ kind: "logo", path: settings.logo });
      if (settings.cover) images.push({ kind: "cover", path: settings.cover });
      for (const path of settings.banners || []) images.push({ kind: "banner", path });
      $("#image-list").innerHTML = images.length ? images.map((image) => {
        const filename = image.path.split("/").pop().split("?")[0];
        return `<div class="image-item"><img src="/${escapeHtml(image.path)}" alt="${escapeHtml(image.kind)} preview">
          <p>${escapeHtml(image.kind)} · ${escapeHtml(filename)}</p>
          <button type="button" class="button button-secondary" data-image-kind="${escapeHtml(image.kind)}" data-image-name="${escapeHtml(filename)}">Remove</button></div>`;
      }).join("") : '<p class="muted">No logo, cover, or banner images are configured.</p>';
    } catch (error) { showMessage(error.message, "error"); }
  }

  $("#settings-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    try {
      const result = await api("/api/admin-operations/settings", {
        method: "PUT",
        body: JSON.stringify({
          name: $("#site-name").value, shortName: $("#site-short-name").value, aboutUs: $("#site-about").value
        })
      });
      showMessage(result.message);
    } catch (error) { showMessage(error.message, "error"); }
  });

  document.querySelectorAll(".upload-form").forEach((form) => form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const input = $("input[type=file]", form);
    if (!input.files.length) return;
    const body = new FormData();
    for (const file of input.files) body.append("files", file);
    try {
      const result = await api(`/api/admin-operations/settings/images/${form.dataset.kind}`, { method: "POST", body });
      showMessage(result.message);
      form.reset();
      await loadSettings();
    } catch (error) { showMessage(error.message, "error"); }
  }));
  $("#image-list").addEventListener("click", async (event) => {
    const button = event.target.closest("[data-image-name]");
    if (!button || !confirm(`Remove this ${button.dataset.imageKind} image?`)) return;
    try {
      await api(`/api/admin-operations/settings/images/${button.dataset.imageKind}/${encodeURIComponent(button.dataset.imageName)}`, { method: "DELETE" });
      showMessage("Image removed.");
      await loadSettings();
    } catch (error) { showMessage(error.message, "error"); }
  });

  initialize();
})();
