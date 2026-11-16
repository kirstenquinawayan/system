const state = { csrf: "", role: "", catalog: null };
const errorBox = document.querySelector("#page-error");
const successBox = document.querySelector("#page-success");
let advancingDueBookings = false;
let dueBookingsInterval = null;

function showNotice(message, success = false) {
  errorBox.hidden = true;
  successBox.hidden = true;
  const box = success ? successBox : errorBox;
  box.textContent = message;
  box.hidden = false;
}

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
  })[character]);
}

async function request(path, options = {}) {
  const headers = new Headers(options.headers || {});
  if (options.body) headers.set("Content-Type", "application/json");
  if (options.method && options.method !== "GET") headers.set("X-CSRF-TOKEN", state.csrf);
  const response = await fetch(path, {
    ...options,
    headers,
    credentials: "same-origin",
    cache: "no-store"
  });
  if (response.status === 401) {
    window.location.replace("/admin-login.html");
    throw new Error("Your session has ended. Sign in again.");
  }
  if (response.status === 204) return null;
  const result = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(result.message || result.title || "The request could not be completed.");
  return result;
}

async function loadCsrf() {
  const result = await request("/api/admin/csrf");
  state.csrf = result.token;
}

async function loadSummary() {
  const data = await request("/api/admin/summary");
  document.querySelector("#stat-total").textContent = data.total;
  document.querySelector("#stat-pending").textContent = data.pending;
  document.querySelector("#stat-active").textContent = data.active;
  document.querySelector("#stat-completed").textContent = data.completed;
  document.querySelector("#stat-unpaid").textContent = data.unpaid;
}

async function advanceDueBookings() {
  if (advancingDueBookings || document.hidden || !state.csrf) return;
  advancingDueBookings = true;
  try {
    const result = await request("/api/admin/bookings/advance-due", { method: "POST" });
    if (result.updated > 0) {
      await loadSummary();
      if (!document.querySelector("#bookings-panel").hidden) await loadBookings();
    }
  } finally {
    advancingDueBookings = false;
  }
}

function stopDueBookingsInterval(error) {
  showNotice(`Could not automatically progress due bookings. ${error.message}`);
  if (dueBookingsInterval !== null) {
    window.clearInterval(dueBookingsInterval);
    dueBookingsInterval = null;
  }
}

function statusName(status) {
  return ({ 0: "Pending", 1: "Confirmed", 2: "Arrived", 3: "In progress", 4: "Completed", 5: "Cancelled" })[status] || "Unknown";
}

function statusClass(status) {
  if (status === 0) return "status-pending";
  if (status === 1 || status === 4) return "status-confirmed";
  if (status === 2 || status === 3) return "status-active";
  return "status-cancelled";
}

async function loadBookings() {
  const archived = document.querySelector("#booking-view").value === "archived";
  const bookings = await request(`/api/admin/bookings?archived=${archived}`);
  const rows = document.querySelector("#booking-rows");
  if (!bookings.length) {
    rows.innerHTML = '<tr><td colspan="7" class="empty-state">No bookings found.</td></tr>';
    return;
  }

  rows.innerHTML = bookings.map((booking) => {
    const schedule = new Date(booking.schedule).toLocaleString(undefined, {
      dateStyle: "medium", timeStyle: "short"
    });
    const actions = archived
      ? `<div class="row-actions"><button class="button button-secondary" data-restore="${booking.id}" type="button">Restore</button></div>`
      : `<div class="row-actions">
        ${booking.status === 0 ? `<button class="button button-secondary" data-status="1" data-id="${booking.id}" type="button">Confirm</button>` : ""}
        ${booking.status < 4 && booking.status !== 5 ? `<button class="button button-secondary" data-status="5" data-id="${booking.id}" type="button">Cancel</button>` : ""}
        <button class="button button-secondary" data-archive="${booking.id}" type="button">Archive</button>
      </div>`;
    return `<tr>
      <td data-label="Reference / customer"><span class="cell-title">${escapeHtml(booking.code)}</span><span class="cell-subtitle">${escapeHtml(booking.customer)} · ${escapeHtml(booking.contact)}</span></td>
      <td data-label="Vehicle"><span class="cell-title">${escapeHtml(booking.vehicle)}</span><span class="cell-subtitle">${escapeHtml(booking.plateNumber)}</span></td>
      <td data-label="Appointment">${escapeHtml(schedule)}</td>
      <td data-label="Total">PHP ${Number(booking.total).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}</td>
      <td data-label="Status"><span class="status-pill ${statusClass(booking.status)}">${statusName(booking.status)}</span></td>
      <td data-label="Payment"><span class="status-pill ${booking.paymentStatus === "paid" ? "status-paid" : "status-unpaid"}">${escapeHtml(booking.paymentStatus)}</span></td>
      <td data-label="Actions">${actions || "—"}</td>
    </tr>`;
  }).join("");
}

async function loadCatalog() {
  state.catalog = await request("/api/admin/catalog");
  renderCatalog();
}

function renderCatalog() {
  const { services, vehicles, prices } = state.catalog;
  document.querySelector("#service-rows").innerHTML = services.map((service) => `
    <tr>
      <td data-label="Service"><span class="cell-title">${escapeHtml(service.name)}</span><span class="cell-subtitle">${escapeHtml(service.description)}</span></td>
      <td data-label="Duration">${service.durationMinutes} min</td>
      <td data-label="State">${service.isActive ? "Active" : "Inactive"}</td>
      <td data-label="Actions"><div class="row-actions">
        <button class="button button-secondary" data-edit-service="${service.id}" type="button">Edit</button>
        <button class="button button-secondary" data-archive-service="${service.id}" type="button">Archive</button>
      </div></td>
    </tr>`).join("") || '<tr><td colspan="4" class="empty-state">No services available.</td></tr>';

  document.querySelector("#vehicle-rows").innerHTML = vehicles.map((vehicle) => `
    <tr>
      <td data-label="Vehicle">${escapeHtml(vehicle.name)}</td>
      <td data-label="State">${vehicle.isActive ? "Active" : "Inactive"}</td>
      <td data-label="Actions"><div class="row-actions">
        <button class="button button-secondary" data-edit-vehicle="${vehicle.id}" type="button">Edit</button>
        <button class="button button-secondary" data-archive-vehicle="${vehicle.id}" type="button">Archive</button>
      </div></td>
    </tr>`).join("") || '<tr><td colspan="3" class="empty-state">No vehicle types available.</td></tr>';

  const serviceSelect = document.querySelector("#price-form [name=serviceId]");
  const vehicleSelect = document.querySelector("#price-form [name=vehicleId]");
  const currentService = serviceSelect.value;
  const currentVehicle = vehicleSelect.value;
  serviceSelect.innerHTML = services.map((service) => `<option value="${service.id}">${escapeHtml(service.name)}</option>`).join("");
  vehicleSelect.innerHTML = vehicles.map((vehicle) => `<option value="${vehicle.id}">${escapeHtml(vehicle.name)}</option>`).join("");
  if (services.some((service) => String(service.id) === currentService)) serviceSelect.value = currentService;
  if (vehicles.some((vehicle) => String(vehicle.id) === currentVehicle)) vehicleSelect.value = currentVehicle;
  syncPriceInput();
}

function syncPriceInput() {
  const serviceId = Number(document.querySelector("#price-form [name=serviceId]").value);
  const vehicleId = Number(document.querySelector("#price-form [name=vehicleId]").value);
  const price = state.catalog?.prices.find((item) => item.serviceId === serviceId && item.vehicleId === vehicleId);
  document.querySelector("#price-form [name=price]").value = price ? Number(price.price).toFixed(2) : "";
}

function selectTab(name) {
  document.querySelectorAll(".tab").forEach((tab) => tab.classList.toggle("is-active", tab.dataset.tab === name));
  document.querySelectorAll(".panel").forEach((panel) => { panel.hidden = panel.id !== `${name}-panel`; });
  if (name === "bookings") loadBookings().catch((error) => showNotice(error.message));
  if (name === "catalog" && state.role === "admin") loadCatalog().catch((error) => showNotice(error.message));
}

document.querySelectorAll(".tab").forEach((tab) => tab.addEventListener("click", () => selectTab(tab.dataset.tab)));
document.querySelectorAll("[data-go]").forEach((button) => button.addEventListener("click", () => selectTab(button.dataset.go)));
document.querySelector("#refresh-page").addEventListener("click", async () => {
  try {
    await loadSummary();
    if (!document.querySelector("#bookings-panel").hidden) await loadBookings();
    if (!document.querySelector("#catalog-panel").hidden && state.role === "admin") await loadCatalog();
    showNotice("Data refreshed.", true);
  } catch (error) { showNotice(error.message); }
});
document.querySelector("#booking-view").addEventListener("change", () => loadBookings().catch((error) => showNotice(error.message)));
document.querySelector("#booking-rows").addEventListener("click", async (event) => {
  const button = event.target.closest("[data-status]");
  const archive = event.target.closest("[data-archive]");
  const restore = event.target.closest("[data-restore]");
  if (archive || restore) {
    const action = archive ? "archive" : "restore";
    if (!window.confirm(`${action[0].toUpperCase()}${action.slice(1)} this booking?`)) return;
    const selectedButton = archive || restore;
    selectedButton.disabled = true;
    try {
      const result = await request(`/api/admin/bookings/${selectedButton.dataset[action]}/${action}`, { method: "POST" });
      showNotice(result.message, true);
      await Promise.all([loadBookings(), loadSummary()]);
    } catch (error) {
      showNotice(error.message);
      selectedButton.disabled = false;
    }
    return;
  }
  if (!button) return;
  const action = Number(button.dataset.status) === 1 ? "confirm" : "cancel";
  if (!window.confirm(`Are you sure you want to ${action} booking ${button.closest("tr").querySelector(".cell-title").textContent}?`)) return;
  button.disabled = true;
  try {
    const result = await request(`/api/admin/bookings/${button.dataset.id}/status`, {
      method: "POST", body: JSON.stringify({ status: Number(button.dataset.status) })
    });
    showNotice(result.message, true);
    await Promise.all([loadBookings(), loadSummary()]);
  } catch (error) {
    showNotice(error.message);
    button.disabled = false;
  }
});

const serviceForm = document.querySelector("#service-form");
function resetServiceForm() {
  serviceForm.reset();
  serviceForm.elements.id.value = "0";
  serviceForm.elements.durationMinutes.value = "60";
  serviceForm.elements.isActive.checked = true;
}
document.querySelector("#reset-service").addEventListener("click", resetServiceForm);
document.querySelector("#service-rows").addEventListener("click", async (event) => {
  const edit = event.target.closest("[data-edit-service]");
  const archive = event.target.closest("[data-archive-service]");
  if (edit) {
    const service = state.catalog.services.find((item) => item.id === Number(edit.dataset.editService));
    if (!service) return;
    serviceForm.elements.id.value = service.id;
    serviceForm.elements.name.value = service.name;
    serviceForm.elements.description.value = service.description;
    serviceForm.elements.durationMinutes.value = service.durationMinutes;
    serviceForm.elements.isActive.checked = service.isActive;
    serviceForm.elements.name.focus();
  }
  if (archive && window.confirm("Archive this service? Existing booking history will be kept.")) {
    try {
      const result = await request(`/api/admin/services/${archive.dataset.archiveService}/archive`, { method: "POST" });
      showNotice(result.message, true);
      await loadCatalog();
    } catch (error) { showNotice(error.message); }
  }
});
serviceForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const data = new FormData(serviceForm);
  const payload = {
    id: Number(data.get("id")),
    name: data.get("name"),
    description: data.get("description"),
    durationMinutes: Number(data.get("durationMinutes")),
    isActive: data.has("isActive")
  };
  try {
    const result = await request("/api/admin/services", { method: "POST", body: JSON.stringify(payload) });
    showNotice(result.message, true);
    resetServiceForm();
    await loadCatalog();
  } catch (error) { showNotice(error.message); }
});

const vehicleForm = document.querySelector("#vehicle-form");
function resetVehicleForm() {
  vehicleForm.reset();
  vehicleForm.elements.id.value = "0";
  vehicleForm.elements.isActive.checked = true;
}
document.querySelector("#reset-vehicle").addEventListener("click", resetVehicleForm);
document.querySelector("#vehicle-rows").addEventListener("click", async (event) => {
  const edit = event.target.closest("[data-edit-vehicle]");
  const archive = event.target.closest("[data-archive-vehicle]");
  if (edit) {
    const vehicle = state.catalog.vehicles.find((item) => item.id === Number(edit.dataset.editVehicle));
    if (!vehicle) return;
    vehicleForm.elements.id.value = vehicle.id;
    vehicleForm.elements.name.value = vehicle.name;
    vehicleForm.elements.isActive.checked = vehicle.isActive;
    vehicleForm.elements.name.focus();
  }
  if (archive && window.confirm("Archive this vehicle type? Existing booking history will be kept.")) {
    try {
      const result = await request(`/api/admin/vehicles/${archive.dataset.archiveVehicle}/archive`, { method: "POST" });
      showNotice(result.message, true);
      await loadCatalog();
    } catch (error) { showNotice(error.message); }
  }
});
vehicleForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const data = new FormData(vehicleForm);
  try {
    const result = await request("/api/admin/vehicles", {
      method: "POST",
      body: JSON.stringify({ id: Number(data.get("id")), name: data.get("name"), isActive: data.has("isActive") })
    });
    showNotice(result.message, true);
    resetVehicleForm();
    await loadCatalog();
  } catch (error) { showNotice(error.message); }
});

document.querySelector("#price-form").addEventListener("change", syncPriceInput);
document.querySelector("#price-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const data = new FormData(event.currentTarget);
  try {
    const result = await request("/api/admin/prices", {
      method: "POST",
      body: JSON.stringify({
        serviceId: Number(data.get("serviceId")),
        vehicleId: Number(data.get("vehicleId")),
        price: Number(data.get("price"))
      })
    });
    showNotice(result.message, true);
    await loadCatalog();
  } catch (error) { showNotice(error.message); }
});

document.querySelector("#sign-out").addEventListener("click", async () => {
  try {
    await request("/api/admin/logout", { method: "POST" });
    window.location.replace("/admin-login.html");
  } catch (error) { showNotice(error.message); }
});

async function start() {
  try {
    const session = await request("/api/admin/session");
    if (!session.authenticated) {
      window.location.replace("/admin-login.html");
      return;
    }
    state.role = session.role;
    document.querySelector("#staff-name").textContent = session.name;
    document.querySelectorAll(".admin-only").forEach((element) => { element.hidden = state.role !== "admin"; });
    await loadCsrf();
    await loadSummary();
    await advanceDueBookings();
    dueBookingsInterval = window.setInterval(() => {
      advanceDueBookings().catch(stopDueBookingsInterval);
    }, 30000);
  } catch (error) {
    showNotice(error.message);
  }
}

document.addEventListener("visibilitychange", () => {
  if (!document.hidden) advanceDueBookings().catch(stopDueBookingsInterval);
});

start();
