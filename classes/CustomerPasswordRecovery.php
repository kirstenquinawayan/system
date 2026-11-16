<?php
require_once '../config.php';

class CustomerPasswordRecovery extends DBConnection {
	public function respond($status, $message, $extra = array()){
		header('Content-Type: application/json; charset=utf-8');
		return json_encode(array_merge(array('status' => $status, 'message' => $message), $extra));
	}

	private function validCsrf(){
		return isset($_POST['csrf_token'], $_SESSION['customer_auth_csrf']) &&
			is_string($_POST['csrf_token']) &&
			hash_equals((string) $_SESSION['customer_auth_csrf'], $_POST['csrf_token']);
	}

	private function customerId(){
		$id = isset($_SESSION['customer_userdata']['id'])
			? filter_var($_SESSION['customer_userdata']['id'], FILTER_VALIDATE_INT)
			: false;
		return $id !== false && $id > 0 ? $id : false;
	}

	private function recoveryCustomerId($method){
		$customerId = $this->customerId();
		if($customerId !== false){
			return $customerId;
		}
		$contact = isset($_POST['contact']) && is_string($_POST['contact']) ? trim($_POST['contact']) : '';
		if($method === 'email'){
			if(!filter_var($contact, FILTER_VALIDATE_EMAIL)){
				return false;
			}
			$stmt = $this->conn->prepare("SELECT id FROM customer_accounts WHERE email = ? LIMIT 1");
		}else{
			if(!preg_match('/^09[0-9]{9}$/', $contact)){
				return false;
			}
			$stmt = $this->conn->prepare("SELECT id FROM customer_accounts WHERE contact = ? LIMIT 1");
		}
		if(!$stmt){
			error_log('Customer password recovery account lookup prepare failed: ' . $this->conn->error);
			return null;
		}
		$stmt->bind_param('s', $contact);
		if(!$stmt->execute()){
			error_log('Customer password recovery account lookup failed: ' . $stmt->error);
			$stmt->close();
			return null;
		}
		$stmt->bind_result($id);
		$found = $stmt->fetch();
		$stmt->close();
		return $found ? (int) $id : false;
	}

	private function getCustomer($customerId){
		$stmt = $this->conn->prepare("SELECT email, contact FROM customer_accounts WHERE id = ? LIMIT 1");
		if(!$stmt){
			error_log('Customer password recovery lookup prepare failed: ' . $this->conn->error);
			return false;
		}
		$stmt->bind_param('i', $customerId);
		if(!$stmt->execute()){
			error_log('Customer password recovery lookup failed: ' . $stmt->error);
			$stmt->close();
			return false;
		}
		$stmt->bind_result($email, $contact);
		$found = $stmt->fetch();
		$stmt->close();
		return $found ? array('email' => $email, 'contact' => $contact) : false;
	}

	private function smtpConfiguration(){
		$autoload = base_app . 'vendor/autoload.php';
		if(!is_file($autoload)){
			error_log('Customer password recovery email dependency is missing.');
			return false;
		}
		require_once $autoload;
		$host = getenv('OCWBS_SMTP_HOST');
		$port = filter_var(getenv('OCWBS_SMTP_PORT'), FILTER_VALIDATE_INT);
		$username = getenv('OCWBS_SMTP_USERNAME');
		$password = getenv('OCWBS_SMTP_PASSWORD');
		$encryption = strtolower((string) getenv('OCWBS_SMTP_ENCRYPTION'));
		$from = getenv('OCWBS_MAIL_FROM');
		if(!is_string($host) || trim($host) === '' || strlen($host) > 255 || preg_match('/[\r\n]/', $host) ||
			$port === false || $port < 1 || $port > 65535 ||
			!in_array($encryption, array('tls', 'ssl', 'none'), true) ||
			!is_string($from) || !filter_var($from, FILTER_VALIDATE_EMAIL) ||
			(($username && !$password) || (!$username && $password))){
			error_log('Customer password recovery SMTP configuration is incomplete or invalid.');
			return false;
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
			$mailer->setFrom($smtp['from'], 'Kwacky-Kleen');
			$mailer->addAddress($email);
			$mailer->Subject = 'Customer password reset';
			$mailer->Body = "We received a request to reset your Kwacky-Kleen customer account password.\r\n\r\n";
			$mailer->Body .= "Open this one-time link within 1 hour to choose a new password:\r\n" . $resetUrl . "\r\n\r\n";
			$mailer->Body .= "If you did not request this, you can ignore this email.";
			$mailer->send();
			return true;
		}catch(\PHPMailer\PHPMailer\Exception $error){
			error_log('Customer password recovery email delivery failed: ' . $error->getMessage());
			return false;
		}
	}

	private function sendSms($phoneNumber, $message){
		if(!function_exists('curl_init')){
			error_log('Customer password recovery SMS unavailable: PHP cURL is not enabled.');
			return false;
		}
		$apiKey = getenv('SEMAPHORE_API_KEY');
		if(!is_string($apiKey) || trim($apiKey) === ''){
			error_log('Customer password recovery SMS unavailable: SEMAPHORE_API_KEY is not configured.');
			return false;
		}
		$payload = array('apikey' => trim($apiKey), 'number' => $phoneNumber, 'message' => $message);
		$sender = getenv('SEMAPHORE_SENDER_NAME');
		if(is_string($sender) && trim($sender) !== ''){
			$payload['sendername'] = trim($sender);
		}
		$curl = curl_init('https://api.semaphore.co/api/v4/messages');
		if($curl === false){
			error_log('Customer password recovery SMS unavailable: HTTPS request could not be initialized.');
			return false;
		}
		$optionsSet = curl_setopt_array($curl, array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query($payload),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		));
		if(!$optionsSet){
			curl_close($curl);
			error_log('Customer password recovery SMS request could not be configured.');
			return false;
		}
		$response = curl_exec($curl);
		$curlError = curl_error($curl);
		$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		if($response === false){
			error_log('Customer password recovery SMS request failed: ' . $curlError);
			return false;
		}
		$data = json_decode($response, true);
		if($statusCode < 200 || $statusCode >= 300 || !is_array($data) || empty($data[0]['message_id'])){
			error_log('Customer password recovery SMS provider rejected the request (HTTP ' . $statusCode . ').');
			return false;
		}
		return true;
	}

	private function applyCsrfAndMethod(){
		if($_SERVER['REQUEST_METHOD'] !== 'POST'){
			http_response_code(405);
			return 'Please submit the form again.';
		}
		if(!$this->validCsrf()){
			http_response_code(400);
			return 'Your session expired. Refresh the page and try again.';
		}
		return false;
	}

	public function requestAdminApproval(){
		$error = $this->applyCsrfAndMethod();
		if($error){
			return $this->respond('failed', $error);
		}
		$customerId = $this->customerId();
		if($customerId === false){
			http_response_code(401);
			return $this->respond('failed', 'Sign in to request password reset approval from the admin.');
		}
		$limit = $this->conn->prepare(
			"SELECT COUNT(*), MAX(requested_at)
			 FROM customer_password_recovery_requests
			 WHERE customer_id = ? AND requested_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
		);
		if(!$limit){
			error_log('Customer password recovery request rate-limit prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Password recovery is temporarily unavailable.');
		}
		$limit->bind_param('i', $customerId);
		if(!$limit->execute()){
			error_log('Customer password recovery request rate-limit failed: ' . $limit->error);
			$limit->close();
			http_response_code(503);
			return $this->respond('failed', 'Password recovery is temporarily unavailable.');
		}
		$limit->bind_result($requestCount, $lastRequest);
		$limit->fetch();
		$limit->close();
		if((int) $requestCount >= 5 || ($lastRequest && time() - strtotime($lastRequest) < 60)){
			return $this->respond('failed', 'Please wait before submitting another password reset request.');
		}
		$active = $this->conn->prepare(
			"SELECT id FROM customer_password_recovery_requests
			 WHERE customer_id = ? AND (
			   status = 'pending' OR (status = 'approved' AND expires_at > NOW())
			 )
			 ORDER BY id DESC LIMIT 1"
		);
		if(!$active){
			error_log('Customer password recovery active-request prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Password recovery is temporarily unavailable.');
		}
		$active->bind_param('i', $customerId);
		if(!$active->execute()){
			error_log('Customer password recovery active-request lookup failed: ' . $active->error);
			$active->close();
			http_response_code(503);
			return $this->respond('failed', 'Password recovery is temporarily unavailable.');
		}
		$active->store_result();
		$hasActiveRequest = $active->num_rows > 0;
		$active->close();
		if($hasActiveRequest){
			return $this->respond('success', 'Your password reset request is already being reviewed or approved. Check your Notification page.');
		}
		$insert = $this->conn->prepare(
			"INSERT INTO customer_password_recovery_requests (customer_id) VALUES (?)"
		);
		if(!$insert){
			error_log('Customer password recovery request prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Password recovery is temporarily unavailable.');
		}
		$insert->bind_param('i', $customerId);
		if(!$insert->execute()){
			error_log('Customer password recovery request failed: ' . $insert->error);
			$insert->close();
			http_response_code(503);
			return $this->respond('failed', 'Password recovery is temporarily unavailable.');
		}
		$insert->close();
		return $this->respond('success', 'Your request was sent to the admin. Check your Notification page after it is reviewed.');
	}

	public function completeApprovedReset(){
		$error = $this->applyCsrfAndMethod();
		if($error){
			return $this->respond('failed', $error);
		}
		$customerId = $this->customerId();
		$password = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
		$confirmation = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
		if($customerId === false){
			http_response_code(401);
			return $this->respond('failed', 'Sign in to reset your password.');
		}
		if(strlen($password) < 8 || strlen($password) > 72 || $password !== $confirmation){
			return $this->respond('invalid', 'Enter a matching password of 8 to 72 characters.');
		}
		$passwordHash = password_hash($password, PASSWORD_DEFAULT);
		if($passwordHash === false){
			error_log('Approved customer password reset hashing failed.');
			http_response_code(503);
			return $this->respond('failed', 'We could not update your password. Please try again.');
		}
		if(!$this->conn->begin_transaction()){
			error_log('Approved customer password reset transaction could not start: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not update your password. Please try again.');
		}
		$lookup = $this->conn->prepare(
			"SELECT id FROM customer_password_recovery_requests
			 WHERE customer_id = ? AND status = 'approved' AND expires_at > NOW()
			 ORDER BY approved_at DESC, id DESC LIMIT 1 FOR UPDATE"
		);
		if(!$lookup){
			$this->conn->rollback();
			error_log('Approved customer password reset lookup prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not verify the approval. Please try again.');
		}
		$lookup->bind_param('i', $customerId);
		if(!$lookup->execute()){
			error_log('Approved customer password reset lookup failed: ' . $lookup->error);
			$lookup->close();
			$this->conn->rollback();
			http_response_code(503);
			return $this->respond('failed', 'We could not verify the approval. Please try again.');
		}
		$lookup->bind_result($requestId);
		$approved = $lookup->fetch();
		$lookup->close();
		if(!$approved){
			$this->conn->rollback();
			return $this->respond('expired', 'There is no active admin approval for a password reset.');
		}
		$update = $this->conn->prepare("UPDATE customer_accounts SET password = ? WHERE id = ?");
		if(!$update){
			$this->conn->rollback();
			error_log('Approved customer password update prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not update your password. Please try again.');
		}
		$update->bind_param('si', $passwordHash, $customerId);
		if(!$update->execute()){
			error_log('Approved customer password update failed: ' . $update->error);
			$update->close();
			$this->conn->rollback();
			http_response_code(503);
			return $this->respond('failed', 'We could not update your password. Please try again.');
		}
		$update->close();
		$complete = $this->conn->prepare(
			"UPDATE customer_password_recovery_requests
			 SET status = 'completed', completed_at = NOW()
			 WHERE id = ? AND status = 'approved'"
		);
		if(!$complete){
			$this->conn->rollback();
			error_log('Approved customer password reset completion prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not finalize your password reset. Please try again.');
		}
		$complete->bind_param('i', $requestId);
		if(!$complete->execute() || $complete->affected_rows !== 1){
			error_log('Approved customer password reset completion failed: ' . $complete->error);
			$complete->close();
			$this->conn->rollback();
			http_response_code(503);
			return $this->respond('failed', 'We could not finalize your password reset. Please try again.');
		}
		$complete->close();
		if(!$this->conn->commit()){
			$this->conn->rollback();
			error_log('Approved customer password reset transaction commit failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not finalize your password reset. Please try again.');
		}
		unset($_SESSION['customer_userdata']);
		session_regenerate_id(true);
		return $this->respond('success', 'Your password has been changed. Sign in with your new password.');
	}

	public function decideAdminRequest(){
		if($_SERVER['REQUEST_METHOD'] !== 'POST'){
			http_response_code(405);
			return $this->respond('failed', 'Please submit the form again.');
		}
		if(
			!isset($_SESSION['userdata']['login_type']) ||
			(int) $_SESSION['userdata']['login_type'] !== 1
		){
			http_response_code(403);
			return $this->respond('failed', 'Administrator access is required.');
		}
		if(
			!isset($_POST['csrf_token'], $_SESSION['password_recovery_admin_csrf']) ||
			!is_string($_POST['csrf_token']) ||
			!hash_equals((string) $_SESSION['password_recovery_admin_csrf'], $_POST['csrf_token'])
		){
			http_response_code(400);
			return $this->respond('failed', 'Your session expired. Refresh the page and try again.');
		}
		$requestId = isset($_POST['request_id']) ? filter_var($_POST['request_id'], FILTER_VALIDATE_INT) : false;
		$decision = isset($_POST['decision']) && is_string($_POST['decision']) ? $_POST['decision'] : '';
		if($requestId === false || $requestId < 1 || !in_array($decision, array('approve', 'reject'), true)){
			return $this->respond('invalid', 'Choose a valid password reset request and decision.');
		}
		$status = $decision === 'approve' ? 'approved' : 'rejected';
		$adminId = isset($_SESSION['userdata']['id']) ? (int) $_SESSION['userdata']['id'] : 0;
		$stmt = $this->conn->prepare(
			"UPDATE customer_password_recovery_requests
			 SET status = ?, approved_at = IF(? = 'approved', NOW(), NULL),
			     approved_by = IF(? = 'approved', ?, NULL),
			     expires_at = IF(? = 'approved', DATE_ADD(NOW(), INTERVAL 30 MINUTE), NULL)
			 WHERE id = ? AND status = 'pending'"
		);
		if(!$stmt){
			error_log('Admin password recovery decision prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'The password reset request could not be updated.');
		}
		$stmt->bind_param('sssisi', $status, $status, $status, $adminId, $status, $requestId);
		if(!$stmt->execute()){
			error_log('Admin password recovery decision failed: ' . $stmt->error);
			$stmt->close();
			http_response_code(503);
			return $this->respond('failed', 'The password reset request could not be updated.');
		}
		$updated = $stmt->affected_rows === 1;
		$stmt->close();
		if(!$updated){
			return $this->respond('expired', 'This request has already been reviewed.');
		}
		return $this->respond('success', $decision === 'approve'
			? 'Approved. The customer can now reset their password from Notification for 30 minutes.'
			: 'The password reset request was declined.');
	}

	public function requestEmail(){
		$error = $this->applyCsrfAndMethod();
		if($error){
			return $this->respond('failed', $error);
		}
		$smtp = $this->smtpConfiguration();
		if($smtp === false){
			http_response_code(503);
			return $this->respond('failed', 'Email password recovery is not configured on this server yet.');
		}
		$customerId = $this->recoveryCustomerId('email');
		if($customerId === false){
			return $this->respond('success', 'If an account matches that email, a password reset link will be sent.');
		}
		if($customerId === null){
			http_response_code(503);
			return $this->respond('failed', 'Email password recovery is temporarily unavailable.');
		}
		$customer = $this->getCustomer($customerId);
		if(!$customer || !filter_var($customer['email'], FILTER_VALIDATE_EMAIL)){
			http_response_code(503);
			return $this->respond('failed', 'We could not load your recovery email. Please contact the shop.');
		}
		if(!$this->checkRateLimit($customerId)){
			return $this->respond('success', 'If an account matches that email, a password reset link will be sent.');
		}
		try{
			$token = bin2hex(random_bytes(32));
		}catch(Throwable $error){
			error_log('Customer password reset token generation failed: ' . $error->getMessage());
			http_response_code(503);
			return $this->respond('failed', 'We could not create a reset link. Please try again later.');
		}
		$tokenHash = hash('sha256', $token);
		$stmt = $this->conn->prepare(
			"INSERT INTO customer_password_resets (customer_id, method, token_hash, expires_at)
			 VALUES (?, 'email', ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))"
		);
		if(!$stmt){
			error_log('Customer email reset insert prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Email password recovery is temporarily unavailable.');
		}
		$stmt->bind_param('is', $customerId, $tokenHash);
		if(!$stmt->execute()){
			error_log('Customer email reset insert failed: ' . $stmt->error);
			$stmt->close();
			http_response_code(503);
			return $this->respond('failed', 'Email password recovery is temporarily unavailable.');
		}
		$resetId = (int) $stmt->insert_id;
		$stmt->close();
		$resetUrl = base_url . 'customer_reset_password.php?token=' . rawurlencode($token);
		if(!$this->sendResetEmail($customer['email'], $resetUrl, $smtp)){
			$this->invalidateReset($resetId);
			http_response_code(503);
			return $this->respond('failed', 'We could not send the reset email. Please try again later.');
		}
		return $this->respond('success', 'If an account matches that email, a password reset link will be sent.');
	}

	public function requestSms(){
		$error = $this->applyCsrfAndMethod();
		if($error){
			return $this->respond('failed', $error);
		}
		if(!function_exists('curl_init') || !is_string(getenv('SEMAPHORE_API_KEY')) || trim(getenv('SEMAPHORE_API_KEY')) === ''){
			error_log('Customer password recovery SMS unavailable: configure PHP cURL and SEMAPHORE_API_KEY.');
			http_response_code(503);
			return $this->respond('failed', 'SMS password recovery is not configured on this server yet.');
		}
		$customerId = $this->recoveryCustomerId('sms');
		if($customerId === false){
			return $this->respond('success', 'If an account matches that mobile number, a password reset code will be sent.');
		}
		if($customerId === null){
			http_response_code(503);
			return $this->respond('failed', 'SMS password recovery is temporarily unavailable.');
		}
		$customer = $this->getCustomer($customerId);
		if(!$customer || !preg_match('/^09[0-9]{9}$/', (string) $customer['contact'])){
			return $this->respond('success', 'If an account matches that mobile number, a password reset code will be sent.');
		}
		if(!$this->checkRateLimit($customerId)){
			return $this->respond('success', 'If an account matches that mobile number, a password reset code will be sent.');
		}
		try{
			$code = (string) random_int(100000, 999999);
		}catch(Throwable $error){
			error_log('Customer password recovery code generation failed: ' . $error->getMessage());
			http_response_code(503);
			return $this->respond('failed', 'We could not generate a code. Please try again later.');
		}
		$otpHash = password_hash($code, PASSWORD_DEFAULT);
		if($otpHash === false){
			error_log('Customer password recovery code hashing failed.');
			http_response_code(503);
			return $this->respond('failed', 'We could not generate a code. Please try again later.');
		}
		$stmt = $this->conn->prepare(
			"INSERT INTO customer_password_resets (customer_id, method, otp_hash, expires_at)
			 VALUES (?, 'sms', ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
		);
		if(!$stmt){
			error_log('Customer SMS reset insert prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'SMS password recovery is temporarily unavailable.');
		}
		$stmt->bind_param('is', $customerId, $otpHash);
		if(!$stmt->execute()){
			error_log('Customer SMS reset insert failed: ' . $stmt->error);
			$stmt->close();
			http_response_code(503);
			return $this->respond('failed', 'SMS password recovery is temporarily unavailable.');
		}
		$resetId = (int) $stmt->insert_id;
		$stmt->close();
		$phoneNumber = '63' . substr($customer['contact'], 1);
		$message = 'Your Kwacky-Kleen password reset code is ' . $code . '. It expires in 10 minutes. Do not share this code.';
		if(!$this->sendSms($phoneNumber, $message)){
			$this->invalidateReset($resetId);
			http_response_code(503);
			return $this->respond('failed', 'We could not send the SMS code. Please try again later.');
		}
		return $this->respond('success', 'If an account matches that mobile number, a password reset code will be sent.');
	}

	public function resetWithSms(){
		$error = $this->applyCsrfAndMethod();
		if($error){
			return $this->respond('failed', $error);
		}
		$customerId = $this->recoveryCustomerId('sms');
		$code = isset($_POST['code']) && is_string($_POST['code']) ? trim($_POST['code']) : '';
		$password = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
		$confirmation = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
		if($customerId === false || $customerId === null){
			return $this->respond('expired', 'This code is invalid, expired, or locked. Request a new code.');
		}
		if(!preg_match('/^[0-9]{6}$/', $code) || strlen($password) < 8 || strlen($password) > 72 || $password !== $confirmation){
			return $this->respond('invalid', 'Enter the 6-digit code and a matching new password of 8 to 72 characters.');
		}
		$passwordHash = password_hash($password, PASSWORD_DEFAULT);
		if($passwordHash === false){
			error_log('Customer SMS reset password hashing failed.');
			http_response_code(503);
			return $this->respond('failed', 'We could not update your password. Please try again.');
		}
		if(!$this->conn->begin_transaction()){
			error_log('Customer SMS reset transaction could not start: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not verify the code. Please try again.');
		}
		$lookup = $this->conn->prepare(
			"SELECT id, otp_hash, attempts FROM customer_password_resets
			 WHERE customer_id = ? AND method = 'sms' AND used_at IS NULL AND expires_at > NOW()
			 ORDER BY id DESC LIMIT 1 FOR UPDATE"
		);
		if(!$lookup){
			$this->conn->rollback();
			error_log('Customer SMS reset lookup prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not verify the code. Please try again.');
		}
		$lookup->bind_param('i', $customerId);
		if(!$lookup->execute()){
			error_log('Customer SMS reset lookup failed: ' . $lookup->error);
			$lookup->close();
			$this->conn->rollback();
			http_response_code(503);
			return $this->respond('failed', 'We could not verify the code. Please try again.');
		}
		$lookup->bind_result($resetId, $otpHash, $attempts);
		$found = $lookup->fetch();
		$lookup->close();
		if(!$found || (int) $attempts >= 5){
			$this->conn->rollback();
			return $this->respond('expired', 'This code is invalid, expired, or locked. Request a new code.');
		}
		if(!password_verify($code, $otpHash)){
			$increment = $this->conn->prepare("UPDATE customer_password_resets SET attempts = attempts + 1 WHERE id = ?");
			if(!$increment){
				$this->conn->rollback();
				error_log('Customer SMS reset attempt update prepare failed: ' . $this->conn->error);
				http_response_code(503);
				return $this->respond('failed', 'We could not verify the code. Please try again.');
			}
			$increment->bind_param('i', $resetId);
			if(!$increment->execute()){
				error_log('Customer SMS reset attempt update failed: ' . $increment->error);
				$increment->close();
				$this->conn->rollback();
				http_response_code(503);
				return $this->respond('failed', 'We could not verify the code. Please try again.');
			}
			$increment->close();
			$this->conn->commit();
			return $this->respond('invalid', 'The code is incorrect. You have ' . (4 - (int) $attempts) . ' attempt(s) remaining.');
		}
		$update = $this->conn->prepare("UPDATE customer_accounts SET password = ? WHERE id = ?");
		if(!$update){
			$this->conn->rollback();
			error_log('Customer SMS reset password update prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not update your password. Please try again.');
		}
		$update->bind_param('si', $passwordHash, $customerId);
		if(!$update->execute() || $update->affected_rows !== 1){
			error_log('Customer SMS reset password update failed: ' . $update->error);
			$update->close();
			$this->conn->rollback();
			http_response_code(503);
			return $this->respond('failed', 'We could not update your password. Please try again.');
		}
		$update->close();
		$consume = $this->conn->prepare("UPDATE customer_password_resets SET used_at = NOW() WHERE customer_id = ? AND used_at IS NULL");
		if(!$consume){
			$this->conn->rollback();
			error_log('Customer SMS reset invalidation prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not finalize your password reset. Please try again.');
		}
		$consume->bind_param('i', $customerId);
		if(!$consume->execute()){
			error_log('Customer SMS reset invalidation failed: ' . $consume->error);
			$consume->close();
			$this->conn->rollback();
			http_response_code(503);
			return $this->respond('failed', 'We could not finalize your password reset. Please try again.');
		}
		$consume->close();
		if(!$this->conn->commit()){
			$this->conn->rollback();
			error_log('Customer SMS reset transaction commit failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not finalize your password reset. Please try again.');
		}
		if($this->customerId() === $customerId){
			unset($_SESSION['customer_userdata']);
			session_regenerate_id(true);
		}
		return $this->respond('success', 'Your password has been reset. Sign in with your new password.');
	}

	public function resetWithEmail(){
		$error = $this->applyCsrfAndMethod();
		if($error){
			return $this->respond('failed', $error);
		}
		$token = isset($_POST['token']) && is_string($_POST['token']) ? $_POST['token'] : '';
		$password = isset($_POST['new_password']) && is_string($_POST['new_password']) ? $_POST['new_password'] : '';
		$confirmation = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
		if(!preg_match('/\A[a-f0-9]{64}\z/', $token) || strlen($password) < 8 || strlen($password) > 72 || $password !== $confirmation){
			return $this->respond('invalid', 'Enter a matching password of 8 to 72 characters.');
		}
		$passwordHash = password_hash($password, PASSWORD_DEFAULT);
		if($passwordHash === false){
			error_log('Customer email reset password hashing failed.');
			http_response_code(503);
			return $this->respond('failed', 'We could not update your password. Please try again.');
		}
		if(!$this->conn->begin_transaction()){
			error_log('Customer email reset transaction could not start: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not verify this reset link. Please try again.');
		}
		$tokenHash = hash('sha256', $token);
		$lookup = $this->conn->prepare(
			"SELECT id, customer_id FROM customer_password_resets
			 WHERE token_hash = ? AND method = 'email' AND used_at IS NULL AND expires_at > NOW()
			 LIMIT 1 FOR UPDATE"
		);
		if(!$lookup){
			$this->conn->rollback();
			error_log('Customer email reset token lookup prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not verify this reset link. Please try again.');
		}
		$lookup->bind_param('s', $tokenHash);
		if(!$lookup->execute()){
			error_log('Customer email reset token lookup failed: ' . $lookup->error);
			$lookup->close();
			$this->conn->rollback();
			http_response_code(503);
			return $this->respond('failed', 'We could not verify this reset link. Please try again.');
		}
		$lookup->bind_result($resetId, $customerId);
		$found = $lookup->fetch();
		$lookup->close();
		if(!$found){
			$this->conn->rollback();
			return $this->respond('expired', 'This reset link is invalid or expired. Request a new one.');
		}
		$update = $this->conn->prepare("UPDATE customer_accounts SET password = ? WHERE id = ?");
		if(!$update){
			$this->conn->rollback();
			error_log('Customer email reset password update prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not update your password. Please try again.');
		}
		$update->bind_param('si', $passwordHash, $customerId);
		if(!$update->execute() || $update->affected_rows !== 1){
			error_log('Customer email reset password update failed: ' . $update->error);
			$update->close();
			$this->conn->rollback();
			http_response_code(503);
			return $this->respond('failed', 'We could not update your password. Please try again.');
		}
		$update->close();
		$consume = $this->conn->prepare("UPDATE customer_password_resets SET used_at = NOW() WHERE customer_id = ? AND used_at IS NULL");
		if(!$consume){
			$this->conn->rollback();
			error_log('Customer email reset invalidation prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not finalize your password reset. Please try again.');
		}
		$consume->bind_param('i', $customerId);
		if(!$consume->execute()){
			error_log('Customer email reset invalidation failed: ' . $consume->error);
			$consume->close();
			$this->conn->rollback();
			http_response_code(503);
			return $this->respond('failed', 'We could not finalize your password reset. Please try again.');
		}
		$consume->close();
		if(!$this->conn->commit()){
			$this->conn->rollback();
			error_log('Customer email reset transaction commit failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'We could not finalize your password reset. Please try again.');
		}
		if(isset($_SESSION['customer_userdata']['id']) && (int) $_SESSION['customer_userdata']['id'] === (int) $customerId){
			unset($_SESSION['customer_userdata']);
			session_regenerate_id(true);
		}
		return $this->respond('success', 'Your password has been reset. Sign in with your new password.');
	}

	private function checkRateLimit($customerId){
		$stmt = $this->conn->prepare(
			"SELECT COUNT(*), MAX(created_at) FROM customer_password_resets
			 WHERE customer_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
		);
		if(!$stmt){
			error_log('Customer password reset rate-limit prepare failed: ' . $this->conn->error);
			return false;
		}
		$stmt->bind_param('i', $customerId);
		if(!$stmt->execute()){
			error_log('Customer password reset rate-limit query failed: ' . $stmt->error);
			$stmt->close();
			return false;
		}
		$stmt->bind_result($requests, $lastRequest);
		$stmt->fetch();
		$stmt->close();
		return (int) $requests < 5 && (!$lastRequest || time() - strtotime($lastRequest) >= 60);
	}

	private function invalidateReset($resetId){
		$stmt = $this->conn->prepare("UPDATE customer_password_resets SET used_at = NOW() WHERE id = ?");
		if(!$stmt){
			error_log('Customer password reset cleanup prepare failed: ' . $this->conn->error);
			return;
		}
		$stmt->bind_param('i', $resetId);
		if(!$stmt->execute()){
			error_log('Customer password reset cleanup failed: ' . $stmt->error);
		}
		$stmt->close();
	}
}

$recovery = new CustomerPasswordRecovery();
$action = isset($_GET['f']) && is_string($_GET['f']) ? strtolower($_GET['f']) : '';
if($action === 'request_admin_approval'){
	echo $recovery->requestAdminApproval();
}elseif($action === 'complete_approved_reset'){
	echo $recovery->completeApprovedReset();
}elseif($action === 'admin_decide'){
	echo $recovery->decideAdminRequest();
}else{
	http_response_code(404);
	echo $recovery->respond('failed', 'Not found.');
}
