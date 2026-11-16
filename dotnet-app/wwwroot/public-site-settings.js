let galleryItems = [];
let currentGalleryItem = 0;
let galleryTimer = null;

function getPublicAssetUrl(value) {
  if (typeof value !== "string") return "";
  const path = value.split("?", 1)[0].replace(/^\/+/, "");
  const parts = path.split("/");
  if (
    !path.startsWith("uploads/") ||
    parts.some((part) => !part || part === "." || part === "..") ||
    !/^uploads\/[A-Za-z0-9._/-]+\.(?:png|jpe?g)$/i.test(path)
  ) {
    return "";
  }

  return `/${parts.map(encodeURIComponent).join("/")}`;
}

async function loadPublicSiteSettings() {
  try {
    const response = await fetch("/api/public/settings", { cache: "no-store" });
    if (!response.ok) throw new Error(`Settings request failed (${response.status}).`);
    const settings = await response.json();
    const name = typeof settings.name === "string" ? settings.name.trim() : "";
    const shortName = typeof settings.shortName === "string" ? settings.shortName.trim() : "";
    const about = typeof settings.aboutUs === "string" ? settings.aboutUs.trim() : "";

    if (name) {
      document.querySelectorAll("[data-site-name]").forEach((element) => {
        element.textContent = name;
      });
      document.title = document.title.replace(/Kwacky-kleen/gi, name);
    }

    if (shortName) {
      document.querySelectorAll("[data-site-short-name]").forEach((element) => {
        element.textContent = shortName;
      });
    }

    if (about) {
      document.querySelectorAll("[data-site-about]").forEach((element) => {
        element.textContent = about;
      });
    }

    const logoUrl = getPublicAssetUrl(settings.logo);
    if (logoUrl) {
      document.querySelectorAll("[data-site-logo]").forEach((image) => {
        image.src = logoUrl;
        image.hidden = false;
      });
    }

    const gallery = document.querySelector("#public-home-carousel");
    const inner = gallery?.querySelector(".carousel-inner");
    if (gallery && inner) {
      const imageUrls = [settings.cover, ...(Array.isArray(settings.banners) ? settings.banners : [])]
        .map(getPublicAssetUrl)
        .filter(Boolean);
      inner.replaceChildren();
      for (const [index, url] of imageUrls.entries()) {
        const slide = document.createElement("div");
        slide.className = `carousel-item${index === 0 ? " active" : ""}`;
        slide.setAttribute("aria-hidden", String(index !== 0));
        const image = document.createElement("img");
        image.src = url;
        image.alt = `${name || "Shop"} gallery image ${index + 1}`;
        image.loading = index === 0 ? "eager" : "lazy";
        slide.append(image);
        inner.append(slide);
      }
      gallery.hidden = imageUrls.length === 0;
      galleryItems = [...inner.querySelectorAll(".carousel-item")];
      currentGalleryItem = 0;
      const previousButton = gallery.querySelector("[data-gallery-prev]");
      const nextButton = gallery.querySelector("[data-gallery-next]");
      if (previousButton) previousButton.hidden = galleryItems.length < 2;
      if (nextButton) nextButton.hidden = galleryItems.length < 2;

      if (galleryTimer !== null) window.clearInterval(galleryTimer);
      if (galleryItems.length > 1 && !window.matchMedia("(prefers-reduced-motion: reduce)").matches) {
        galleryTimer = window.setInterval(() => showGalleryItem(currentGalleryItem + 1), 6500);
      }
    }
  } catch (error) {
    console.error("Could not load public site settings.", error);
    const main = document.querySelector("main");
    if (main) {
      const notice = document.createElement("p");
      notice.className = "site-settings-error";
      notice.setAttribute("role", "status");
      notice.textContent = "Some site information may be temporarily unavailable.";
      main.prepend(notice);
    }
  }
}

function showGalleryItem(index) {
  if (galleryItems.length < 2) return;
  currentGalleryItem = (index + galleryItems.length) % galleryItems.length;
  galleryItems.forEach((item, itemIndex) => {
    const active = itemIndex === currentGalleryItem;
    item.classList.toggle("active", active);
    item.setAttribute("aria-hidden", String(!active));
  });
}

document.querySelector("[data-gallery-prev]")?.addEventListener("click", () => {
  showGalleryItem(currentGalleryItem - 1);
});
document.querySelector("[data-gallery-next]")?.addEventListener("click", () => {
  showGalleryItem(currentGalleryItem + 1);
});

loadPublicSiteSettings();
