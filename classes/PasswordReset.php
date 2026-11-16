<?php
require_once '../config.php';

class PasswordReset extends DBConnection {
	private function respond($status, $message){
		header('Content-Type: application/json; charset=utf-8');
		return json_encode(array('status' => $status, 'message' => $message));
	}

	private function smtpConfiguration(){
		$autoload = base_app . 'vendor/autoload.php';
		if(!is_file($autoload)){
			error_log('Password reset email dependency is missing; run Composer install.');
			return null;
		}
		require_once $autoload;

		$host = getenv('OCWBS_SMTP_HOST');
		$port = filter_var(getenv('OCWBS_SMTP_PORT'), FILTER_VALIDATE_INT);
		$username = getenv('OCWBS_SMTP_USERNAME');
		$password = getenv('OCWBS_SMTP_PASSWORD');
		$encryption = strtolower((string) getenv('OCWBS_SMTP_ENCRYPTION'));
		$from = getenv('OCWBS_MAIL_FROM');
		if(!$host || strlen($host) > 255 || preg_match('/[\r\n]/', $host) ||
			$port === false || $port < 1 || $port > 65535 ||
			!in_array($encryption, array('tls', 'ssl', 'none'), true) ||
			!filter_var($from, FILTER_VALIDATE_EMAIL) ||
			(($username && !$password) || (!$username && $password))){
			error_log('Password reset email configuration is incomplete or invalid.');
			return null;
		}

		return array(
			'host' => $host,
			'port' => $port,
			'username' => $username,
			'password' => $password,
			'encryption' => $encryption,
			'from' => $from,
		);
	}

	private function sendResetEmail($email, $resetUrl, $smtp){
		try{
			$mailer = new \PHPMailer\PHPMailer\PHPMailer(true);
			$mailer->isSMTP();
			$mailer->Host = $smtp['host'];
			$mailer->Port = $smtp['port'];
			$mailer->SMTPAuth = (bool) $smtp['username'];
			$mailer->Username = $smtp['username'] ? $smtp['username'] : '';
			$mailer->Password = $smtp['password'] ? $smtp['password'] : '';
			$mailer->SMTPSecure = $smtp['encryption'] === 'tls'
				? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
				: ($smtp['encryption'] === 'ssl' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS : '');
			$mailer->SMTPAutoTLS = $smtp['encryption'] === 'tls';
			$mailer->Timeout = 15;
			$mailer->CharSet = 'UTF-8';
			$mailer->setFrom($smtp['from'], 'Online Car Wash Booking System');
			$mailer->addAddress($email);
			$mailer->Subject = 'Password reset request';
			$mailer->Body = "We received a request to reset the password for your Online Car Wash Booking System account.\r\n\r\n";
			$mailer->Body .= "Use this link within 1 hour to choose a new password:\r\n" . $resetUrl . "\r\n\r\n";
			$mailer->Body .= "If you did not request this change, you can ignore this email. Your password will not change unless the link is used.";
			$mailer->send();
			return true;
		}catch(\PHPMailer\PHPMailer\Exception $error){
			error_log('Password reset email delivery failed: ' . $error->getMessage());
			return false;
		}
	}

	private function validCsrfToken(){
		return isset($_POST['csrf_token'], $_SESSION['password_reset_csrf']) &&
			is_string($_POST['csrf_token']) &&
			hash_equals($_SESSION['password_reset_csrf'], $_POST['csrf_token']);
	}

	public function requestReset(){
		if($_SERVER['REQUEST_METHOD'] !== 'POST'){
			http_response_code(405);
			return $this->respond('failed', 'Please submit the form again.');
		}
		if(!$this->validCsrfToken()){
			http_response_code(400);
			return $this->respond('failed', 'Your session expired. Refresh the page and try again.');
		}
		$smtp = $this->smtpConfiguration();
		if($smtp === null){
			http_response_code(503);
			return $this->respond('failed', 'Password reset email is not configured on this server yet. Please try again later.');
		}

		$identifier = isset($_POST['identifier']) ? trim((string) $_POST['identifier']) : '';
		$genericMessage = 'If an account matches those details, a password reset link will be sent to its recovery email.';
		if($identifier === '' || strlen($identifier) > 254){
			return $this->respond('success', $genericMessage);
		}

		$stmt = $this->conn->prepare("SELECT id, email FROM users WHERE username = ? OR email = ? LIMIT 1");
		if(!$stmt){
			error_log('Password reset account lookup prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Password reset is temporarily unavailable. Please try again later.');
		}
		$stmt->bind_param('ss', $identifier, $identifier);
		if(!$stmt->execute()){
			error_log('Password reset account lookup failed: ' . $stmt->error);
			$stmt->close();
			http_response_code(503);
			return $this->respond('failed', 'Password reset is temporarily unavailable. Please try again later.');
		}
		$stmt->bind_result($userId, $email);
		$found = $stmt->fetch();
		$stmt->close();
		if(!$found || !is_string($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)){
			return $this->respond('success', $genericMessage);
		}

		$cleanup = $this->conn->prepare(
			"DELETE FROM admin_password_resets WHERE user_id = ? AND created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)"
		);
		if(!$cleanup){
			error_log('Password reset history cleanup prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Password reset is temporarily unavailable. Please try again later.');
		}
		$cleanup->bind_param('i', $userId);
		if(!$cleanup->execute()){
			error_log('Password reset history cleanup failed: ' . $cleanup->error);
			$cleanup->close();
			http_response_code(503);
			return $this->respond('failed', 'Password reset is temporarily unavailable. Please try again later.');
		}
		$cleanup->close();

		$rateLimit = $this->conn->prepare(
			"SELECT COUNT(*) FROM admin_password_resets WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
		);
		if(!$rateLimit){
			error_log('Password reset rate-limit prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Password reset is temporarily unavailable. Please try again later.');
		}
		$rateLimit->bind_param('i', $userId);
		if(!$rateLimit->execute()){
			error_log('Password reset rate-limit query failed: ' . $rateLimit->error);
			$rateLimit->close();
			http_response_code(503);
			return $this->respond('failed', 'Password reset is temporarily unavailable. Please try again later.');
		}
		$rateLimit->bind_result($recentRequests);
		$rateLimit->fetch();
		$rateLimit->close();
		if((int) $recentRequests >= 3){
			return $this->respond('success', $genericMessage);
		}

		$token = bin2hex(random_bytes(32));
		$tokenHash = hash('sha256', $token);
		$createToken = $this->conn->prepare(
			"INSERT INTO admin_password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))"
		);
		if(!$createToken){
			error_log('Password reset token insert prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Password reset is temporarily unavailable. Please try again later.');
		}
		$createToken->bind_param('is', $userId, $tokenHash);
		if(!$createToken->execute()){
			error_log('Password reset token insert failed: ' . $createToken->error);
			$createToken->close();
			http_response_code(503);
			return $this->respond('failed', 'Password reset is temporarily unavailable. Please try again later.');
		}
		$createToken->close();

		$resetUrl = base_url . 'admin/reset_password.php?token=' . rawurlencode($token);
		$sent = $this->sendResetEmail($email, $resetUrl, $smtp);
		if(!$sent){
			$removeToken = $this->conn->prepare("UPDATE admin_password_resets SET expires_at = NOW() WHERE token_hash = ?");
			if($removeToken){
				$removeToken->bind_param('s', $tokenHash);
				if(!$removeToken->execute()){
					error_log('Unsent password reset token cleanup failed: ' . $removeToken->error);
				}
				$removeToken->close();
			}else{
				error_log('Unsent password reset token cleanup prepare failed: ' . $this->conn->error);
			}
			http_response_code(503);
			return $this->respond('failed', 'We could not send the reset email. Please try again later.');
		}else{
			$invalidateOld = $this->conn->prepare(
				"UPDATE admin_password_resets SET expires_at = NOW() WHERE user_id = ? AND token_hash != ? AND expires_at > NOW()"
			);
			if(!$invalidateOld){
				error_log('Previous password reset token invalidation prepare failed: ' . $this->conn->error);
			}else{
				$invalidateOld->bind_param('is', $userId, $tokenHash);
				if(!$invalidateOld->execute()){
					error_log('Previous password reset token invalidation failed: ' . $invalidateOld->error);
				}
				$invalidateOld->close();
			}
		}
		return $this->respond('success', $genericMessage);
	}

	public function resetPassword(){
		if($_SERVER['REQUEST_METHOD'] !== 'POST'){
			http_response_code(405);
			return $this->respond('failed', 'Please submit the form again.');
		}
		if(!$this->validCsrfToken()){
			http_response_code(400);
			return $this->respond('failed', 'Your session expired. Refresh the page and try again.');
		}
		$token = isset($_POST['token']) ? (string) $_POST['token'] : '';
		$password = isset($_POST['password']) ? (string) $_POST['password'] : '';
		$confirmation = isset($_POST['password_confirmation']) ? (string) $_POST['password_confirmation'] : '';
		if(!preg_match('/\A[a-f0-9]{64}\z/', $token) || strlen($password) < 12 ||
			strlen($password) > 72 || !hash_equals($password, $confirmation)){
			return $this->respond('invalid', 'Use a matching password of at least 12 characters.');
		}

		$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
		if($hashedPassword === false){
			error_log('Password reset password hashing failed.');
			http_response_code(500);
			return $this->respond('failed', 'Unable to update the password. Please try again.');
		}
		$tokenHash = hash('sha256', $token);
		if(!$this->conn->begin_transaction()){
			error_log('Password reset transaction could not start: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Unable to update the password. Please try again.');
		}
		$lookup = $this->conn->prepare(
			"SELECT id, user_id FROM admin_password_resets WHERE token_hash = ? AND expires_at > NOW() FOR UPDATE"
		);
		if(!$lookup){
			$this->conn->rollback();
			error_log('Password reset token lookup prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Unable to update the password. Please try again.');
		}
		$lookup->bind_param('s', $tokenHash);
		if(!$lookup->execute()){
			$error = $lookup->error;
			$lookup->close();
			$this->conn->rollback();
			error_log('Password reset token lookup failed: ' . $error);
			http_response_code(503);
			return $this->respond('failed', 'Unable to update the password. Please try again.');
		}
		$lookup->bind_result($resetId, $userId);
		$validToken = $lookup->fetch();
		$lookup->close();
		if(!$validToken){
			$this->conn->rollback();
			return $this->respond('expired', 'This reset link is invalid or has expired. Request a new one.');
		}

		$update = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
		if(!$update){
			$this->conn->rollback();
			error_log('Password reset update prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Unable to update the password. Please try again.');
		}
		$update->bind_param('si', $hashedPassword, $userId);
		if(!$update->execute() || $update->affected_rows !== 1){
			$error = $update->error;
			$update->close();
			$this->conn->rollback();
			error_log('Password reset update failed: ' . $error);
			http_response_code(503);
			return $this->respond('failed', 'Unable to update the password. Please try again.');
		}
		$update->close();

		$deleteTokens = $this->conn->prepare("DELETE FROM admin_password_resets WHERE user_id = ?");
		if(!$deleteTokens){
			$this->conn->rollback();
			error_log('Password reset token invalidation prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Unable to update the password. Please try again.');
		}
		$deleteTokens->bind_param('i', $userId);
		if(!$deleteTokens->execute()){
			$error = $deleteTokens->error;
			$deleteTokens->close();
			$this->conn->rollback();
			error_log('Password reset token invalidation failed: ' . $error);
			http_response_code(503);
			return $this->respond('failed', 'Unable to update the password. Please try again.');
		}
		$deleteTokens->close();
		if(!$this->conn->commit()){
			$this->conn->rollback();
			error_log('Password reset transaction commit failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Unable to update the password. Please try again.');
		}

		unset($_SESSION['userdata']);
		session_regenerate_id(true);
		return $this->respond('success', 'Your password has been changed. Sign in with your new password.');
	}
}

$reset = new PasswordReset();
$action = isset($_GET['f']) ? strtolower($_GET['f']) : '';
if($action === 'request'){
	echo $reset->requestReset();
}elseif($action === 'reset'){
	echo $reset->resetPassword();
}else{
	http_response_code(404);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode(array('status' => 'failed', 'message' => 'Not found.'));
}
