<style>
    #serviceAccordion button.btn.btn-block.text-left.font-weight-bolder,
    #promoPackageAccordion button.btn.btn-block.text-left.font-weight-bolder,
    #promoPackageList button.btn.btn-block.text-left.font-weight-bolder {
        box-shadow: none !important;
    }
    #search-field .form-control.rounded-pill{
        border-top-right-radius:0 !important;
        border-bottom-right-radius:0 !important;
        border-right:none !important
    }
    #search-field .form-control:focus{
        box-shadow:none !important;
    }
    #search-field .form-control:focus + .input-group-append .services-search-button{
        border-color: #22d3ee !important;
        background-color: #0e7490 !important;
    }
    #search-field .services-search-button{
        min-width: 56px;
        border-top-left-radius:0 !important;
        border-bottom-left-radius:0 !important;
        border-color: #0891b2 !important;
        background-color: #0891b2 !important;
        color: #fff !important;
        cursor: pointer;
        transition: background-color .18s ease, box-shadow .18s ease;
    }
    #search-field .services-search-button:hover,
    #search-field .services-search-button:focus-visible{
        border-color: #0e7490 !important;
        background-color: #0e7490 !important;
        color: #fff !important;
        box-shadow: 0 0 0 3px rgba(34, 211, 238, .28) !important;
        outline: none;
    }
</style>
<div class="section py-5">
    <div class="container">
        <h3 class="text-center"><b>Our Services</b></h3>
        <hr>
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10 col-sm-12 col-sm-12 mb-3">
                <div class="input-group input-group-lg" id="search-field">
                    <input type="search" class="form-control form-control-lg rounded-pill" aria-label="Search Service Field" id="search" placeholder="Search service here">
                    <div class="input-group-append">
                        <button class="btn services-search-button rounded-pill" type="button" id="services-search-button" aria-label="Search services" title="Search services"><i class="fa fa-search" aria-hidden="true"></i></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $services = $conn->query("SELECT * FROM `service_list` WHERE delete_flag = 0 AND `status` = 1 ORDER BY CASE `name` WHEN 'Car Wash' THEN 0 WHEN 'Car Repaint' THEN 1 ELSE 2 END, `name` ASC");
        $regularServices = [];
        $promoPackages = [];
        if(!$services){
            error_log('Public services query failed: ' . $conn->error);
        }
        while($services && $row = $services->fetch_assoc()){
            if(preg_match('/^Promo Package (\d{2}) - (.+)$/', $row['name'], $matches)){
                $packageNumber = $matches[1];
                if(!isset($promoPackages[$packageNumber])){
                    $promoPackages[$packageNumber] = [
                        'name' => 'Promo Package ' . $packageNumber,
                        'variants' => [],
                    ];
                }
                $row['variant_name'] = $matches[2];
                $promoPackages[$packageNumber]['variants'][] = $row;
                continue;
            }
            $regularServices[] = $row;
        }
        ?>
        <h4 class="mt-4 mb-2">Services</h4>
        <div id="service-search-empty" class="alert alert-info" role="status" hidden>No matching packages or services were found.</div>
        <div class="accordion" id="serviceAccordion">
            <div class="card mb-0 rounded-0 promo-packages-card">
                <div class="card-header" id="promo-packages-heading">
                    <h2 class="mb-0">
                        <button class="btn btn-block text-left font-weight-bolder" type="button" data-toggle="collapse" data-target="#promo-packages-content" aria-expanded="false" aria-controls="promo-packages-content">
                        <div class="d-flex w-100 align-items-center justify-content-between">
                            Promo Packages
                            <i class="fa fa-plus font-size-3 promo-packages-collapse-icon"></i>
                        </div>
                        </button>
                    </h2>
                </div>
                <div id="promo-packages-content" class="collapse" aria-labelledby="promo-packages-heading" data-parent="#serviceAccordion">
                    <div class="card-body">
                        <div class="accordion" id="promoPackageAccordion">
                        <?php foreach($promoPackages as $packageNumber => $package):
                            $packageId = 'promo-package-' . $packageNumber;
                            $packageName = 'Package ' . (int) $packageNumber;
                        ?>
                        <div class="card mb-0 promo-package-card">
                            <div class="card-header" id="<?= $packageId ?>-heading">
                                <h3 class="mb-0">
                                    <button class="btn btn-block text-left font-weight-bolder" type="button" data-toggle="collapse" data-target="#collapse-<?= $packageId ?>" aria-expanded="false" aria-controls="collapse-<?= $packageId ?>">
                                        <div class="d-flex w-100 align-items-center justify-content-between">
                                            <?= $packageName ?>
                                            <i class="fa fa-plus font-size-3 promo-package-collapse-icon"></i>
                                        </div>
                                    </button>
                                </h3>
                            </div>
                            <div id="collapse-<?= $packageId ?>" class="collapse promo_package_collapse" aria-labelledby="<?= $packageId ?>-heading" data-parent="#promoPackageAccordion">
                                <div class="card-body">
                                    <?php foreach($package['variants'] as $variant):
                                        $variantName = htmlspecialchars($variant['variant_name'], ENT_QUOTES, 'UTF-8');
                                        $serviceName = htmlspecialchars($variant['name'], ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <div class="service-option mb-3">
                                        <h5><?= $variantName ?></h5>
                                        <p><?= nl2br(htmlspecialchars($variant['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                                        <p><strong>Duration:</strong> <?= htmlspecialchars(format_duration_minutes($variant['duration_minutes']), ENT_QUOTES, 'UTF-8') ?></p>
                                        <button class="btn btn-primary btn-sm rounded-pill view_price" type="button" data-id="<?= (int) $variant['id'] ?>" data-name="<?= $serviceName ?>"><i class="fa fa-tags"></i> View Prices by Vehicle</button>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php foreach($regularServices as $service):
                $serviceId = (int) $service['id'];
                $serviceName = htmlspecialchars($service['name'], ENT_QUOTES, 'UTF-8');
            ?>
            <div class="card mb-0 rounded-0 regular-service-card">
                <div class="card-header" id="service<?= $serviceId ?>">
                    <h2 class="mb-0">
                        <button class="btn btn-block text-left font-weight-bolder" type="button" data-toggle="collapse" data-target="#collapse-service-<?= $serviceId ?>" aria-expanded="false" aria-controls="collapse-service-<?= $serviceId ?>">
                            <div class="d-flex w-100 align-items-center justify-content-between">
                                <?= $serviceName ?>
                                <i class="fa fa-plus font-size-3 collapse-icon"></i>
                            </div>
                        </button>
                    </h2>
                </div>
                <div id="collapse-service-<?= $serviceId ?>" class="collapse service_collapse" aria-labelledby="service<?= $serviceId ?>" data-parent="#serviceAccordion">
                    <div class="card-body">
                        <p><?= nl2br(htmlspecialchars($service['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                        <div class="text-center">
                            <button class="btn btn-primary btn-sm rounded-pill view_price" type="button" data-id="<?= $serviceId ?>" data-name="<?= $serviceName ?>"><i class="fa fa-tags"></i> View Prices by Vehicle</button>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script>
    $(function(){
        $('.view_price').click(function(){
            var name = $(this).attr('data-name')
            var id = $(this).attr('data-id')
            uni_modal("<b>"+name+"'s Price List</b>", "services/price_list.php?id="+id)
        })
        $('.service_collapse, .promo_package_collapse, #promo-packages-content').on('show.bs.collapse',function(){
            var card = $(this).closest('.card')
            var icon = card.find('.collapse-icon, .promo-package-collapse-icon, .promo-packages-collapse-icon')
            icon.removeClass('fa-plus').addClass('fa-minus')
        }).on('hide.bs.collapse',function(){
            var card = $(this).closest('.card')
            var icon = card.find('.collapse-icon, .promo-package-collapse-icon, .promo-packages-collapse-icon')
            icon.removeClass('fa-minus').addClass('fa-plus')
        })
        $('#search').on('input', function(){
            var search = $(this).val().toLowerCase().trim()
            var matchesFound = 0
            if(search !== ''){
                $('.service_collapse, .promo_package_collapse, #promo-packages-content').removeAttr('data-parent')
            }else{
                $('.regular-service-card .service_collapse, #promo-packages-content').attr('data-parent', '#serviceAccordion')
                $('.promo_package_collapse').attr('data-parent', '#promoPackageAccordion')
            }
            $('.regular-service-card').each(function(){
                var card = $(this)
                var matches = search === '' || card.text().toLowerCase().includes(search)
                card.toggle(matches)
                if(matches && search !== ''){
                    matchesFound++
                    card.find('.service_collapse').collapse('show')
                }else if(search === ''){
                    card.find('.service_collapse').collapse('hide')
                }
            })
            var visiblePackages = 0
            $('.promo-package-card').each(function(){
                var packageCard = $(this)
                var packageNameMatches = search === '' || packageCard.children('.card-header').text().toLowerCase().includes(search)
                var matchingVariants = 0
                packageCard.find('.service-option').each(function(){
                    var variant = $(this)
                    var variantMatches = search === '' || packageNameMatches || variant.text().toLowerCase().includes(search)
                    variant.toggle(variantMatches)
                    if(variantMatches && search !== ''){
                        matchingVariants++
                    }
                })
                var matches = search === '' || packageNameMatches || matchingVariants > 0
                packageCard.toggle(matches)
                if(matches && search !== ''){
                    visiblePackages++
                    matchesFound++
                    packageCard.find('.promo_package_collapse').collapse('show')
                }else if(search === ''){
                    packageCard.find('.promo_package_collapse').collapse('hide')
                }
            })
            var showPromoPackages = search === '' || 'promo packages'.includes(search) || visiblePackages > 0
            $('.promo-packages-card').toggle(showPromoPackages)
            if(search !== '' && showPromoPackages && (visiblePackages > 0 || 'promo packages'.includes(search))){
                $('#promo-packages-content').collapse('show')
            }else if(search === ''){
                $('#promo-packages-content').collapse('hide')
            }
            $('#service-search-empty').prop('hidden', search === '' || matchesFound > 0)
        })
        $('#services-search-button').on('click', function(){
            $('#search').trigger('input').trigger('focus')
        })
    })
</script>
