<?php
require_once 'config.php';
if(empty($_SESSION['customer_auth_csrf'])){
	$_SESSION['customer_auth_csrf'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Forgot Password | <?= htmlspecialchars((string) $_settings->info('name'), ENT_QUOTES, 'UTF-8') ?></title>
	<link rel="stylesheet" href="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>plugins/fontawesome-free/css/all.min.css">
	<link rel="stylesheet" href="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>dist/css/adminlte.css">
	<link rel="stylesheet" href="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>assets/css/public-ui.css">
	<style>
		body { min-height: 100vh; display: grid; place-items: center; padding: 1.5rem; background: #0a1020; color: #e8eef8; }
		.recovery-card { width: 100%; max-width: 460px; border: 1px solid rgba(148,163,184,.25); border-radius: 1rem; background: #111d31; }
		.recovery-card .card-body { padding: clamp(1.5rem,5vw,2.25rem); }
	</style>
	<script>var _base_url_ = <?= json_encode(base_url) ?>;</script>
</head>
<body>
	<main class="card recovery-card">
		<div class="card-body">
			<h1 class="h3 text-center">Forgot Password?</h1>
			<p class="text-center">Password reset requires admin approval. Sign in, request approval from Change Password, then check your Notification page.</p>
			<a class="btn btn-primary btn-block" href="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>#customer-login">Sign in</a>
			<p class="text-center mt-3 mb-0"><a href="<?= htmlspecialchars(base_url, ENT_QUOTES, 'UTF-8') ?>">Return to Kwacky-Kleen</a></p>
		</div>
	</main>
</body>
</html>
