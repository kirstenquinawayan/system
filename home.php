
<?php 
$bannerDirectory = base_app . 'uploads/banner';
$coverImage = (string) $_settings->info('cover');
$coverPath = explode('?', $coverImage, 2)[0];
$heroImages = [];
if($coverPath !== '' && is_file(base_app . $coverPath)){
    $heroImages[] = $coverImage;
}
if(is_dir($bannerDirectory)){
    foreach(scandir($bannerDirectory) as $banner){
        if(in_array($banner, ['.', '..'], true) || !preg_match('/\.(jpe?g|png|webp)$/i', $banner)){
            continue;
        }
        if(is_file($bannerDirectory . '/' . $banner)){
            $heroImages[] = 'uploads/banner/' . $banner;
        }
    }
}
$siteName = trim((string) $_settings->info('name'));
$siteName = $siteName !== '' ? $siteName : 'Car Wash and Detailing';
$siteShortName = trim((string) $_settings->info('short_name'));
$siteShortName = $siteShortName !== '' ? $siteShortName : $siteName;
?>
<main id="public-home">
    <section class="public-home-hero" aria-labelledby="public-home-title">
        <div id="public-home-carousel" class="carousel slide public-home-carousel" data-ride="carousel" data-interval="6500" aria-label="Car wash gallery">
            <div class="carousel-inner">
                <?php foreach($heroImages as $index => $image): ?>
                    <div class="carousel-item <?= $index === 0 ? 'active' : '' ?>">
                        <img src="<?= htmlspecialchars(validate_image($image), ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>" <?= $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="public-home-hero-shade"></div>
            <?php if(count($heroImages) > 1): ?>
                <button class="carousel-control-prev" type="button" data-target="#public-home-carousel" data-slide="prev" aria-label="Previous image">
                    <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                </button>
                <button class="carousel-control-next" type="button" data-target="#public-home-carousel" data-slide="next" aria-label="Next image">
                    <span class="carousel-control-next-icon" aria-hidden="true"></span>
                </button>
            <?php endif; ?>
        </div>
        <div class="public-home-hero-content">
            <h1 id="public-home-title"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></h1>
            <p>Experience meticulous hand washing, advanced ceramic coatings, and professional detailing designed to protect and restore your vehicle's showroom look.</p>
            <div class="public-home-actions">
                <a class="public-home-button public-home-button-primary" href="./?p=booking&amp;new_booking=1">Book Your Wash Now</a>
                <a class="public-home-button public-home-button-secondary" href="./?p=services">Explore Services</a>
            </div>
        </div>
    </section>
    <section class="public-home-services" id="home-services" aria-label="Featured Services">
        <div class="container px-4 px-lg-5">
            <div class="public-home-services-header">
                <span class="public-home-eyebrow public-home-services-kicker"><i class="fa fa-star" aria-hidden="true"></i> Explore Services</span>
                <h2>Detailing and protection built around your routine.</h2>
                <p>From everyday maintenance to premium paint protection, every package is designed to restore shine, protect finish, and keep your vehicle looking its best.</p>
            </div>

            <div class="public-home-service-grid">
                <article class="public-home-service-card">
                    <img class="public-home-service-image" src="uploads/booking/services-background-4k.jpg" alt="Car covered in foam during a wash" loading="lazy">
                    <div class="public-home-service-content">
                        <h3>Car Wash</h3>
                        <p>Foam, rinse, and hand dry to leave your vehicle clean and refreshed.</p>
                        <span class="public-home-service-tag">Exterior wash</span>
                    </div>
                </article>

                <article class="public-home-service-card">
                    <img class="public-home-service-image" src="uploads/services/interior-detailing.png" alt="Gloved detailer cleaning the center console inside a vehicle" loading="lazy">
                    <div class="public-home-service-content">
                        <h3>Interior Detailing</h3>
                        <p>Deep cabin cleaning and careful care for a fresher, more comfortable interior.</p>
                        <span class="public-home-service-tag">Deep clean</span>
                    </div>
                </article>

                <article class="public-home-service-card">
                    <img class="public-home-service-image" src="uploads/banner/wall4.jpg" alt="Detailer polishing a vehicle exterior" loading="lazy">
                    <div class="public-home-service-content">
                        <h3>Exterior Detailing</h3>
                        <p>Careful paint cleaning and polishing to bring back a smooth, glossy finish.</p>
                        <span class="public-home-service-tag">Paint care</span>
                    </div>
                </article>

                <article class="public-home-service-card">
                    <img class="public-home-service-image" src="uploads/banner/wall5.jpg" alt="Microfiber cloth caring for a glossy vehicle finish" loading="lazy">
                    <div class="public-home-service-content">
                        <h3>Ceramic Coating</h3>
                        <p>Long-lasting paint protection for a glossy finish that is easier to maintain.</p>
                        <span class="public-home-service-tag">Lasting gloss</span>
                    </div>
                </article>
            </div>

            <div class="public-home-services-cta">
                <a class="public-home-button public-home-button-primary" href="./?p=services">Explore All Services</a>
            </div>
        </div>
    </section>

    <section class="public-home-why" aria-labelledby="public-home-why-title">
        <div class="container px-4 px-lg-5">
            <div class="public-home-why-layout">
                <figure class="public-home-why-photo">
                    <img src="uploads/services/why-car-care.png" alt="Car wash professional standing beside a freshly washed car" loading="lazy">
                    <figcaption><strong>Car care, made simple.</strong> Browse, choose, and book in a few steps.</figcaption>
                </figure>
                <div class="public-home-why-content">
                    <span class="public-home-eyebrow">Why choose us</span>
                    <h2 id="public-home-why-title">Car care,<br><span>minus the detour.</span></h2>
                    <div class="public-home-why-points">
                        <article class="public-home-why-point">
                            <span class="public-home-why-icon"><i class="fa fa-calendar-check" aria-hidden="true"></i></span>
                            <div><h3>Convenient</h3><p>Browse services and book your visit online.</p></div>
                        </article>
                        <article class="public-home-why-point">
                            <span class="public-home-why-icon"><i class="fa fa-list-alt" aria-hidden="true"></i></span>
                            <div><h3>Clear choices</h3><p>Review service details and prices for your vehicle.</p></div>
                        </article>
                        <article class="public-home-why-point">
                            <span class="public-home-why-icon"><i class="fa fa-clock" aria-hidden="true"></i></span>
                            <div><h3>Choose your schedule</h3><p>Select an available date and appointment time.</p></div>
                        </article>
                        <article class="public-home-why-point">
                            <span class="public-home-why-icon"><i class="fa fa-star" aria-hidden="true"></i></span>
                            <div><h3>Services in one place</h3><p>Explore car wash, detailing, and ceramic coating options.</p></div>
                        </article>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="public-home-benefits" aria-labelledby="public-home-benefits-title">
        <div class="container px-4 px-lg-5">
            <div class="public-home-benefits-heading">
                <span class="public-home-eyebrow"><i class="fa fa-check-circle" aria-hidden="true"></i> Easy from start to finish</span>
                <h2 id="public-home-benefits-title">A smoother way to care for your car.</h2>
            </div>
            <div class="public-home-benefit-grid">
                <article class="public-home-benefit">
                    <span class="public-home-benefit-number">01</span>
                    <div>
                        <h3>Choose with confidence</h3>
                        <p>Review service details and check available prices for your vehicle type before booking.</p>
                    </div>
                </article>
                <article class="public-home-benefit">
                    <span class="public-home-benefit-number">02</span>
                    <div>
                        <h3>Pick a convenient schedule</h3>
                        <p>Choose your vehicle, preferred date, and an available appointment time in the booking form.</p>
                    </div>
                </article>
                <article class="public-home-benefit">
                    <span class="public-home-benefit-number">03</span>
                    <div>
                        <h3>Keep your confirmation</h3>
                        <p>After submitting, review your booking reference and service summary on the confirmation screen.</p>
                    </div>
                </article>
            </div>
        </div>
    </section>

    <section class="public-home-faq" aria-labelledby="public-home-faq-title">
        <div class="container px-4 px-lg-5">
            <div class="public-home-faq-layout">
                <div class="public-home-faq-heading">
                    <span class="public-home-eyebrow"><i class="fa fa-comments" aria-hidden="true"></i> Need to know</span>
                    <h2 id="public-home-faq-title">Frequently asked questions</h2>
                    <p>Quick answers to help you choose a service and plan your visit.</p>
                    <a class="public-home-faq-link" href="./?p=services">Browse service details <i class="fa fa-arrow-right" aria-hidden="true"></i></a>
                </div>
                <div class="public-home-faq-list">
                    <details class="public-home-faq-item">
                        <summary>How can I check the price for my vehicle?</summary>
                        <p>Open Our Services, expand a service, then select “View Prices by Vehicle” to see the available price for each vehicle type.</p>
                    </details>
                    <details class="public-home-faq-item">
                        <summary>How do I schedule a booking?</summary>
                        <p>Open Booking, enter your details, choose your vehicle, select a date, and pick an available appointment time.</p>
                    </details>
                    <details class="public-home-faq-item">
                        <summary>What happens after I submit the booking form?</summary>
                        <p>The confirmation screen shows your booking reference, schedule, selected services, and total. You can also view the booking confirmation PDF.</p>
                    </details>
                    <details class="public-home-faq-item">
                        <summary>How do I pay for my booking?</summary>
                        <p>Payment is settled in cash at the cashier upon arrival, as shown on the booking confirmation.</p>
                    </details>
                </div>
            </div>
        </div>
    </section>

    <section class="public-home-about" id="about" aria-label="About Us">
        <div class="container px-4 px-lg-5">
            <div class="public-home-about-card">
                <div class="public-home-about-content">
                    <?php include 'about.html'; ?>
                </div>
            </div>
        </div>
    </section>

</main>