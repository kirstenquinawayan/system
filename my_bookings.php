<?php
$customerId = isset($_SESSION['customer_userdata']['id'])
    ? filter_var($_SESSION['customer_userdata']['id'], FILTER_VALIDATE_INT)
    : false;
if($customerId === false || $customerId < 1){
    redirect('#customer-login');
    exit;
}

$passwordRecoveryStatement = $conn->prepare(
    "SELECT id, status, expires_at,
            (status = 'approved' AND expires_at > NOW()) AS approval_active
     FROM customer_password_recovery_requests
     WHERE customer_id = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
     ORDER BY id DESC LIMIT 1"
);
if(!$passwordRecoveryStatement){
    error_log('Customer password recovery notification prepare failed: ' . $conn->error);
    $customerPasswordRecoveryRequest = null;
    $customerPasswordRecoveryError = 'Password reset approval status is temporarily unavailable.';
}else{
    $passwordRecoveryStatement->bind_param('i', $customerId);
    if(!$passwordRecoveryStatement->execute()){
        error_log('Customer password recovery notification query failed: ' . $passwordRecoveryStatement->error);
        $customerPasswordRecoveryRequest = null;
        $customerPasswordRecoveryError = 'Password reset approval status is temporarily unavailable.';
    }else{
        $passwordRecoveryResult = $passwordRecoveryStatement->get_result();
        $customerPasswordRecoveryRequest = $passwordRecoveryResult ? $passwordRecoveryResult->fetch_assoc() : null;
        $customerPasswordRecoveryError = '';
    }
    $passwordRecoveryStatement->close();
}
if($customerPasswordRecoveryRequest && $customerPasswordRecoveryRequest['status'] === 'approved' && (int) $customerPasswordRecoveryRequest['approval_active'] === 1){
    if(!isset($_SESSION['customer_seen_password_approval_ids']) || !is_array($_SESSION['customer_seen_password_approval_ids'])){
        $_SESSION['customer_seen_password_approval_ids'] = [];
    }
    if(!isset($_SESSION['customer_seen_password_approval_ids'][$customerId]) || !is_array($_SESSION['customer_seen_password_approval_ids'][$customerId])){
        $_SESSION['customer_seen_password_approval_ids'][$customerId] = [];
    }
    $_SESSION['customer_seen_password_approval_ids'][$customerId][(int) $customerPasswordRecoveryRequest['id']] = true;
}

$notificationsStatement = $conn->prepare(
    "SELECT n.id, b.code, n.status, n.message, n.appointment_at, n.date_created
     FROM customer_booking_notifications n
     INNER JOIN booking_list b ON b.id = n.booking_id
     WHERE n.customer_id = ? AND n.dismissed_at IS NULL
     ORDER BY n.date_created DESC, n.id DESC
     LIMIT 100"
);
if(!$notificationsStatement){
    error_log('Customer booking notifications prepare failed: ' . $conn->error);
    $customerNotifications = [];
    $customerNotificationsError = 'Booking updates are temporarily unavailable. Please try again later.';
}else{
    $notificationsStatement->bind_param('i', $customerId);
    if(!$notificationsStatement->execute()){
        error_log('Customer booking notifications query failed: ' . $notificationsStatement->error);
        $customerNotifications = [];
        $customerNotificationsError = 'Booking updates are temporarily unavailable. Please try again later.';
    }else{
        $notificationsResult = $notificationsStatement->get_result();
        $customerNotifications = $notificationsResult ? $notificationsResult->fetch_all(MYSQLI_ASSOC) : [];
        $customerNotificationsError = '';
    }
    $notificationsStatement->close();
}
if($customerNotificationsError === ''){
    $markNotificationsRead = $conn->prepare(
        "UPDATE customer_booking_notifications SET read_at = NOW()
         WHERE customer_id = ? AND read_at IS NULL AND dismissed_at IS NULL"
    );
    if(!$markNotificationsRead){
        error_log('Marking customer booking notifications read prepare failed: ' . $conn->error);
        $customerNotificationsError = 'Your updates are shown, but could not be marked as read.';
    }else{
        $markNotificationsRead->bind_param('i', $customerId);
        if(!$markNotificationsRead->execute()){
            error_log('Marking customer booking notifications read failed: ' . $markNotificationsRead->error);
            $customerNotificationsError = 'Your updates are shown, but could not be marked as read.';
        }
        $markNotificationsRead->close();
    }
}
$statement = $conn->prepare(
    "SELECT id, code, schedule, queue_number, status, plate_number, total_amount, payment_status,
            is_archived, service_started_at,
            (SELECT COALESCE(SUM(bs.duration_minutes), 0)
             FROM booking_services bs WHERE bs.booking_id = booking_list.id) AS total_duration_minutes
     FROM booking_list
     WHERE customer_id = ?
       AND NOT EXISTS (
           SELECT 1
           FROM customer_booking_dismissals cbd
           WHERE cbd.booking_id = booking_list.id
             AND cbd.customer_id = booking_list.customer_id
       )
     ORDER BY schedule DESC
     LIMIT 100"
);
if(!$statement){
    error_log('Customer bookings list prepare failed: ' . $conn->error);
    $customerBookingsError = 'Your bookings are temporarily unavailable. Please try again later.';
    $customerBookings = [];
}else{
    $statement->bind_param('i', $customerId);
    if(!$statement->execute()){
        error_log('Customer bookings list query failed: ' . $statement->error);
        $customerBookingsError = 'Your bookings are temporarily unavailable. Please try again later.';
        $customerBookings = [];
    }else{
        $result = $statement->get_result();
        $customerBookings = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $customerBookingsError = '';
    }
    $statement->close();
}
$statusNames = [
    0 => 'Waiting',
    1 => 'Confirmed',
    2 => 'Arrived',
    3 => 'In Progress',
    4 => 'Completed',
    5 => 'Cancelled',
];
?>
<section class="section py-5">
    <div class="container">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-1">Notification</h2>
                <p class="text-muted mb-0">View booking updates and appointment details.</p>
            </div>
        </div>
        <?php if(isset($_GET['rescheduled'])): ?>
            <div class="alert alert-success" role="status">Your booking has been rescheduled.</div>
        <?php endif; ?>
        <?php if($customerPasswordRecoveryError !== ''): ?>
            <div class="alert alert-warning" role="status"><?= htmlspecialchars($customerPasswordRecoveryError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif($customerPasswordRecoveryRequest && $customerPasswordRecoveryRequest['status'] === 'pending'): ?>
            <div class="alert alert-info" role="status"><strong>Password reset request:</strong> The admin is reviewing your request. Check back here for an update.</div>
        <?php elseif($customerPasswordRecoveryRequest && $customerPasswordRecoveryRequest['status'] === 'approved' && (int) $customerPasswordRecoveryRequest['approval_active'] === 1): ?>
            <div class="alert alert-success" role="status">
                <strong>Password reset approved.</strong> You can change your password now. This approval expires in 30 minutes.
                <button type="button" class="btn btn-primary shadow font-weight-bold ml-2" id="open-approved-password-reset" style="border:2px solid #fff;cursor:pointer;">
                    <i class="fas fa-hand-pointer mr-1" aria-hidden="true"></i> Change password
                </button>
            </div>
        <?php elseif($customerPasswordRecoveryRequest && $customerPasswordRecoveryRequest['status'] === 'approved'): ?>
            <div class="alert alert-warning" role="status" id="password-recovery-status">
                <strong>Password reset approval expired.</strong> Request approval again to reset your password.
                <button type="button" class="btn btn-outline-dark btn-sm ml-2" id="request-password-approval-again">Request approval again</button>
            </div>
        <?php elseif($customerPasswordRecoveryRequest && $customerPasswordRecoveryRequest['status'] === 'rejected'): ?>
            <div class="alert alert-danger" role="status"><strong>Password reset request declined.</strong> Please contact the shop if you need help.</div>
        <?php endif; ?>
        <?php if($customerNotificationsError !== ''): ?>
            <div class="alert alert-warning" role="status"><?= htmlspecialchars($customerNotificationsError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php foreach($customerNotifications as $notification): ?>
            <div class="alert alert-<?= (int) $notification['status'] === 1 ? 'success' : 'danger' ?> alert-dismissible fade show customer-booking-notification" role="status" aria-live="polite" data-notification-id="<?= (int) $notification['id'] ?>">
                <button type="button" class="close dismiss-customer-notification" aria-label="Dismiss notification">
                    <span aria-hidden="true">&times;</span>
                </button>
                <strong>Booking <?= htmlspecialchars((string) $notification['code'], ENT_QUOTES, 'UTF-8') ?>:</strong>
                <?= htmlspecialchars((string) $notification['message'], ENT_QUOTES, 'UTF-8') ?>
                <?php if((int) $notification['status'] === 1): ?>
                    <strong><?= htmlspecialchars(date('F d, Y', strtotime($notification['appointment_at'])), ENT_QUOTES, 'UTF-8') ?></strong>
                    at <strong><?= htmlspecialchars(date('g:i A', strtotime($notification['appointment_at'])), ENT_QUOTES, 'UTF-8') ?></strong>.
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if($customerBookingsError !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= htmlspecialchars($customerBookingsError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if($customerBookingsError === '' && $customerBookings): ?>
            <div class="row">
                <?php foreach($customerBookings as $booking): ?>
                    <?php
                        $bookingStatus = (int) $booking['status'];
                        $canReschedule = in_array($bookingStatus, [0, 1], true)
                            && (int) $booking['is_archived'] === 0
                            && $booking['service_started_at'] === null
                            && strtotime($booking['schedule']) > time();
                    ?>
                    <div class="col-lg-6 mb-3 customer-booking-card" data-booking-id="<?= (int) $booking['id'] ?>">
                        <article class="card card-default h-100 shadow-sm">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <h3 class="h5 mb-2">Booking <?= htmlspecialchars((string) $booking['code'], ENT_QUOTES, 'UTF-8') ?></h3>
                                    <div class="d-flex align-items-start">
                                        <span class="badge badge-<?= $bookingStatus === 1 ? 'primary' : ($bookingStatus === 0 ? 'warning' : (in_array($bookingStatus, [2, 3], true) ? 'info' : ($bookingStatus === 4 ? 'success' : ($bookingStatus === 5 ? 'danger' : 'secondary')))) ?> mt-1">
                                            <?= htmlspecialchars($statusNames[$bookingStatus] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <button type="button" class="close ml-2 dismiss-customer-booking" aria-label="Remove booking from notifications" title="Remove from notifications">
                                            <span aria-hidden="true">&times;</span>
                                        </button>
                                    </div>
                                </div>
                                <p class="mb-1"><strong>Appointment:</strong> <?= htmlspecialchars(date('M d, Y g:i A', strtotime($booking['schedule'])), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mb-1"><strong>Queue Number:</strong> <?= (int) $booking['queue_number'] ?></p>
                                <?php if(!empty($booking['service_started_at'])): ?>
                                    <p class="mb-1"><strong>Carwash started:</strong> <?= htmlspecialchars(date('M d, Y g:i A', strtotime($booking['service_started_at'])), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                                <p class="mb-1"><strong>Estimated service duration:</strong> <?= htmlspecialchars(format_duration_minutes($booking['total_duration_minutes']), ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mb-1"><strong>Plate:</strong> <?= htmlspecialchars((string) $booking['plate_number'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mb-3"><strong>Payment:</strong> <?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $booking['payment_status'])), ENT_QUOTES, 'UTF-8') ?></p>
                                <?php if($canReschedule): ?>
                                    <a class="btn btn-outline-primary" href="./?p=reschedule_booking&amp;id=<?= (int) $booking['id'] ?>">Reschedule</a>
                                <?php else: ?>
                                    <small class="text-muted">This booking is no longer eligible for rescheduling.</small>
                                <?php endif; ?>
                            </div>
                        </article>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<script>
    $(function(){
        $('.toggle-password-visibility').on('click', function(){
            var button = $(this)
            var input = $('#' + button.data('target'))
            var showPassword = input.attr('type') === 'password'
            input.attr('type', showPassword ? 'text' : 'password')
            button.attr('aria-pressed', showPassword ? 'true' : 'false')
                .attr('aria-label', showPassword ? 'Hide password' : (input.attr('id') === 'approved-reset-password' ? 'Show new password' : 'Show password confirmation'))
            button.find('i').toggleClass('fa-eye', !showPassword).toggleClass('fa-eye-slash', showPassword)
        })
        $('#open-approved-password-reset').on('click', function(){
            $('#approved-password-reset-modal').modal('show')
        })
        $('#approved-password-reset-form').on('submit', function(event){
            event.preventDefault()
            var button = $('#approved-reset-submit')
            button.prop('disabled', true).text('Updating password...')
            $.ajax({
                url: _base_url_ + 'classes/CustomerPasswordRecovery.php?f=complete_approved_reset',
                method: 'POST',
                dataType: 'json',
                data: {
                    new_password: $('#approved-reset-password').val(),
                    confirm_password: $('#approved-reset-confirm-password').val(),
                    csrf_token: <?= json_encode((string) ($_SESSION['customer_auth_csrf'] ?? '')) ?>
                },
                error: function(xhr, textStatus, errorThrown){
                    console.error('Could not complete approved password reset.', {
                        status: xhr.status,
                        textStatus: textStatus,
                        error: errorThrown
                    })
                    $('#approved-reset-feedback')
                        .removeClass('alert-success')
                        .addClass('alert-danger')
                        .text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Could not reset the password. Please try again.')
                        .prop('hidden', false)
                    button.prop('disabled', false).text('Change password')
                },
                success: function(response){
                    if(response && response.status === 'success'){
                        window.location.href = _base_url_ + '#customer-login'
                        return
                    }
                    $('#approved-reset-feedback')
                        .removeClass('alert-success')
                        .addClass('alert-danger')
                        .text(response && response.message ? response.message : 'Could not reset the password. Please try again.')
                        .prop('hidden', false)
                    button.prop('disabled', false).text('Change password')
                }
            })
        })
        $('#request-password-approval-again').on('click', function(){
            var button = $(this)
            button.prop('disabled', true).text('Requesting approval...')
            $.ajax({
                url: _base_url_ + 'classes/CustomerPasswordRecovery.php?f=request_admin_approval',
                method: 'POST',
                dataType: 'json',
                data: {
                    csrf_token: <?= json_encode((string) ($_SESSION['customer_auth_csrf'] ?? '')) ?>
                },
                error: function(xhr, textStatus, errorThrown){
                    console.error('Could not request password reset approval.', {
                        status: xhr.status,
                        textStatus: textStatus,
                        error: errorThrown
                    })
                    $('#password-recovery-status')
                        .removeClass('alert-info alert-success')
                        .addClass('alert-danger')
                        .text(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Could not request approval. Please try again.')
                    button.prop('disabled', false).text('Request approval again')
                },
                success: function(response){
                    if(response && response.status === 'success'){
                        $('#password-recovery-status')
                            .removeClass('alert-warning alert-danger')
                            .addClass('alert-info')
                            .text('Your password reset request was sent to the admin. Check back here after it is reviewed.')
                        button.prop('hidden', true)
                        return
                    }
                    $('#password-recovery-status')
                        .removeClass('alert-warning alert-info')
                        .addClass('alert-danger')
                        .text(response && response.message ? response.message : 'Could not request approval. Please try again.')
                    button.prop('disabled', false).text('Request approval again')
                }
            })
        })
        $('.dismiss-customer-notification').on('click', function(){
            var button = $(this)
            var notification = button.closest('.customer-booking-notification')
            button.prop('disabled', true)
            $.ajax({
                url: _base_url_ + 'classes/CustomerNotifications.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    notification_id: notification.data('notification-id'),
                    csrf_token: <?= json_encode((string) ($_SESSION['customer_auth_csrf'] ?? '')) ?>
                },
                error: function(xhr, textStatus, errorThrown){
                    console.error('Could not dismiss customer notification.', {
                        status: xhr.status,
                        textStatus: textStatus,
                        error: errorThrown
                    })
                    notification.prepend(
                        $('<div class="small text-danger mb-2" role="alert">')
                            .text('Could not remove this notification. Please try again.')
                    )
                    button.prop('disabled', false)
                },
                success: function(response){
                    if(response && response.status === 'success'){
                        notification.alert('close')
                        return
                    }
                    notification.prepend(
                        $('<div class="small text-danger mb-2" role="alert">')
                            .text(response && response.msg ? response.msg : 'Could not remove this notification. Please try again.')
                    )
                    button.prop('disabled', false)
                }
            })
        })
        $('.dismiss-customer-booking').on('click', function(){
            var button = $(this)
            var card = button.closest('.customer-booking-card')
            button.prop('disabled', true)
            $.ajax({
                url: _base_url_ + 'classes/CustomerNotifications.php',
                method: 'POST',
                dataType: 'json',
                data: {
                    booking_id: card.data('booking-id'),
                    csrf_token: <?= json_encode((string) ($_SESSION['customer_auth_csrf'] ?? '')) ?>
                },
                error: function(xhr, textStatus, errorThrown){
                    console.error('Could not remove customer booking from notifications.', {
                        status: xhr.status,
                        textStatus: textStatus,
                        error: errorThrown
                    })
                    card.find('.booking-dismiss-error').remove()
                    card.find('.card-body').prepend(
                        $('<div class="alert alert-danger py-2 booking-dismiss-error" role="alert">')
                            .text('Could not remove this booking from notifications. Please try again.')
                    )
                    button.prop('disabled', false)
                },
                success: function(response){
                    if(response && response.status === 'success'){
                        card.fadeOut(150, function(){
                            card.remove()
                        })
                        return
                    }
                    card.find('.booking-dismiss-error').remove()
                    card.find('.card-body').prepend(
                        $('<div class="alert alert-danger py-2 booking-dismiss-error" role="alert">')
                            .text(response && response.msg ? response.msg : 'Could not remove this booking from notifications. Please try again.')
                    )
                    button.prop('disabled', false)
                }
            })
        })
    })
</script>
<?php if($customerPasswordRecoveryRequest && $customerPasswordRecoveryRequest['status'] === 'approved' && (int) $customerPasswordRecoveryRequest['approval_active'] === 1): ?>
<div class="modal fade" id="approved-password-reset-modal" tabindex="-1" role="dialog" aria-labelledby="approved-password-reset-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form id="approved-password-reset-form">
                <div class="modal-header">
                    <h5 class="modal-title" id="approved-password-reset-title">Change Password</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <div id="approved-reset-feedback" class="alert" role="alert" hidden></div>
                    <div class="form-group">
                        <label for="approved-reset-password">New password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="approved-reset-password" minlength="8" maxlength="72" autocomplete="new-password" required>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary toggle-password-visibility" data-target="approved-reset-password" aria-label="Show new password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                            </div>
                        </div>
                        <small class="form-text text-muted">Use 8 to 72 characters.</small>
                    </div>
                    <div class="form-group mb-0">
                        <label for="approved-reset-confirm-password">Confirm new password</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="approved-reset-confirm-password" minlength="8" maxlength="72" autocomplete="new-password" required>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary toggle-password-visibility" data-target="approved-reset-confirm-password" aria-label="Show password confirmation" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="approved-reset-submit">Change password</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
