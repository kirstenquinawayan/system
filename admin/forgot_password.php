<?php
require_once('../config.php');
if(empty($_SESSION['password_reset_csrf'])){
	$_SESSION['password_reset_csrf'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['password_reset_csrf'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Password reset | <?php echo htmlspecialchars($_settings->info('name'), ENT_QUOTES, 'UTF-8') ?></title>
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
				<h2 class="h4 text-center">Forgot your password?</h2>
				<p class="text-muted text-center">Enter your username or account email. If it matches an account, we will send a one-time reset link to its recovery email.</p>
				<div id="reset-feedback" class="alert" role="status" aria-live="polite" hidden></div>
				<form id="request-reset-form">
					<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
					<div class="form-group">
						<label for="identifier">Username or email</label>
						<input type="text" class="form-control" id="identifier" name="identifier" maxlength="254" autocomplete="username" required autofocus>
					</div>
					<button type="submit" class="btn btn-primary btn-block" id="request-reset-submit">Send reset link</button>
				</form>
				<p class="text-center mt-3 mb-0"><a href="<?php echo htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>admin/login.php">Back to sign in</a></p>
			</div>
		</div>
	</main>
	<script src="<?php echo htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>plugins/jquery/jquery.min.js"></script>
	<script>
		$('#request-reset-form').on('submit', function(event) {
			event.preventDefault();
			var $button = $('#request-reset-submit');
			var $feedback = $('#reset-feedback');
			$button.prop('disabled', true).text('Sending...');
			$feedback.prop('hidden', true).removeClass('alert-success alert-danger').text('');
			$.ajax({
				url: _base_url_ + 'classes/PasswordReset.php?f=request',
				method: 'POST',
				data: $(this).serialize(),
				dataType: 'json'
			}).done(function(response) {
				$feedback.addClass(response.status === 'success' ? 'alert-success' : 'alert-danger')
					.text(response.message).prop('hidden', false);
				if (response.status === 'success') {
					$('#request-reset-form').get(0).reset();
				}
			}).fail(function(xhr) {
				var response = xhr.responseJSON;
				$feedback.addClass('alert-danger')
					.text(response && response.message ? response.message : 'Unable to request a reset right now. Please try again later.')
					.prop('hidden', false);
			}).always(function() {
				$button.prop('disabled', false).text('Send reset link');
			});
		});
	</script>
</body>
</html>
