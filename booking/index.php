<section class="py-5">
    <div class="container">
        <?php if($_settings->chk_flashdata('success_fix')): ?>
            <div class="alert alert-success">
                <div class="d-flex w-100 align-items-center">
                    <div class="col-11"><?= $_settings->flashdata('success_fix') ?></div>
                    <div class="col-1 text-right">
                        <button class="btn-close" type="button" onclick="$(this).closest('.alert').hide('slow').remove()"></button>
                    </div>
                </div>
            </div>
        <?php endif;?>
        <h2 class="text-center"><b>Booking Form</b></h2>
        <center>
            <hr class="bg-primary" width="10%" style="height:2px;opacity:1">
        </center>
        <div class="row justify-content-center">
            <div class="col-lg-8 col-md-10 col-sm-12 col-xs-12">
                <div class="card card-default rounded-0 shadow blur">
                    <div class="card-body container-fluid">
                        <div id="booking-success" class="d-none">
                            <div class="booking-confirmation-success text-center">
                                <h3><i class="fa fa-check-circle" aria-hidden="true"></i> Booking successfully confirmed!</h3>
                                <small>Your reservation has been recorded.</small>
                            </div>
                            <h4 class="booking-confirmation-title">Booking Confirmation</h4>
                            <div class="booking-receipt-details">
                                <div class="booking-receipt-item"><strong>Booking Reference</strong><span id="receipt-code"></span></div>
                                <div class="booking-receipt-item"><strong>Customer</strong><span id="receipt-client"></span></div>
                                <div class="booking-receipt-item"><strong>Contact</strong><span id="receipt-contact"></span></div>
                                <div class="booking-receipt-item"><strong>Plate Number</strong><span id="receipt-plate"></span></div>
                                <div class="booking-receipt-item"><strong>Vehicle</strong><span id="receipt-vehicle"></span></div>
                                <div class="booking-receipt-item"><strong>Schedule</strong><span id="receipt-schedule"></span></div>
                            </div>
                            <h5 class="booking-confirmation-section-title">Selected Services</h5>
                            <ul id="receipt-services" class="list-group mb-3"></ul>
                            <div class="booking-confirmation-totals">
                                <div><span>Services:</span><span>₱<span id="receipt-subtotal"></span></span></div>
                                <div><span>Reservation Fee:</span><span>₱<span id="receipt-reservation-fee"></span></span></div>
                                <div class="booking-confirmation-total"><strong>Total Amount:</strong><strong>₱<span id="receipt-total"></span></strong></div>
                            </div>
                            <div class="booking-arrival-notice" role="note">
                                <strong><i class="fa fa-clock-o" aria-hidden="true"></i> Arrival Notice</strong>
                                <p>Please arrive on time. If you are more than 15 minutes late, the next eligible queue number may be served first. Contact staff if you arrive late.</p>
                            </div>
                            <div class="booking-confirmation-payment">
                                <h5 class="booking-confirmation-section-title">Payment Options</h5>
                                <?php if(stripe_test_mode_enabled()): ?>
                                    <p class="booking-payment-copy">Choose online payment through Stripe test mode or pay in cash at the shop cashier. Stripe test transactions do not charge real money.</p>
                                <?php else: ?>
                                    <p class="booking-payment-copy">Pay your booking balance in cash at the shop cashier upon arrival. Your booking stays unpaid until staff confirms receipt.</p>
                                <?php endif; ?>
                                <div class="booking-payment-actions">
                                    <?php if(stripe_test_mode_enabled()): ?>
                                        <button type="button" class="btn btn-success" id="pay-with-stripe"><i class="fa fa-lock"></i> Continue to Online Payment</button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-success" id="pay-cash-at-shop" aria-pressed="false"><i class="fa fa-money"></i> Cash Payment</button>
                                </div>
                                <?php if(stripe_test_mode_enabled()): ?>
                                    <div id="stripe-payment-status" class="small mt-2" role="status" aria-live="polite"></div>
                                <?php endif; ?>
                                <div id="cash-payment-status" class="small mt-2" role="status" aria-live="polite" hidden></div>
                            </div>
                            <div class="booking-confirmation-actions no-print">
                                <button type="button" class="btn" id="print-booking"><i class="fa fa-file-pdf-o"></i> View Booking Confirmation PDF</button>
                                <a class="btn" href="<?= isset($_SESSION['customer_userdata']) && is_array($_SESSION['customer_userdata']) ? './?p=my_bookings' : './?booking_login=1#customer-login' ?>"><i class="fa fa-bell"></i> Notification / Reschedule</a>
                                <button type="button" class="btn" id="new-booking"><i class="fa fa-plus"></i> Make Another Booking</button>
                            </div>
                        </div>
                        <form action="" id="booking-form">
                            <input type="hidden" name="id" value="">
                            <div class="row">
                                <div class="col-lg-6 col-md-6 col-sm-12 col-xs-12">
                                    <div class="form-group mb-3">
                                        <label for="client_name" class="control-label mx-3">Full Name</label>
                                        <input type="text" class="form-control rounded-pill" id="client_name" name="client_name" required>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="contact" class="control-label mx-3">Contact #</label>
                                        <input type="tel" class="form-control rounded-pill" id="contact" name="contact" inputmode="numeric" minlength="11" maxlength="11" pattern="[0-9]{11}" title="Enter an 11-digit contact number." placeholder="09xxxxxxxxx" required>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="plate_number" class="control-label mx-3">Plate Number</label>
                                        <input type="text" class="form-control rounded-pill text-uppercase" id="plate_number" name="plate_number" maxlength="8" pattern="(?:[A-Za-z]{3} ?[0-9]{4}|[A-Za-z]{2} ?[0-9]{5}|[0-9]{1,6})" title="Enter a vehicle plate like AAA 0123, a motorcycle plate like AA 12345, a protocol number, or a 6-digit diplomatic plate." placeholder="ex.AAA 0123" autocomplete="off" autocorrect="off" autocapitalize="characters" spellcheck="false" value="" aria-describedby="plate-number-feedback" required>
                                        <div id="plate-number-feedback" class="invalid-feedback" aria-live="polite"></div>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="email" class="control-label mx-3">Email</label>
                                        <input type="email" class="form-control rounded-pill" id="email" name="email" required>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="address" class="control-label mx-3">Address</label>
                                        <textarea rows="3" class="form-control" id="address" name="address" required></textarea>
                                    </div>
                                </div>
                                <div class="col-lg-6 col-md-6 col-sm-12 col-xs-12">
                                    <div class="form-group mb-3">
                                        <label for="vehicle_id" class="control-label mx-3">Vehicle Type</label>
                                        <select class="form-control rounded-pill" id="vehicle_id" name="vehicle_id">
                                            <option value="" selected disabled></option>
                                            <?php 
                                            $vehicles = $conn->query("SELECT * FROM `vehicle_list` WHERE `status` = 1 AND delete_flag = 0 ORDER BY CASE `name` WHEN 'Sedan' THEN 1 WHEN 'AUV' THEN 2 WHEN 'MPV' THEN 3 WHEN 'Pick Up' THEN 4 WHEN 'Ordinary Van' THEN 5 WHEN 'Premium Van' THEN 6 ELSE 99 END, `name` ASC");
                                            while($row = $vehicles->fetch_assoc()):
                                            ?>
                                            <option value="<?= $row['id'] ?>"><?= $row['name'] ?></option>
                                            <?php endwhile; ?>
                                        </select>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="schedule" class="control-label mx-3">Schedule</label>
                                        <input type="date" class="form-control rounded-pill" id="schedule" name="schedule" min="<?= date('Y-m-d') ?>" required>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="schedule_time" class="control-label mx-3">Appointment Time</label>
                                        <select class="form-control rounded-pill" id="schedule_time" name="schedule_time" required disabled>
                                            <option value="">Select a date first</option>
                                        </select>
                                        <small id="schedule-help" class="form-text text-muted">Shop hours: Monday-Friday, 7:00 AM-11:00 PM; Saturday-Sunday, 7:00 AM-12:00 AM. Appointments are available every hour.</small>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="reservation_fee" class="control-label mx-3">Reservation Fee</label>
                                        <input type="text" class="form-control rounded-pill text-right" id="reservation_fee" value="PHP <?= number_format(BOOKING_RESERVATION_FEE, 2) ?>" readonly>
                                        <small class="form-text text-danger mx-3">Non-refundable.</small>
                                    </div>
                                    <div class="form-group mb-3">
                                        <label for="total_amount" class="control-label mx-3">Total Amount</label>
                                        <input type="text" class="form-control rounded-pill text-right" id="total_amount" name="total_amount" value="0" readonly>
                                    </div>
                                </div>
                            </div>
                            <hr>
                            <h4 class="text-center"><b>Select Services</b></h4>
                            <div class="text-center" id="sel-vehicle-first"><small class="text-muted"><i>Please Select Vehicle Type First</i></small></div>
                            <div id="services-list" class="row m-0 w-100"></div>
                            <div class="clear-fix mb-3"></div>
                            <div class="text-center mb-2"><small class="text-muted">A PHP <?= number_format(BOOKING_RESERVATION_FEE, 2) ?> reservation fee is included in the total. Pay the full amount at the shop cashier.</small></div>
                            <div class="text-center"><button class="btn btn-primary bg-gradient-primary col-4 rounded-pill"><i class="fa fa-send"></i> Submit Booking</button></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<noscript id="service-row-clone">
    <div class="col-1 border mx-0 text-center">
        <input type="hidden" name="service_id[]">
        <input type="hidden" class="price" name="price[]">
        <div class="form-check">
            <input class="form-check-input service-checkbox" data-id="" type="checkbox" name="service_check[]">
        </div>
    </div>
    <div class="col-5 border mx-0 service_name">
        <span class="service-name-label"></span>
        <small class="service-duration-label d-block text-muted"></small>
    </div>
    <div class="col-6 border mx-0 text-end service_price"></div>
</noscript>
<script>
    $(function(){
        var confirmationStorageKey = 'ocwbs_booking_confirmation'
        var startsNewBooking = new URLSearchParams(window.location.search).get('new_booking') === '1'
        function showBookingConfirmation(receipt){
            $('#receipt-code').text(receipt.code)
            $('#receipt-client').text(receipt.client_name)
            $('#receipt-contact').text(receipt.contact)
            $('#receipt-plate').text(receipt.plate_number)
            $('#receipt-vehicle').text(receipt.vehicle)
            $('#receipt-schedule').text(receipt.schedule)
            $('#receipt-subtotal').text(receipt.formatted_subtotal)
            $('#receipt-reservation-fee').text(receipt.formatted_reservation_fee)
            $('#receipt-total').text(receipt.formatted_total)
            window.bookingReceiptToken = receipt.pdf_token
            window.bookingReceipt = receipt
            var servicesList = $('#receipt-services').empty()
            receipt.services.forEach(function(service){
                var item = $('<li>').addClass('list-group-item d-flex justify-content-between')
                item.append($('<span>').text(service.name))
                item.append($('<span>').text('₱' + service.formatted_price))
                servicesList.append(item)
            })
            try {
                if(sessionStorage.getItem('ocwbs_cash_payment_choice_' + receipt.pdf_token) === 'cash'){
                    showCashPaymentChoice(receipt)
                }
            } catch(error) {
                console.error('Could not restore the cash payment choice.', error)
            }
            $('#booking-form').addClass('d-none')
            $('#booking-success').removeClass('d-none')
            $('body').addClass('booking-confirmation-active')
        }
        function isValidReceipt(receipt){
            return !!receipt &&
                Array.isArray(receipt.services) &&
                /^[a-f0-9]{64}$/.test(receipt.pdf_token || '') &&
                receipt.services.every(function(service){
                    return !!service && typeof service.name === 'string' && typeof service.formatted_price === 'string'
                })
        }
        function setStripeStatus(message, state){
            $('#stripe-payment-status')
                .removeClass('text-danger text-success text-muted')
                .addClass(state === 'error' ? 'text-danger' : (state === 'success' ? 'text-success' : 'text-muted'))
                .text(message)
        }
        function showCashPaymentChoice(receipt, saved){
            $('#pay-cash-at-shop')
                .removeClass('btn-outline-success')
                .addClass('btn-success')
                .attr('aria-pressed', 'true')
            $('#cash-payment-status')
                .removeClass('text-danger text-muted')
                .addClass('text-success')
                .text('Cash payment selected. Please pay PHP ' + receipt.formatted_total + ' at the shop cashier. Your booking remains unpaid until staff confirms receipt.' + (saved ? '' : ' Select cash again if you refresh this page.'))
                .prop('hidden', false)
        }
        try {
            if(startsNewBooking){
                sessionStorage.removeItem(confirmationStorageKey)
            }
            var savedConfirmation = sessionStorage.getItem(confirmationStorageKey)
            if(savedConfirmation){
                var savedReceipt = JSON.parse(savedConfirmation)
                if(isValidReceipt(savedReceipt)){
                    showBookingConfirmation(savedReceipt)
                }else{
                    sessionStorage.removeItem(confirmationStorageKey)
                }
            }
        } catch(error) {
            console.error('Could not restore the booking confirmation.', error)
        }
        var stripeReturn = new URLSearchParams(window.location.search)
        if(stripeReturn.get('stripe') === 'cancelled'){
            setStripeStatus('Stripe checkout was cancelled. No payment was made; you can try again or pay at the cashier.', 'pending')
        }else if(stripeReturn.get('stripe') === 'success'){
            var returnedSessionId = stripeReturn.get('session_id') || ''
            if(/^cs_test_[A-Za-z0-9_]+$/.test(returnedSessionId)){
                setStripeStatus('Checking your Stripe test payment...', 'pending')
                $.ajax({
                    url: _base_url_ + 'classes/Master.php?f=confirm_stripe_checkout',
                    method: 'POST',
                    data: {session_id: returnedSessionId},
                    dataType: 'JSON',
                    success: function(resp){
                        setStripeStatus(
                            resp.msg || 'Stripe is still confirming this payment. Please wait or contact the shop.',
                            resp.status === 'failed' ? 'error' : (resp.status === 'success' ? 'success' : 'pending')
                        )
                    },
                    error: function(xhr, textStatus, errorThrown){
                        console.error('Stripe return confirmation failed.', {
                            status: xhr.status,
                            textStatus: textStatus,
                            error: errorThrown
                        })
                        setStripeStatus('Stripe received your return, but the booking status could not be checked. Please contact the shop.', 'error')
                    }
                })
            }else{
                setStripeStatus('The Stripe session could not be checked. Please contact the shop.', 'error')
            }
        }
        if(startsNewBooking){
            try {
                var bookingUrl = new URL(window.location.href)
                bookingUrl.searchParams.delete('new_booking')
                window.history.replaceState(null, '', bookingUrl.pathname + bookingUrl.search + bookingUrl.hash)
            } catch(error) {
                console.warn('Could not remove the new-booking URL marker.', error)
            }
        }
        $('#vehicle_id').select2({
            width:'100%',
            placeholder:"Please select here",
            containerCssClass :"form-control rounded-pill"
        }).addClass("form-control rounded-pill")
        var plateNumberPattern = /^(?:[A-Z]{3} ?[0-9]{4}|[A-Z]{2} ?[0-9]{5}|[0-9]{1,6})$/
        $('#plate_number').val('').on('focus', function(){
            if(this.value === 'HAL. ABC 123' || this.value === 'HAL. ABC 1234' || this.value === 'ABC 1234'){
                this.value = ''
            }
        }).on('input', function(){
            var rawPlateNumber = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '')
            var plateLetters = (rawPlateNumber.match(/^[A-Z]+/) || [''])[0]
            var plateDigits = ''
            if(plateLetters){
                plateLetters = plateLetters.slice(0, 3)
                plateDigits = rawPlateNumber.slice((rawPlateNumber.match(/^[A-Z]+/) || [''])[0].length)
                    .replace(/\D/g, '')
                    .slice(0, plateLetters.length === 2 ? 5 : 4)
                this.value = plateLetters + (plateDigits ? ' ' + plateDigits : '')
            }else{
                this.value = rawPlateNumber.replace(/\D/g, '').slice(0, 6)
            }
            var plateNumber = this.value
            var validationMessage = ''
            if(plateNumber && !plateNumberPattern.test(plateNumber)){
                validationMessage = 'Use AAA 0123 for a vehicle, AA 12345 for a motorcycle, 1-6 digits for a protocol plate, or 6 digits for a diplomatic plate.'
            }else if(!plateNumber && this.dataset.touched === 'true'){
                validationMessage = 'Plate number is required.'
            }
            this.setCustomValidity(validationMessage)
            $(this)
                .toggleClass('is-invalid', !!validationMessage)
                .siblings('#plate-number-feedback')
                .text(validationMessage)
        }).on('blur', function(){
            this.dataset.touched = 'true'
            $(this).trigger('input')
        })
        $('#contact').on('input', function(){
            this.value = this.value.replace(/\D/g, '').slice(0, 11)
        })
        $('#plate_number').val('').attr({
            autocomplete: 'off',
            autocorrect: 'off',
            autocapitalize: 'characters',
            spellcheck: 'false'
        }).on('focus', function(){
            if(['HAL. ABC 123', 'HAL. ABC 1234', 'ABC 1234', 'ABC1234', 'HAL. ABC'].includes(this.value.trim())){
                this.value = ''
            }
        }).on('blur', function(){
            if(!this.value.trim()){
                this.value = ''
            }
        })
        $('#booking-form').attr('autocomplete', 'off')
        var plateResetAttempts = 0
        function forceClearPlateField(){
            var $plate = $('#plate_number')
            if(!$plate.length){
                return
            }
            var staleValues = ['HAL. ABC 123', 'HAL. ABC 1234', 'ABC 1234', 'ABC1234', 'HAL. ABC']
            var currentValue = $plate.val() || ''
            if(staleValues.includes(currentValue.trim()) || !currentValue.trim()){
                $plate.val('').trigger('input')
            }
            plateResetAttempts += 1
            if(plateResetAttempts < 25){
                setTimeout(forceClearPlateField, 100)
            }
        }
        setTimeout(forceClearPlateField, 50)
        function formatAppointmentTime(time){
            var parts = time.split(':')
            var hour = parseInt(parts[0], 10)
            var period = hour >= 12 ? 'PM' : 'AM'
            hour = hour % 12 || 12
            return hour + ':' + parts[1] + ' ' + period
        }
        function loadAvailableSlots(){
            var date = $('#schedule').val()
            var timeSelect = $('#schedule_time')
            var selectedTime = timeSelect.val()
            timeSelect.empty().prop('disabled', true)
            if(!date){
                timeSelect.append($('<option>').val('').text('Select a date first'))
                return
            }
            var selectedDay = new Date(date + 'T00:00:00').getDay()
            var isWeekend = selectedDay === 0 || selectedDay === 6
            var openingHours = isWeekend ? '7:00 AM-12:00 AM' : '7:00 AM-11:00 PM'
            $('#schedule-help').text('Shop hours for this date: ' + openingHours + '. Loading available appointment times...')
            timeSelect.append($('<option>').val('').text('Loading available times...'))
            $.ajax({
                url:_base_url_+"classes/Master.php?f=get_available_slots",
                method:'POST',
                data:{schedule:date},
                dataType:'json',
                error:function(){
                    timeSelect.empty().append($('<option>').val('').text('Availability unavailable. Please try again.'))
                    $('#schedule-help').text('Could not load appointment availability. Change the date to retry.')
                },
                success:function(resp){
                    timeSelect.empty()
                    if(resp.status !== 'success' || !Array.isArray(resp.data)){
                        timeSelect.append($('<option>').val('').text('Availability unavailable'))
                        $('#schedule-help').text(resp.msg || 'Could not load appointment availability.')
                        return
                    }
                    timeSelect.append($('<option>').val('').text('Choose an available time'))
                    var availableCount = 0
                    resp.data.forEach(function(slot){
                        var option = $('<option>').val(slot.time)
                        if(slot.available > 0){
                            option.text(formatAppointmentTime(slot.time))
                            availableCount++
                        }else{
                            option.text(formatAppointmentTime(slot.time) + (slot.past ? ' (Fully Booked)' : ' (Full)')).prop('disabled', true)
                        }
                        timeSelect.append(option)
                    })
                    timeSelect.find('option').each(function(){
                        if(this.value === selectedTime && !this.disabled){
                            timeSelect.val(selectedTime)
                        }
                    })
                    timeSelect.prop('disabled', false)
                    $('#schedule-help').text(availableCount
                        ? 'Shop hours: ' + openingHours + '. Up to 2 bookings per 1-hour slot.'
                        : 'No available appointment times for this date. Select another date.')
                }
            })
        }
        $('#schedule').on('change', loadAvailableSlots)
        $(window).on('focus', loadAvailableSlots)
        window.setInterval(function(){
            if(!document.hidden){
                loadAvailableSlots()
            }
        }, 30000)
        $('#vehicle_id').change(function(){
            var id = $(this).val()
            $('#services-list').empty()
            $('#sel-vehicle-first').html('<small class="text-muted"><i>Loading prices for selected vehicle...</i></small>')
            start_loader()
            $.ajax({
                url:_base_url_+"classes/Master.php?f=get_vehicle_service",
                method:'POST',
                data:{id:id},
                dataType:'json',
                error:err=>{
                    console.log(err)
                    alert_toast('An error occurred.', 'error')
                    end_loader()
                },
                success:function(resp){
                    if(resp.status == 'success'){
                        $('#services-list').html('')
                        var servicesLayout = $('<div>').addClass('booking-services-layout')
                        var regularServices = $('<div>').addClass('booking-service-column booking-regular-services')
                        var promoServices = $('<div>').addClass('booking-service-column booking-promo-services')
                        function addServiceHeaders(column, title){
                            column.append(
                                $('<div>').addClass('service-list-column-title').text(title),
                                $('<div>').addClass('service-list-header service-list-select'),
                                $('<div>').addClass('service-list-header text-center').text('Service'),
                                $('<div>').addClass('service-list-header text-right').text('Price')
                            )
                        }
                        addServiceHeaders(regularServices, 'Services')
                        addServiceHeaders(promoServices, 'Promo Packages')
                        servicesLayout.append(regularServices, promoServices)
                        $('#services-list').append(servicesLayout)
                        var unavailablePrices = 0
                        var regularServiceData = []
                        var promoPackageData = {}
                        function compareServicePrices(first, second){
                            var firstPrice = first.price === null ? Number.POSITIVE_INFINITY : Number(first.price)
                            var secondPrice = second.price === null ? Number.POSITIVE_INFINITY : Number(second.price)
                            return firstPrice - secondPrice || first.name.localeCompare(second.name)
                        }
                        Object.keys(resp.data).forEach(function(key){
                            var service = resp.data[key]
                            var packageMatch = service.name.match(/^Promo Package (\d{2}) - (.+)$/)
                            if(packageMatch){
                                if(!promoPackageData[packageMatch[1]]){
                                    promoPackageData[packageMatch[1]] = []
                                }
                                promoPackageData[packageMatch[1]].push(service)
                            }else{
                                regularServiceData.push(service)
                            }
                        })
                        regularServiceData.sort(compareServicePrices)
                        var sortedPromoPackages = Object.keys(promoPackageData).map(function(packageNumber){
                            var variants = promoPackageData[packageNumber].sort(compareServicePrices)
                            return {
                                number: packageNumber,
                                variants: variants,
                                lowestPrice: variants.length && variants[0].price !== null
                                    ? Number(variants[0].price)
                                    : Number.POSITIVE_INFINITY
                            }
                        }).sort(function(first, second){
                            return first.lowestPrice - second.lowestPrice || Number(first.number) - Number(second.number)
                        })
                        var orderedServiceData = regularServiceData.slice()
                        sortedPromoPackages.forEach(function(packageData){
                            orderedServiceData = orderedServiceData.concat(packageData.variants)
                        })
                        var currentPackage = null
                        var currentPackageOptions = null
                        orderedServiceData.forEach(function(service){
                            var row = $('<div>').append($($('noscript#service-row-clone').html()).clone())
                            var packageMatch = service.name.match(/^Promo Package (\d{2}) - (.+)$/)
                            if(packageMatch){
                                if(currentPackage !== packageMatch[1]){
                                    currentPackage = packageMatch[1]
                                    currentPackageOptions = $('<div>').addClass('service-package-options')
                                    var packageDropdown = $('<details>').addClass('service-list-package')
                                    packageDropdown.append(
                                        $('<summary>').addClass('service-list-package-header').text('Package ' + parseInt(currentPackage, 10)),
                                        currentPackageOptions
                                    )
                                    promoServices.append(packageDropdown)
                                }
                            }else{
                                currentPackage = null
                                currentPackageOptions = null
                            }
                            row.find('[name="service_id[]"]').attr('name', "service_id["+service.id+"]").val(service.id)
                            var priceInput = row.find('[name="price[]"]').attr('name', "price["+service.id+"]")
                            var serviceCheckbox = row.find('[name="service_check[]"]').attr('name', "service_check["+service.id+"]").attr('data-id',service.id)
                            row.find('.service-name-label').text(packageMatch ? packageMatch[2] : service.name)
                            row.find('.service-duration-label').text('Estimated: ' + service.estimated_duration)
                            if(service.price === null){
                                unavailablePrices++
                                serviceCheckbox.prop('disabled', true).attr('title', 'Price has not been configured for this vehicle.')
                                row.find('.service_price').text('Price unavailable')
                            }else{
                                priceInput.val(service.price)
                                row.find('.service_price').text(service.formatted_price)
                            }
                            var serviceTarget = packageMatch ? currentPackageOptions : regularServices
                            serviceTarget.append(row.contents())
                        })
                        $('#sel-vehicle-first').html(unavailablePrices
                            ? '<small class="text-warning">Some services have no price for this vehicle and cannot be selected.</small>'
                            : '')
                    }else{
                        $('#services-list').empty()
                        $('#sel-vehicle-first').html('<small class="text-danger">'+(resp.msg || 'Could not load prices for this vehicle.')+'</small>')
                    }
                    end_loader()
                },
                complete:function(){
                    $('#services-list .service-checkbox').change(function(){
                        calc_total()
                    })
                    function calc_total(){
                        var total = <?= json_encode(BOOKING_RESERVATION_FEE) ?>;
                        $('#services-list .service-checkbox:checked').each(function(){
                            var sid = $(this).attr('data-id')
                            total+= parseFloat($('[name="price['+sid+']"]').val())
                        })
                        $('#total_amount').val('PHP ' + total.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}))
                    }
                    calc_total()
                }
            })
        })
        $('#booking-form').submit(function(e){
            e.preventDefault()
            var _this = $(this)
            var pop_msg = $('<div>')
            pop_msg.addClass("alert alert-danger rounded-0 err-msg")
            pop_msg.hide()
            $('.err-msg').remove()
            start_loader()
            if(!$('#vehicle_id').val()){
                pop_msg.text("Please select a vehicle type first")
                _this.prepend(pop_msg)
                pop_msg.show()
                $('html, body').scrollTop(0)
                end_loader()
                return
            }
            if($('#services-list .service-checkbox:checked').length <= 0){
                pop_msg.text("Please Select atleast 1 service first")
                _this.prepend(pop_msg)
                pop_msg.show()
                $('html, body').scrollTop(0)
                end_loader()
                return
            }
            $.ajax({
                url:_base_url_+"classes/Master.php?f=save_booking",
                method:'POST',
                data:_this.serialize(),
                dataType:'JSON',
                error:function(xhr, textStatus, errorThrown){
                    console.error('Booking submission request failed.', {
                        status: xhr.status,
                        statusText: xhr.statusText,
                        textStatus: textStatus,
                        error: errorThrown
                    })
                    var errorMessage = xhr.responseJSON && typeof xhr.responseJSON.msg === 'string'
                        ? xhr.responseJSON.msg
                        : 'Could not confirm whether your booking was saved. Please check with the shop before submitting again.'
                    pop_msg.text(errorMessage)
                    _this.prepend(pop_msg)
                    pop_msg.show()
                    $('html, body').scrollTop(0)
                    end_loader()
                },
                success:function(resp){
                    if(resp.status == 'success'){
                        if(!isValidReceipt(resp.receipt)){
                            pop_msg.text(resp.msg || 'Your booking was submitted, but the confirmation could not be displayed. Booking reference: ' + (resp.code || 'unavailable') + '.')
                            _this.prepend(pop_msg)
                            pop_msg.show()
                            end_loader()
                            return
                        }
                        showBookingConfirmation(resp.receipt)
                        try {
                            sessionStorage.setItem(confirmationStorageKey, JSON.stringify(resp.receipt))
                        } catch(error) {
                            console.error('Could not save the booking confirmation for page refresh.', error)
                            alert_toast('The booking was submitted, but this browser could not save the confirmation for refresh.', 'warning')
                        }
                        $('html, body').scrollTop(0)
                        end_loader()
                    }else if(!!resp.msg){
                        pop_msg.text(resp.msg)
                        _this.prepend(pop_msg)
                        pop_msg.show()
                        $('html, body').scrollTop(0)
                        end_loader()
                    }else{
                        pop_msg.text('Could not confirm whether your booking was saved. Please check with the shop before submitting again.')
                        _this.prepend(pop_msg)
                        pop_msg.show()
                        $('html, body').scrollTop(0)
                        end_loader()
                    }
                }
            })
        })
        $('#print-booking').click(function(){
            location.href = _base_url_ + 'receipt_pdf.php?token=' + encodeURIComponent(window.bookingReceiptToken)
        })
        $('#new-booking').click(function(){
            try {
                sessionStorage.removeItem(confirmationStorageKey)
            } catch(error) {
                console.error('Could not clear the saved booking confirmation.', error)
            }
            location.reload()
        })
        $('#pay-cash-at-shop').click(function(){
            var receipt = window.bookingReceipt
            if(!isValidReceipt(receipt) || receipt.pdf_token !== window.bookingReceiptToken){
                $('#cash-payment-status')
                    .removeClass('text-success')
                    .addClass('text-danger')
                    .text('Could not verify this booking total. Please refresh the confirmation or contact the shop.')
                    .prop('hidden', false)
                return
            }
            var saved = false
            try {
                sessionStorage.setItem('ocwbs_cash_payment_choice_' + receipt.pdf_token, 'cash')
                saved = true
            } catch(error) {
                console.error('Could not save the cash payment choice.', error)
            }
            showCashPaymentChoice(receipt, saved)
        })
        $('#pay-with-stripe').click(function(){
            var button = $(this)
            if(!/^[a-f0-9]{64}$/.test(window.bookingReceiptToken || '')){
                setStripeStatus('The booking token is unavailable. Please open the booking confirmation again or pay at the cashier.', 'error')
                return
            }
            $('#pay-cash-at-shop')
                .removeClass('btn-success')
                .addClass('btn-outline-success')
                .attr('aria-pressed', 'false')
            $('#cash-payment-status').prop('hidden', true).text('')
            try {
                sessionStorage.removeItem('ocwbs_cash_payment_choice_' + window.bookingReceiptToken)
            } catch(error) {
                console.error('Could not clear the cash payment choice before online checkout.', error)
            }
            button.prop('disabled', true)
            setStripeStatus('Starting Stripe test checkout...', 'pending')
            $.ajax({
                url: _base_url_ + 'classes/Master.php?f=create_stripe_checkout',
                method: 'POST',
                data: {token: window.bookingReceiptToken},
                dataType: 'JSON',
                success: function(resp){
                    if(resp.status === 'success' && typeof resp.url === 'string' && /^https:\/\/checkout\.stripe\.com\//.test(resp.url)){
                        window.location.assign(resp.url)
                        return
                    }
                    setStripeStatus(resp.msg || 'Could not start Stripe checkout. Please pay at the cashier.', 'error')
                    button.prop('disabled', false)
                },
                error: function(xhr, textStatus, errorThrown){
                    console.error('Stripe checkout could not be started.', {
                        status: xhr.status,
                        textStatus: textStatus,
                        error: errorThrown
                    })
                    setStripeStatus('Could not start Stripe checkout. Please pay at the cashier.', 'error')
                    button.prop('disabled', false)
                }
            })
        })
    })
</script>