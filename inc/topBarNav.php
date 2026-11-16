<?php
if(empty($_SESSION['customer_auth_csrf'])){
    $_SESSION['customer_auth_csrf'] = bin2hex(random_bytes(32));
}
$customerNotificationCount = 0;
if(isset($_SESSION['customer_userdata']['id'])){
    $customerNotificationCustomerId = (int) $_SESSION['customer_userdata']['id'];
    $customerNotificationCountStatement = $conn->prepare(
        "SELECT COUNT(*) FROM customer_booking_notifications
         WHERE customer_id = ? AND read_at IS NULL AND dismissed_at IS NULL"
    );
    if(!$customerNotificationCountStatement){
        error_log('Customer notification count prepare failed: ' . $conn->error);
    }else{
        $customerNotificationCountStatement->bind_param('i', $customerNotificationCustomerId);
        if(!$customerNotificationCountStatement->execute()){
            error_log('Customer notification count query failed: ' . $customerNotificationCountStatement->error);
        }else{
            $customerNotificationCountStatement->bind_result($customerNotificationCount);
            $customerNotificationCountStatement->fetch();
            $customerNotificationCount = (int) $customerNotificationCount;
        }
        $customerNotificationCountStatement->close();
    }
    $customerPasswordApprovalStatement = $conn->prepare(
        "SELECT id FROM customer_password_recovery_requests
         WHERE customer_id = ? AND status = 'approved' AND expires_at > NOW()"
    );
    if(!$customerPasswordApprovalStatement){
        error_log('Customer password approval notification count prepare failed: ' . $conn->error);
    }else{
        $customerPasswordApprovalStatement->bind_param('i', $customerNotificationCustomerId);
        if(!$customerPasswordApprovalStatement->execute()){
            error_log('Customer password approval notification count failed: ' . $customerPasswordApprovalStatement->error);
        }else{
            $customerPasswordApprovalStatement->bind_result($customerPasswordApprovalId);
            $seenPasswordApprovalIds = [];
            if(isset($_SESSION['customer_seen_password_approval_ids'][$customerNotificationCustomerId]) && is_array($_SESSION['customer_seen_password_approval_ids'][$customerNotificationCustomerId])){
                $seenPasswordApprovalIds = $_SESSION['customer_seen_password_approval_ids'][$customerNotificationCustomerId];
            }
            while($customerPasswordApprovalStatement->fetch()){
                if(!isset($seenPasswordApprovalIds[(int) $customerPasswordApprovalId])){
                    $customerNotificationCount++;
                }
            }
        }
        $customerPasswordApprovalStatement->close();
    }
}
?>
<style>
    .customer-notification-link {
        position: relative;
    }
    .customer-notification-count {
        position: absolute;
        top: 0;
        right: 0;
        min-width: 16px;
        padding: 2px 4px;
        border-radius: 10px;
        background: #dc3545;
        color: #fff;
        font-size: 10px;
        font-weight: 700;
        line-height: 1;
        text-align: center;
    }
</style>
<nav class="navbar navbar-expand-lg navbar-dark bg-gradient-primary">
            <div class="container px-4 px-lg-5 ">
                <a class="navbar-brand" href="./">
                    <img src="<?php echo htmlspecialchars(validate_image($_settings->info('logo')), ENT_QUOTES, 'UTF-8') ?>" data-fallback-src="<?php echo htmlspecialchars(validate_image($_settings->info('cover')), ENT_QUOTES, 'UTF-8') ?>" width="38" height="38" alt="" loading="eager" onerror="if(this.dataset.fallbackSrc && this.src !== this.dataset.fallbackSrc){this.src=this.dataset.fallbackSrc}else{this.hidden=true}">
                    <span><?php echo htmlspecialchars((string) $_settings->info('short_name'), ENT_QUOTES, 'UTF-8') ?></span>
                </a>
                <button class="navbar-toggler btn btn-sm ml-auto" type="button" data-toggle="collapse" data-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>
                
                <div class="collapse navbar-collapse" id="navbarSupportedContent">
                        <ul class="navbar-nav mr-auto mb-2 mb-lg-0 ml-lg-4">
                            <?php if(isset($_SESSION['customer_userdata']) && is_array($_SESSION['customer_userdata'])): ?>
                                <li class="nav-item"><a class="nav-link text-white" aria-current="page" href="./">Home</a></li>
                            <?php endif; ?>
                            <li class="nav-item">
                                <a class="nav-link text-white<?= isset($_SESSION['customer_userdata']) && is_array($_SESSION['customer_userdata']) ? '' : ' customer-booking-login-link' ?>" href="<?= isset($_SESSION['customer_userdata']) && is_array($_SESSION['customer_userdata']) ? './?p=booking&amp;new_booking=1' : './?booking_login=1#customer-login' ?>">Booking</a>
                            </li>
                            <li class="nav-item"><a class="nav-link text-white" href="./#home-services">Services</a></li>
                            <?php if(isset($_SESSION['customer_userdata']) && is_array($_SESSION['customer_userdata'])): ?>
                            <li class="nav-item">
                                <a class="nav-link text-white customer-notification-link" href="./?p=my_bookings">
                                    Notification
                                    <?php if($customerNotificationCount > 0 && (!isset($page) || $page !== 'my_bookings')): ?>
                                        <span class="customer-notification-count" aria-label="<?= $customerNotificationCount ?> unread notifications"><?= $customerNotificationCount > 99 ? '99+' : $customerNotificationCount ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <?php endif; ?>
                        </ul>
                    <div class="d-flex align-items-center ml-lg-auto">
                        <?php if(isset($_SESSION['customer_userdata']) && is_array($_SESSION['customer_userdata'])): ?>
                            <div class="dropdown">
                                <button class="login-btn font-weight-bolder mx-2 dropdown-toggle" type="button" id="customerSettingsDropdown" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                    Hi, <?= htmlspecialchars((string) ($_SESSION['customer_userdata']['full_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                                <div class="dropdown-menu dropdown-menu-right" aria-labelledby="customerSettingsDropdown">
                                    <button class="dropdown-item" type="button" data-toggle="modal" data-target="#customerChangePasswordModal">
                                        <i class="fas fa-key mr-2"></i> Change Password
                                    </button>
                                    <div class="customer-theme-preference">
                                        <div class="customer-theme-preference-copy">
                                            <i class="fas fa-moon mr-2"></i>
                                            <span><strong>Dark mode</strong></span>
                                        </div>
                                        <label class="customer-theme-switch" for="customer-dark-mode-toggle">
                                            <span class="sr-only">Toggle dark mode</span>
                                            <input type="checkbox" id="customer-dark-mode-toggle" role="switch" aria-label="Dark mode">
                                            <span class="customer-theme-switch-track"></span>
                                        </label>
                                    </div>
                                    <div class="dropdown-divider"></div>
                                    <form method="post" action="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>classes/CustomerAuth.php?f=logout" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['customer_auth_csrf'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="dropdown-item" type="submit"><i class="fas fa-sign-out-alt mr-2"></i> Log out</button>
                                    </form>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </nav>
<?php if(isset($_SESSION['customer_password_feedback'])): ?>
    <div class="container mt-3">
        <div class="alert alert-<?= $_SESSION['customer_password_feedback']['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars((string) $_SESSION['customer_password_feedback']['message'], ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
    </div>
    <?php unset($_SESSION['customer_password_feedback']); ?>
<?php endif; ?>
<?php if(isset($_SESSION['customer_userdata']) && is_array($_SESSION['customer_userdata'])): ?>
<div class="modal fade" id="customerChangePasswordModal" tabindex="-1" role="dialog" aria-labelledby="customerChangePasswordTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <form method="post" action="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>classes/CustomerAuth.php?f=change_password">
                <div class="modal-header">
                    <h5 class="modal-title" id="customerChangePasswordTitle">Change Password</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['customer_auth_csrf'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <div id="customer-change-password-fields">
                        <div class="form-group">
                            <label for="customer-current-password">Current password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="customer-current-password" name="current_password" autocomplete="current-password" required>
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-secondary toggle-password-visibility" data-target="customer-current-password" aria-label="Show current password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="customer-new-password">New password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="customer-new-password" name="new_password" minlength="8" maxlength="72" autocomplete="new-password" required>
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-secondary toggle-password-visibility" data-target="customer-new-password" aria-label="Show new password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                </div>
                            </div>
                            <small class="form-text text-muted">Use 8 to 72 characters.</small>
                        </div>
                        <div class="form-group mb-2">
                            <label for="customer-confirm-password">Confirm new password</label>
                            <div class="input-group">
                                <input type="password" class="form-control" id="customer-confirm-password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required>
                                <div class="input-group-append">
                                    <button type="button" class="btn btn-outline-secondary toggle-password-visibility" data-target="customer-confirm-password" aria-label="Show password confirmation" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
                                </div>
                            </div>
                        </div>
                        <a href="#" id="customer-forgot-password-link">Forgot password?</a>
                    </div>
                    <div id="customer-forgot-password-fields" hidden>
                        <p class="text-muted">Send a password reset request to the admin. After approval, you can reset it from your Notification page.</p>
                        <div id="customer-recovery-feedback" class="alert" role="status" aria-live="polite" hidden></div>
                        <div id="customer-recovery-methods">
                            <button type="button" class="btn btn-outline-primary btn-block" id="customer-request-password-reset">Request admin approval</button>
                        </div>
                        <button type="button" class="btn btn-link px-0 mt-2" id="customer-recovery-back">Back to change password</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="customer-update-password-button">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?php if(isset($_SESSION['customer_userdata']) && is_array($_SESSION['customer_userdata'])): ?>
<script>
$(function(){
    $('.toggle-password-visibility').on('click', function(){
        var button = $(this)
        var input = $('#' + button.data('target'))
        var showPassword = input.attr('type') === 'password'
        input.attr('type', showPassword ? 'text' : 'password')
        button.attr('aria-pressed', showPassword ? 'true' : 'false')
            .attr('aria-label', showPassword ? 'Hide password' : (input.attr('id') === 'customer-new-password' ? 'Show new password' : (input.attr('id') === 'customer-current-password' ? 'Show current password' : 'Show password confirmation')))
        button.find('i').toggleClass('fa-eye', !showPassword).toggleClass('fa-eye-slash', showPassword)
    })
    var recoveryUrl = <?= json_encode(base_url . 'classes/CustomerPasswordRecovery.php') ?>;
    var csrfToken = <?= json_encode((string) ($_SESSION['customer_auth_csrf'] ?? '')) ?>;
    var feedback = $('#customer-recovery-feedback');
    function showRecoveryMessage(message, success){
        feedback.removeClass('alert-success alert-danger')
            .addClass(success ? 'alert-success' : 'alert-danger')
            .text(message)
            .prop('hidden', false);
    }
    function recoveryRequest(action, data, onSuccess){
        var requestData = $.extend({csrf_token: csrfToken}, data || {});
        $.ajax({
            url: recoveryUrl + '?f=' + encodeURIComponent(action),
            method: 'POST',
            data: requestData,
            dataType: 'json'
        }).done(function(response){
            if(response.status === 'success'){
                onSuccess(response);
            }else{
                showRecoveryMessage(response.message || 'Password recovery failed. Please try again.', false);
            }
        }).fail(function(xhr){
            var response = xhr.responseJSON;
            showRecoveryMessage(response && response.message ? response.message : 'Password recovery is unavailable. Please try again later.', false);
        });
    }
    $('#customer-forgot-password-link').on('click', function(event){
        event.preventDefault();
        $('#customer-change-password-fields').prop('hidden', true);
        $('#customer-change-password-fields').find('input[type="password"]').prop('disabled', true);
        $('#customer-forgot-password-fields').prop('hidden', false);
        $('#customer-update-password-button').prop('hidden', true);
        feedback.prop('hidden', true);
    });
    $('#customer-recovery-back').on('click', function(){
        $('#customer-forgot-password-fields').prop('hidden', true);
        $('#customer-change-password-fields').prop('hidden', false);
        $('#customer-change-password-fields').find('input[type="password"]').prop('disabled', false);
        $('#customer-update-password-button').prop('hidden', false);
    });
    $('#customer-request-password-reset').on('click', function(){
        var button = $(this);
        button.prop('disabled', true).text('Sending request...');
        recoveryRequest('request_admin_approval', {}, function(response){
            showRecoveryMessage(response.message, true);
        });
        window.setTimeout(function(){button.prop('disabled', false).text('Request admin approval');}, 10000);
    });
});
</script>
<?php endif; ?>
<script>
    (function(){
        var storageKey = 'ocwbs-customer-dark-mode'
        var toggle = document.getElementById('customer-dark-mode-toggle')
        var enabled = false
        try {
            enabled = window.localStorage.getItem(storageKey) === 'true'
        } catch(error) {
            console.warn('Could not read the saved display preference.', error)
        }
        document.documentElement.classList.toggle('customer-dark-mode', enabled)
        if(!toggle){
            return
        }
        toggle.checked = enabled
        toggle.addEventListener('change', function(){
            enabled = toggle.checked
            document.documentElement.classList.toggle('customer-dark-mode', enabled)
            try {
                window.localStorage.setItem(storageKey, enabled ? 'true' : 'false')
            } catch(error) {
                console.warn('Could not save the display preference.', error)
            }
        })
    })()
</script>
<script>
  $(function(){
    $('#login-btn').click(function(){
      uni_modal("","login.php")
    })
    $('#navbarResponsive').on('show.bs.collapse', function () {
        $('#mainNav').addClass('navbar-shrink')
    })
    $('#navbarResponsive').on('hidden.bs.collapse', function () {
        if($('body').offset.top == 0)
          $('#mainNav').removeClass('navbar-shrink')
    })
  })

  $('#search-form').submit(function(e){
    e.preventDefault()
     var sTxt = $('[name="search"]').val()
     if(sTxt != '')
      location.href = './?p=products&search='+sTxt;
  })
</script>