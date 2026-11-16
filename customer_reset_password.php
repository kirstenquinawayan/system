<?php
require_once 'config.php';
if(empty($_SESSION['customer_auth_csrf'])){
	$_SESSION['customer_auth_csrf'] = bin2hex(random_bytes(32));
}
$token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
$validToken = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Reset Password | <?= htmlspecialchars((string) $_settings->info('name'), ENT_QUOTES, 'UTF-8') ?></title>
	<link rel="stylesheet" href="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>plugins/fontawesome-free/css/all.min.css">
	<link rel="stylesheet" href="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>dist/css/adminlte.css">
	<link rel="stylesheet" href="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>assets/css/public-ui.css">
	<style>
		body { min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; background: #0a1020; color: #e8eef8; }
		.reset-card { width: 100%; max-width: 440px; border: 1px solid rgba(148,163,184,.25); border-radius: 1rem; background: #111d31; }
		.reset-card .card-body { padding: clamp(1.5rem,5vw,2.25rem); }
	</style>
	<script>var _base_url_ = <?= json_encode(base_url) ?>;</script>
</head>
<body>
	<main class="card reset-card">
		<div class="card-body">
			<h1 class="h3 text-center">Reset Password</h1>
			<div id="reset-feedback" class="alert" role="status" aria-live="polite" hidden></div>
			<?php if($validToken): ?>
				<p class="text-muted">Choose a new password of 8 to 72 characters.</p>
				<form id="customer-reset-form">
					<input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) $_SESSION['customer_auth_csrf'], ENT_QUOTES, 'UTF-8') ?>">
					<input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
					<div class="form-group">
						<label for="reset-new-password">New password</label>
						<div class="input-group">
							<input type="password" class="form-control" id="reset-new-password" name="new_password" minlength="8" maxlength="72" autocomplete="new-password" required>
							<div class="input-group-append">
								<button type="button" class="btn btn-outline-secondary toggle-password-visibility" data-target="reset-new-password" aria-label="Show new password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
							</div>
						</div>
					</div>
					<div class="form-group">
						<label for="reset-confirm-password">Confirm new password</label>
						<div class="input-group">
							<input type="password" class="form-control" id="reset-confirm-password" name="confirm_password" minlength="8" maxlength="72" autocomplete="new-password" required>
							<div class="input-group-append">
								<button type="button" class="btn btn-outline-secondary toggle-password-visibility" data-target="reset-confirm-password" aria-label="Show password confirmation" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
							</div>
						</div>
					</div>
					<button class="btn btn-primary btn-block" id="reset-submit" type="submit">Reset password</button>
				</form>
			<?php else: ?>
				<p class="text-danger">This reset link is invalid. Request a new one from the Forgot Password page.</p>
			<?php endif; ?>
			<p class="text-center mt-3 mb-0"><a href="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>">Return to Kwacky-Kleen</a></p>
		</div>
	</main>
	<script src="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>plugins/jquery/jquery.min.js"></script>
	<?php if($validToken): ?>
	<script>
		$('.toggle-password-visibility').on('click', function(){
			var button = $(this);
			var input = $('#' + button.data('target'));
			var showPassword = input.attr('type') === 'password';
			input.attr('type', showPassword ? 'text' : 'password');
			button.attr('aria-pressed', showPassword ? 'true' : 'false')
				.attr('aria-label', showPassword ? 'Hide password' : (input.attr('id') === 'reset-new-password' ? 'Show new password' : 'Show password confirmation'));
			button.find('i').toggleClass('fa-eye', !showPassword).toggleClass('fa-eye-slash', showPassword);
		});
		$('#customer-reset-form').on('submit', function(event){
			event.preventDefault();
			var button = $('#reset-submit');
			var feedback = $('#reset-feedback');
			button.prop('disabled', true).text('Updating...');
			feedback.prop('hidden', true).removeClass('alert-success alert-danger').text('');
			$.ajax({
				url: _base_url_ + 'classes/CustomerPasswordRecovery.php?f=reset_email',
				method: 'POST',
				data: $(this).serialize(),
				dataType: 'json'
			}).done(function(response){
				feedback.addClass(response.status === 'success' ? 'alert-success' : 'alert-danger').text(response.message).prop('hidden', false);
				if(response.status === 'success' || response.status === 'expired'){
					$('#customer-reset-form').remove();
				}
			}).fail(function(xhr){
				var response = xhr.responseJSON;
				feedback.addClass('alert-danger').text(response && response.message ? response.message : 'Unable to reset the password. Please try again.').prop('hidden', false);
			}).always(function(){
				button.prop('disabled', false).text('Reset password');
			});
		});
	</script>
	<?php endif; ?>
</body>
</html>
