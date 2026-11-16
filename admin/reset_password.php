<?php
require_once('../config.php');
if(empty($_SESSION['password_reset_csrf'])){
	$_SESSION['password_reset_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['password_reset_csrf'];
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$validTokenFormat = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Choose a new password | <?php echo htmlspecialchars($_settings->info('name'), ENT_QUOTES, 'UTF-8') ?></title>
	<link rel="stylesheet" href="<?php echo htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>plugins/fontawesome-free/css/all.min.css">
	<link rel="stylesheet" href="<?php echo htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>dist/css/adminlte.css">
	<link rel="stylesheet" href="<?php echo htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>dist/css/custom.css">
	<style>
		body.login-page {
			min-height: 100vh;
			padding: 2rem 1rem;
			background: linear-gradient(rgba(0, 0, 0, .48), rgba(0, 0, 0, .58)), url("<?php echo htmlspecialchars(validate_image($_settings->info('cover')), ENT_QUOTES, 'UTF-8') ?>") center / cover no-repeat;
		}
		.reset-box { width: 100%; max-width: 430px; margin: 0 auto; }
		.reset-title { margin: 0 auto 1.25rem; color: #fff; font-size: clamp(1.75rem, 5vw, 3rem); text-align: center; text-shadow: 0 2px 8px #000; }
	</style>
	<script>var _base_url_ = <?php echo json_encode(base_url) ?>;</script>
</head>
<body class="hold-transition login-page">
	<h1 class="reset-title"><b><?php echo htmlspecialchars($_settings->info('name'), ENT_QUOTES, 'UTF-8') ?></b></h1>
	<main class="reset-box">
		<div class="card card-primary">
			<div class="card-body">
				<h2 class="h4 text-center">Choose a new password</h2>
				<div id="reset-feedback" class="alert" role="status" aria-live="polite" hidden></div>
				<?php if($validTokenFormat): ?>
					<p class="text-muted">Choose a password with at least 12 characters. This reset link can only be used once.</p>
					<form id="reset-password-form">
						<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
						<input type="hidden" name="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
						<div class="form-group">
							<label for="password">New password</label>
							<input type="password" class="form-control" id="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required autofocus>
						</div>
						<div class="form-group">
							<label for="password-confirmation">Confirm new password</label>
							<input type="password" class="form-control" id="password-confirmation" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" required>
						</div>
						<button type="submit" class="btn btn-primary btn-block" id="reset-password-submit">Change password</button>
					</form>
				<?php else: ?>
					<p class="text-danger">This reset link is invalid. <a href="<?php echo htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>admin/forgot_password.php">Request a new one</a>.</p>
				<?php endif; ?>
				<p class="text-center mt-3 mb-0"><a href="<?php echo htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>admin/login.php">Back to sign in</a></p>
			</div>
		</div>
	</main>
	<script src="<?php echo htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>plugins/jquery/jquery.min.js"></script>
	<?php if($validTokenFormat): ?>
	<script>
		$('#reset-password-form').on('submit', function(event) {
			event.preventDefault();
			var $button = $('#reset-password-submit');
			var $feedback = $('#reset-feedback');
			$button.prop('disabled', true).text('Updating...');
			$feedback.prop('hidden', true).removeClass('alert-success alert-danger').text('');
			$.ajax({
				url: _base_url_ + 'classes/PasswordReset.php?f=reset',
				method: 'POST',
				data: $(this).serialize(),
				dataType: 'json'
			}).done(function(response) {
				$feedback.addClass(response.status === 'success' ? 'alert-success' : 'alert-danger')
					.text(response.message).prop('hidden', false);
				if (response.status === 'success' || response.status === 'expired') {
					$('#reset-password-form').remove();
				}
			}).fail(function(xhr) {
				var response = xhr.responseJSON;
				$feedback.addClass('alert-danger')
					.text(response && response.message ? response.message : 'Unable to update the password. Request a new reset link and try again.')
					.prop('hidden', false);
			}).always(function() {
				$button.prop('disabled', false).text('Change password');
			});
		});
	</script>
	<?php endif; ?>
</body>
</html>
