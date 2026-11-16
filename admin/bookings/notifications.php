<?php
$adminRecoveryCsrf = $_SESSION['password_recovery_admin_csrf'] ?? '';
if($adminRecoveryCsrf === ''){
    $adminRecoveryCsrf = bin2hex(random_bytes(32));
    $_SESSION['password_recovery_admin_csrf'] = $adminRecoveryCsrf;
}
$passwordRecoveryQuery = $conn->query(
    "SELECT r.id, r.requested_at, c.full_name, c.email, c.contact
     FROM customer_password_recovery_requests r
     INNER JOIN customer_accounts c ON c.id = r.customer_id
     WHERE r.status = 'pending'
     ORDER BY r.requested_at ASC, r.id ASC"
);
if(!$passwordRecoveryQuery){
    error_log('Admin customer password recovery requests query failed: ' . $conn->error);
}
$notificationQuery = $conn->query(
    "SELECT id, code, client_name, contact, schedule, queue_number, status
     FROM booking_list
     WHERE customer_id IS NOT NULL AND is_archived = 0 AND is_deleted = 0
       AND status IN (0, 5)
     ORDER BY status ASC, schedule ASC, id DESC"
);
if(!$notificationQuery){
    error_log('Customer booking notifications query failed: ' . $conn->error);
}
?>
<div class="card card-outline card-warning">
    <div class="card-header">
        <h3 class="card-title">Password Reset Requests</h3>
    </div>
    <div class="card-body">
        <div class="alert alert-info" role="note">Approve a request to let the customer reset their password from their Notification page. Approval expires after 30 minutes.</div>
        <?php if(!$passwordRecoveryQuery): ?>
            <div class="alert alert-danger" role="alert">Password reset requests are temporarily unavailable. Please try again later.</div>
        <?php elseif($passwordRecoveryQuery->num_rows === 0): ?>
            <div class="alert alert-secondary" role="status">There are no password reset requests waiting for review.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped table-bordered" id="password-recovery-requests">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Registered email</th>
                            <th>Registered mobile</th>
                            <th>Requested</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($request = $passwordRecoveryQuery->fetch_assoc()): ?>
                            <tr data-request-id="<?= (int) $request['id'] ?>">
                                <td><?= htmlspecialchars((string) $request['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $request['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $request['contact'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($request['requested_at'])), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-success password-recovery-decision" data-decision="approve">Approve</button>
                                    <button type="button" class="btn btn-sm btn-outline-danger password-recovery-decision" data-decision="reject">Decline</button>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title">Customer Booking Notifications</h3>
    </div>
    <div class="card-body">
        <div class="alert alert-info" role="note">
            Approve a booking to notify the customer on the website. The approved appointment date and time will appear on the customer's Notification page.
        </div>
        <script>
        $(function(){
            $('.password-recovery-decision').on('click', function(){
                var button = $(this)
                var row = button.closest('tr')
                var decision = button.data('decision')
                button.prop('disabled', true)
                $.ajax({
                    url: _base_url_ + 'classes/CustomerPasswordRecovery.php?f=admin_decide',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        request_id: row.data('request-id'),
                        decision: decision,
                        csrf_token: <?= json_encode($adminRecoveryCsrf) ?>
                    },
                    error: function(xhr, textStatus, errorThrown){
                        console.error('Could not update password reset request.', {
                            status: xhr.status,
                            textStatus: textStatus,
                            error: errorThrown
                        })
                        alert_toast(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Could not update the request.', 'error')
                        button.prop('disabled', false)
                    },
                    success: function(response){
                        if(response && response.status === 'success'){
                            alert_toast(response.message, 'success')
                            row.fadeOut(150, function(){row.remove()})
                            return
                        }
                        alert_toast(response && response.message ? response.message : 'Could not update the request.', 'error')
                        button.prop('disabled', false)
                    }
                })
            })
        })
        </script>
        <?php if(!$notificationQuery): ?>
            <div class="alert alert-danger" role="alert">Customer notifications are temporarily unavailable. Please try again later.</div>
        <?php elseif($notificationQuery->num_rows === 0): ?>
            <div class="alert alert-secondary" role="status">There are no customer bookings waiting for approval or showing a cancellation update.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>Booking</th>
                            <th>Customer</th>
                            <th>Contact</th>
                            <th>Approved Appointment</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($booking = $notificationQuery->fetch_assoc()): ?>
                            <?php $bookingStatus = (int) $booking['status']; ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $booking['code'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $booking['client_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $booking['contact'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?= htmlspecialchars(date('M d, Y', strtotime($booking['schedule'])), ENT_QUOTES, 'UTF-8') ?><br>
                                    <?= htmlspecialchars(date('g:i A', strtotime($booking['schedule'])), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $bookingStatus === 1 ? 'success' : ($bookingStatus === 5 ? 'danger' : 'warning') ?>">
                                        <?= $bookingStatus === 1 ? 'Approved' : ($bookingStatus === 5 ? 'Cancelled' : 'Waiting for approval') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if($bookingStatus === 5): ?>
                                        <span class="text-muted">Cancellation message shown to customer</span>
                                    <?php else: ?>
                                    <button
                                        type="button"
                                        class="btn btn-sm <?= $bookingStatus === 1 ? 'btn-outline-primary' : 'btn-primary' ?>"
                                        onclick='uni_modal("Update Customer Booking", "bookings/update_status.php?id=<?= (int) $booking["id"] ?>&amp;mode=decision")'>
                                        Approve / Cancel
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
