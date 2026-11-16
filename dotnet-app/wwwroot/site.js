const serviceList = document.querySelector("#service-list");
const promoSection = document.querySelector("#promo-section");
const promoList = document.querySelector("#promo-list");
const serviceStatus = document.querySelector("#service-status");
const serviceRetry = document.querySelector("#service-retry");
const search = document.querySelector("#search");
const priceDialog = document.querySelector("#price-dialog");
const priceStatus = document.querySelector("#price-status");
const priceList = document.querySelector("#price-list");
const priceRetry = document.querySelector("#price-retry");

let services = [];
let activePriceService = null;

function priceButton(service) {
  const button = document.createElement("button");
  button.className = "price-button";
  button.type = "button";
  button.textContent = "Prices by vehicle";
  button.addEventListener("click", () => showPrices(service));
  return button;
}

function serviceCard(service, compact = false) {
  const card = document.createElement("article");
  card.className = "service-card";

  const match = /^Promo Package \d{2} - (.+)$/.exec(service.name);
  const title = document.createElement("h3");
  title.textContent = match ? match[1] : service.name;
  const description = document.createElement("p");
  description.textContent = service.description;
  const duration = document.createElement("span");
  duration.className = "duration";
  duration.textContent = service.duration;
  const actions = document.createElement("div");
  actions.className = "service-card-actions";
  actions.append(priceButton(service));

  const bookLink = document.createElement("a");
  bookLink.className = "service-book-link";
  bookLink.href = `/booking.html?serviceId=${encodeURIComponent(service.id)}`;
  bookLink.textContent = "Book this service";
  actions.append(bookLink);
  card.append(title, description, duration, actions);
  if (compact) card.classList.add("promo-variant");
  return card;
}

function renderServices() {
  const query = search.value.trim().toLocaleLowerCase();
  const filtered = services.filter(service =>
    `${service.name} ${service.description}`.toLocaleLowerCase().includes(query)
  );
  const regular = filtered.filter(service => !/^Promo Package \d{2} - /.test(service.name));
  const promo = filtered.filter(service => /^Promo Package \d{2} - /.test(service.name));
  serviceList.replaceChildren();
  promoList.replaceChildren();

  for (const service of regular) serviceList.append(serviceCard(service));

  const packages = new Map();
  for (const service of promo) {
    const number = /^Promo Package (\d{2}) - /.exec(service.name)?.[1];
    if (!number) continue;
    const options = packages.get(number) || [];
    options.push(service);
    packages.set(number, options);
  }

  for (const [number, options] of [...packages.entries()].sort(([a], [b]) => Number(a) - Number(b))) {
    const group = document.createElement("details");
    group.className = "promo-group";
    const summary = document.createElement("summary");
    summary.textContent = `Package ${Number(number)} · ${options.length} option${options.length === 1 ? "" : "s"}`;
    const grid = document.createElement("div");
    grid.className = "service-grid";
    for (const service of options) grid.append(serviceCard(service, true));
    group.append(summary, grid);
    promoList.append(group);
    if (query) group.open = true;
  }

  promoSection.hidden = packages.size === 0;
  serviceStatus.textContent = filtered.length
    ? `${filtered.length} service option${filtered.length === 1 ? "" : "s"} found`
    : query ? "No matching services were found." : "No services are currently available.";
  if (filtered.length === 0) {
    const empty = document.createElement("p");
    empty.className = "empty-services";
    empty.textContent = query ? "Try another search term." : "Please check back soon.";
    serviceList.append(empty);
    promoSection.hidden = true;
  }
}

async function showPrices(service) {
  activePriceService = service;
  document.querySelector("#price-title").textContent = `${service.name} prices`;
  priceDialog.showModal();
  await loadPrices(service);
}

async function loadPrices(service) {
  priceDialog.setAttribute("aria-busy", "true");
  priceStatus.textContent = "Loading prices…";
  priceList.replaceChildren();
  priceRetry.hidden = true;

  try {
    const response = await fetch(`/api/public/services/${service.id}/prices`);
    if (!response.ok) throw new Error("Prices could not be loaded.");
    const result = await response.json();
    for (const item of result.prices) {
      const row = document.createElement("div");
      row.className = "price-row";
      const vehicle = document.createElement("span");
      vehicle.textContent = item.vehicle;
      const price = document.createElement("strong");
      price.textContent = item.price === null
        ? "Price unavailable"
        : new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(item.price);
      row.append(vehicle, price);
      priceList.append(row);
    }
    priceStatus.textContent = result.prices.length
      ? "Prices may vary by vehicle type."
      : "No vehicle prices are available for this service.";
  } catch {
    priceStatus.textContent = "Prices could not be loaded. Check your connection and try again.";
    priceRetry.hidden = false;
  } finally {
    priceDialog.setAttribute("aria-busy", "false");
  }
}

document.querySelector("#close-dialog").addEventListener("click", () => priceDialog.close());
priceRetry.addEventListener("click", () => {
  if (activePriceService) loadPrices(activePriceService);
});
priceDialog.addEventListener("click", event => {
  if (event.target === priceDialog) priceDialog.close();
});
search.addEventListener("input", renderServices);

async function loadPublicServices() {
  serviceRetry.hidden = true;
  serviceRetry.disabled = true;
  serviceStatus.textContent = "Loading services…";
  try {
    const response = await fetch("/api/public/services", { cache: "no-store" });
    if (!response.ok) throw new Error("Services could not be loaded.");
    services = await response.json();
    search.disabled = false;
    renderServices();
  } catch {
    serviceStatus.textContent = "Services are temporarily unavailable. Check your connection and try again.";
    serviceList.replaceChildren();
    serviceRetry.hidden = false;
  } finally {
    serviceRetry.disabled = false;
  }
}

serviceRetry.addEventListener("click", loadPublicServices);
loadPublicServices();
