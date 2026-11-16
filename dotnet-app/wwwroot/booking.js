const form = document.querySelector("#booking-form");
const vehicleSelect = document.querySelector("#vehicle-id");
const dateInput = document.querySelector("#schedule");
const timeSelect = document.querySelector("#schedule-time");
const serviceList = document.querySelector("#booking-services");
const regularServices = document.querySelector("#regular-services");
const promoServices = document.querySelector("#promo-services");
const serviceSearch = document.querySelector("#service-search");
const retryVehiclesButton = document.querySelector("#retry-vehicles");
const retryServicesButton = document.querySelector("#retry-services");
const vehicleHelp = document.querySelector("#vehicle-help");
const serviceHelp = document.querySelector("#service-help");
const scheduleHelp = document.querySelector("#schedule-help");
const errorBox = document.querySelector("#booking-error");
const submitButton = document.querySelector("#submit-booking");
const continueButton = document.querySelector("#continue-details");
const appointmentStep = document.querySelector("#appointment-step");
const customerStep = document.querySelector("#customer-step");
const progressSteps = [...document.querySelectorAll("#booking-progress li")];
const confirmation = document.querySelector("#booking-confirmation");
const serviceDetails = new Map();
let hasLoadedServices = false;
let hasAppliedRequestedService = false;
let slotsRequestId = 0;
let servicesRequestId = 0;

const manilaDate = new Intl.DateTimeFormat("en-CA", {
  timeZone: "Asia/Manila",
  year: "numeric",
  month: "2-digit",
  day: "2-digit"
}).format(new Date());
dateInput.min = manilaDate;

function php(value) {
  return new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(value);
}

function selectedServices() {
  return [...serviceList.querySelectorAll("input:checked")]
    .map(input => ({ id: Number(input.value), price: Number(input.dataset.price) }));
}

function updateTotal() {
  const total = 250 + selectedServices().reduce((sum, service) => sum + service.price, 0);
  document.querySelector("#total-amount").textContent = php(total);
  document.querySelector("#review-total").textContent = php(total);
  continueButton.disabled = !vehicleSelect.value || !dateInput.value ||
    !timeSelect.value || selectedServices().length === 0;
}

function showError(message) {
  errorBox.textContent = message;
  errorBox.hidden = false;
  errorBox.focus();
  errorBox.scrollIntoView({ behavior: "smooth", block: "center" });
}

function clearError() {
  errorBox.hidden = true;
  errorBox.textContent = "";
}

function setStep(step) {
  const isAppointmentStep = step === 1;
  appointmentStep.hidden = !isAppointmentStep;
  customerStep.hidden = isAppointmentStep;
  for (const control of appointmentStep.querySelectorAll("input, select, textarea")) {
    control.disabled = !isAppointmentStep;
  }
  for (const control of customerStep.querySelectorAll("input, select, textarea")) {
    control.disabled = isAppointmentStep;
  }
  progressSteps.forEach((item, index) => {
    item.classList.toggle("is-current", index + 1 === step);
    item.classList.toggle("is-complete", index + 1 < step);
    if (index + 1 === step) item.setAttribute("aria-current", "step");
    else item.removeAttribute("aria-current");
  });
  clearError();
  document.querySelector(isAppointmentStep ? "#appointment-step h2" : "#customer-step h2").focus();
  window.scrollTo({ top: 0, behavior: "smooth" });
}

async function loadVehicles() {
  const previousSelection = vehicleSelect.value;
  retryVehiclesButton.hidden = true;
  vehicleSelect.disabled = true;
  vehicleHelp.textContent = "Loading vehicle types…";
  const response = await fetch("/api/public/vehicles", { cache: "no-store" });
  if (!response.ok) throw new Error("Vehicle types are temporarily unavailable.");
  const vehicles = await response.json();
  vehicleSelect.replaceChildren(new Option("Choose a vehicle", ""));
  for (const vehicle of vehicles) vehicleSelect.add(new Option(vehicle.name, vehicle.id));
  if (vehicles.some(vehicle => String(vehicle.id) === previousSelection)) {
    vehicleSelect.value = previousSelection;
  }
  vehicleSelect.disabled = vehicles.length === 0;
  vehicleHelp.textContent = vehicles.length
    ? "Choose the vehicle you'll bring to the appointment."
    : "No vehicle types are currently available.";
}

function addServiceCard(service, target) {
  const option = document.createElement("div");
  option.className = "booking-service-option";

  const checkbox = document.createElement("input");
  checkbox.type = "checkbox";
  checkbox.id = `service-${service.id}`;
  checkbox.value = service.id;
  checkbox.disabled = service.price === null;
  if (service.price !== null) checkbox.dataset.price = service.price;
  checkbox.addEventListener("change", updateTotal);

  const label = document.createElement("label");
  label.htmlFor = checkbox.id;
  label.append(document.createTextNode(service.variantName || service.name));
  const duration = document.createElement("small");
  duration.textContent = `Estimated: ${service.estimatedDuration}`;
  label.append(duration);

  const price = document.createElement("span");
  price.className = "service-price";
  price.textContent = service.price === null ? "Unavailable" : php(service.price);

  option.append(checkbox, label, price);
  target.append(option);
  return option;
}

async function loadServices() {
  const requestId = ++servicesRequestId;
  retryServicesButton.hidden = true;
  regularServices.replaceChildren();
  promoServices.replaceChildren();
  serviceDetails.clear();
  hasLoadedServices = false;
  serviceSearch.value = "";
  serviceSearch.disabled = !vehicleSelect.value;
  updateTotal();

  if (!vehicleSelect.value) {
    serviceHelp.textContent = "Select a vehicle type to see available services and prices.";
    return;
  }

  serviceHelp.textContent = "Loading services and prices…";
  try {
    const response = await fetch(`/api/public/booking/services?vehicleId=${encodeURIComponent(vehicleSelect.value)}`, { cache: "no-store" });
    const result = await response.json();
    if (requestId !== servicesRequestId) return;
    if (!response.ok || result.status !== "success") {
      throw new Error(result.msg || "Prices are unavailable.");
    }

    const services = result.data;
    const packages = new Map();
    const standardServices = [];
    for (const service of services) {
      const match = /^Promo Package (\d{2}) - (.+)$/.exec(service.name);
      if (match) {
        const packageServices = packages.get(match[1]) || [];
        packageServices.push({ ...service, variantName: match[2] });
        packages.set(match[1], packageServices);
      } else {
        standardServices.push(service);
      }
      serviceDetails.set(service.id, service);
    }

    standardServices.sort((a, b) => (a.price ?? Infinity) - (b.price ?? Infinity) || a.name.localeCompare(b.name));
    for (const service of standardServices) addServiceCard(service, regularServices);

    for (const [number, variants] of [...packages.entries()].sort(([a], [b]) => Number(a) - Number(b))) {
      const group = document.createElement("details");
      group.className = "promo-group";
      const summary = document.createElement("summary");
      summary.textContent = `Package ${Number(number)} · ${variants.length} options`;
      const options = document.createElement("div");
      options.className = "booking-service-grid";
      variants.sort((a, b) => (a.price ?? Infinity) - (b.price ?? Infinity) || a.name.localeCompare(b.name));
      for (const service of variants) addServiceCard(service, options);
      group.append(summary, options);
      promoServices.append(group);
    }

    hasLoadedServices = true;
    serviceSearch.disabled = services.length === 0;
    if (!hasAppliedRequestedService) {
      hasAppliedRequestedService = true;
      const requestedServiceId = new URLSearchParams(location.search).get("serviceId");
      const requestedService = requestedServiceId && document.getElementById(`service-${requestedServiceId}`);
      if (requestedService && !requestedService.disabled) requestedService.checked = true;
    }
    serviceHelp.textContent = services.length
      ? `${services.length} service options for ${vehicleSelect.selectedOptions[0].text}. Prices are confirmed when you submit.`
      : "No services are currently available.";
    if (!services.length) {
      const empty = document.createElement("p");
      empty.className = "empty-services";
      empty.textContent = "No services are currently available for this vehicle.";
      serviceList.append(empty);
    }
    filterServices();
    updateTotal();
  } catch (error) {
    if (requestId !== servicesRequestId) return;
    serviceHelp.textContent = error.message || "Could not load services for this vehicle.";
    retryServicesButton.hidden = false;
  }
}

function filterServices() {
  const query = serviceSearch.value.trim().toLocaleLowerCase();
  let visibleCount = 0;
  for (const card of regularServices.querySelectorAll(".booking-service-option")) {
    const matches = !query || card.textContent.toLocaleLowerCase().includes(query);
    card.hidden = !matches;
    if (matches) visibleCount++;
  }
  for (const group of promoServices.querySelectorAll(".promo-group")) {
    let groupMatches = 0;
    for (const card of group.querySelectorAll(".booking-service-option")) {
      const matches = !query || card.textContent.toLocaleLowerCase().includes(query);
      card.hidden = !matches;
      if (matches) groupMatches++;
    }
    group.hidden = groupMatches === 0;
    visibleCount += groupMatches;
    if (query && groupMatches > 0) group.open = true;
  }

  const existingEmpty = serviceList.querySelector(".empty-services");
  if (existingEmpty) existingEmpty.remove();
  serviceHelp.textContent = query
    ? `${visibleCount} matching service option${visibleCount === 1 ? "" : "s"}.`
    : serviceDetails.size
      ? `${serviceDetails.size} service options for ${vehicleSelect.selectedOptions[0].text}. Prices are confirmed when you submit.`
      : "No services are currently available.";
  if (hasLoadedServices && visibleCount === 0) {
    const empty = document.createElement("p");
    empty.className = "empty-services";
    empty.textContent = query ? "No services match your search." : "No services are currently available.";
    serviceList.append(empty);
  }
}

async function loadSlots() {
  const requestId = ++slotsRequestId;
  const previousSelection = timeSelect.value;
  timeSelect.replaceChildren(new Option("Loading available times…", ""));
  timeSelect.disabled = true;
  updateTotal();
  if (!dateInput.value) {
    timeSelect.replaceChildren(new Option("Select a date first", ""));
    scheduleHelp.textContent = "Shop hours: weekdays 7:00 AM–11:00 PM; weekends 7:00 AM–12:00 AM.";
    return;
  }

  const date = new Date(`${dateInput.value}T12:00:00`);
  const weekend = date.getDay() === 0 || date.getDay() === 6;
  const hours = weekend ? "7:00 AM–12:00 AM" : "7:00 AM–11:00 PM";
  try {
    const response = await fetch(`/api/public/booking/slots?date=${encodeURIComponent(dateInput.value)}`);
    const result = await response.json();
    if (requestId !== slotsRequestId) return;
    if (!response.ok || result.status !== "success") {
      throw new Error(result.title || "Availability is unavailable.");
    }

    timeSelect.replaceChildren(new Option("Choose an available time", ""));
    let availableCount = 0;
    let restoredSelection = false;
    for (const slot of result.data) {
      const [hour, minute] = slot.time.split(":").map(Number);
      const time = new Date(2000, 0, 1, hour, minute).toLocaleTimeString("en-US", {
        hour: "numeric",
        minute: "2-digit"
      });
      const option = new Option(
        slot.available > 0 ? time : `${time} (${slot.past ? "Past" : "Full"})`,
        slot.time
      );
      option.disabled = slot.available <= 0 || slot.past;
      if (!option.disabled) availableCount++;
      timeSelect.add(option);
      if (slot.time === previousSelection && !option.disabled) {
        option.selected = true;
        restoredSelection = true;
      }
    }
    if (!restoredSelection) timeSelect.selectedIndex = 0;
    timeSelect.disabled = availableCount === 0;
    scheduleHelp.textContent = availableCount
      ? `Shop hours: ${hours}. Up to 2 bookings per 1-hour slot.`
      : "No available appointment times for this date. Select another date.";
  } catch (error) {
    if (requestId !== slotsRequestId) return;
    timeSelect.replaceChildren(new Option("Availability unavailable", ""));
    scheduleHelp.textContent = error.message || "Could not load appointment availability.";
  }
  updateTotal();
}

function updateReview() {
  const date = new Date(`${dateInput.value}T12:00:00`);
  const dateText = new Intl.DateTimeFormat("en-PH", {
    dateStyle: "long",
    timeZone: "Asia/Manila"
  }).format(date);
  document.querySelector("#review-appointment").textContent =
    `${vehicleSelect.selectedOptions[0].text} · ${dateText} at ${timeSelect.selectedOptions[0].text}`;

  const reviewServices = document.querySelector("#review-services");
  reviewServices.replaceChildren();
  for (const service of selectedServices()) {
    const item = document.createElement("li");
    const details = serviceDetails.get(service.id);
    item.textContent = `${details.name} — ${php(service.price)}`;
    reviewServices.append(item);
  }
  document.querySelector("#review-total").textContent =
    php(250 + selectedServices().reduce((sum, service) => sum + service.price, 0));
}

function showConfirmation(receipt) {
  for (const [id, value] of Object.entries({
    "receipt-code": receipt.code,
    "receipt-client": receipt.clientName,
    "receipt-contact": receipt.contact,
    "receipt-plate": receipt.plateNumber,
    "receipt-vehicle": receipt.vehicle,
    "receipt-schedule": receipt.schedule,
    "receipt-subtotal": `PHP ${receipt.formattedSubtotal}`,
    "receipt-fee": `PHP ${receipt.formattedReservationFee}`,
    "receipt-total": `PHP ${receipt.formattedTotal}`
  })) {
    document.getElementById(id).textContent = value;
  }

  const receiptServices = document.querySelector("#receipt-services");
  receiptServices.replaceChildren();
  for (const service of receipt.services) {
    const item = document.createElement("li");
    item.textContent = `${service.name} — PHP ${service.formattedPrice}`;
    receiptServices.append(item);
  }
  const token = encodeURIComponent(receipt.pdfToken);
  document.querySelector("#download-confirmation").href =
    `/api/public/receipts/${token}/confirmation.pdf`;
  document.querySelector("#payment-options").href = `/payment.html?token=${token}`;
  form.hidden = true;
  document.querySelector("#booking-progress").hidden = true;
  confirmation.hidden = false;
  document.querySelector("#receipt-code").focus?.();
  window.scrollTo({ top: 0, behavior: "smooth" });
}

vehicleSelect.addEventListener("change", loadServices);
dateInput.addEventListener("change", loadSlots);
timeSelect.addEventListener("change", updateTotal);
serviceSearch.addEventListener("input", filterServices);
retryVehiclesButton.addEventListener("click", async () => {
  retryVehiclesButton.disabled = true;
  try {
    await loadVehicles();
    await loadServices();
  } catch (error) {
    vehicleHelp.textContent = error.message || "Could not load vehicle types.";
    retryVehiclesButton.hidden = false;
  } finally {
    retryVehiclesButton.disabled = false;
  }
});
retryServicesButton.addEventListener("click", loadServices);
window.addEventListener("focus", () => {
  if (dateInput.value) loadSlots();
});
document.querySelector("#another-booking").addEventListener("click", () => window.location.reload());

continueButton.addEventListener("click", () => {
  clearError();
  for (const control of [vehicleSelect, dateInput, timeSelect]) {
    if (!control.reportValidity()) return;
  }
  if (selectedServices().length === 0) {
    showError("Select at least one service to continue.");
    return;
  }
  updateReview();
  setStep(2);
  customerStep.querySelector("input").focus();
});

document.querySelector("#back-to-appointment").addEventListener("click", () => setStep(1));

form.addEventListener("submit", async event => {
  event.preventDefault();
  clearError();
  if (!form.reportValidity()) return;
  const services = selectedServices();
  if (!services.length) {
    setStep(1);
    showError("Select at least one service to continue.");
    return;
  }

  submitButton.disabled = true;
  submitButton.textContent = "Submitting…";
  try {
    const data = {
      clientName: form.elements.namedItem("clientName").value,
      contact: form.elements.namedItem("contact").value,
      plateNumber: form.elements.namedItem("plateNumber").value,
      email: form.elements.namedItem("email").value,
      address: form.elements.namedItem("address").value,
      vehicleId: Number(vehicleSelect.value),
      schedule: dateInput.value,
      scheduleTime: timeSelect.value,
      serviceIds: services.map(service => service.id)
    };
    const response = await fetch("/api/public/bookings", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data)
    });
    const result = await response.json();
    if (!response.ok || result.status !== "success") {
      throw new Error(result.msg || result.title || "Your booking could not be saved.");
    }
    showConfirmation(result.receipt);
  } catch (error) {
    showError(error.message || "Could not confirm whether your booking was saved. Please contact the shop before submitting again.");
  } finally {
    submitButton.disabled = false;
    submitButton.textContent = "Confirm booking";
  }
});

vehicleSelect.disabled = true;
setStep(1);
loadVehicles()
  .then(() => {
    const requestedVehicle = new URLSearchParams(location.search).get("vehicleId");
    if (requestedVehicle) vehicleSelect.value = requestedVehicle;
    return loadServices();
  })
  .catch(error => {
    vehicleSelect.replaceChildren(new Option("Vehicles are temporarily unavailable", ""));
    serviceHelp.textContent = error.message || "Could not load vehicle types. Please try again later.";
    vehicleHelp.textContent = error.message || "Vehicle types are temporarily unavailable.";
    retryVehiclesButton.hidden = false;
  });

window.setInterval(() => {
  if (!document.hidden && dateInput.value) loadSlots();
}, 30000);
