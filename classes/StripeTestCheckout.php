<?php
class StripeTestCheckout{
	private const API_BASE_URL = 'https://api.stripe.com/v1';

	private static function configured(){
		$secretKey = getenv('STRIPE_SECRET_KEY');
		return getenv('STRIPE_MODE') === 'test' &&
			is_string($secretKey) &&
			strncmp($secretKey, 'sk_test_', 8) === 0;
	}

	private static function request($method, $path, $data = [], $idempotencyKey = null){
		$secretKey = getenv('STRIPE_SECRET_KEY');
		$url = self::API_BASE_URL . $path;
		$headers = ['Authorization: Bearer ' . $secretKey];
		$options = [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_HTTPHEADER => $headers,
		];
		if($method === 'POST'){
			$options[CURLOPT_POST] = true;
			$body = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
			$options[CURLOPT_POSTFIELDS] = str_replace('%7BCHECKOUT_SESSION_ID%7D', '{CHECKOUT_SESSION_ID}', $body);
			$options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
			if(is_string($idempotencyKey) && $idempotencyKey !== ''){
				$options[CURLOPT_HTTPHEADER][] = 'Idempotency-Key: ' . $idempotencyKey;
			}
		}else{
			$options[CURLOPT_HTTPGET] = true;
		}
		$curl = curl_init($url);
		if($curl === false || !curl_setopt_array($curl, $options)){
			if($curl !== false){
				curl_close($curl);
			}
			error_log('Stripe test checkout request could not be initialized.');
			return ['ok' => false];
		}
		$response = curl_exec($curl);
		$curlError = curl_error($curl);
		$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		if($response === false){
			error_log('Stripe test checkout request failed: ' . $curlError);
			return ['ok' => false, 'retryable' => true];
		}
		$responseData = json_decode($response, true);
		if($statusCode < 200 || $statusCode >= 300 || !is_array($responseData)){
			$stripeError = is_array($responseData) && isset($responseData['error']['message'])
				? (string) $responseData['error']['message']
				: 'Invalid Stripe API response.';
			error_log('Stripe test checkout API returned HTTP ' . $statusCode . ': ' . $stripeError);
			return ['ok' => false];
		}
		return ['ok' => true, 'data' => $responseData];
	}

	public static function expireActiveForCash(mysqli $conn, $bookingId){
		$payments = $conn->prepare(
			"SELECT id, transaction_ref
			 FROM booking_online_payments
			 WHERE booking_id = ? AND payment_provider = 'stripe'
			   AND status IN ('creating', 'pending')
			 ORDER BY id"
		);
		if(!$payments){
			error_log('Active Stripe checkout lookup prepare failed: ' . $conn->error);
			return ['status' => 'failed', 'msg' => 'Could not check for an active Stripe checkout.'];
		}
		$payments->bind_param('i', $bookingId);
		if(!$payments->execute()){
			error_log('Active Stripe checkout lookup failed: ' . $payments->error);
			$payments->close();
			return ['status' => 'failed', 'msg' => 'Could not check for an active Stripe checkout.'];
		}
		$payments->bind_result($paymentId, $sessionId);
		$activePayments = [];
		while($payments->fetch()){
			$activePayments[] = [
				'id' => (int) $paymentId,
				'session_id' => is_string($sessionId) ? $sessionId : '',
			];
		}
		$payments->close();
		if(count($activePayments) === 0){
			return ['status' => 'success'];
		}
		if(!self::configured() || !function_exists('curl_init')){
			return ['status' => 'failed', 'msg' => 'An active Stripe checkout could not be safely expired. Please wait for it to finish, then refresh and retry.'];
		}

		foreach($activePayments as $payment){
			$paymentId = $payment['id'];
			$sessionId = $payment['session_id'];
			if(!preg_match('/^cs_test_[A-Za-z0-9_]+$/', $sessionId)){
				return ['status' => 'failed', 'msg' => 'A Stripe checkout is still being created. Wait a moment, refresh, then retry recording cash.'];
			}

			$session = self::request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
			if(!$session['ok']){
				return ['status' => 'failed', 'msg' => 'Could not verify the Stripe checkout. Refresh the booking and retry recording cash.'];
			}
			$sessionStatus = $session['data']['status'] ?? '';
			if($sessionStatus === 'complete'){
				if(($session['data']['payment_status'] ?? '') === 'paid'){
					self::finalizePaidSession($conn, $session['data']);
					return ['status' => 'failed', 'msg' => 'Stripe completed this payment. Refresh the booking to check its paid status before recording cash.'];
				}
				return ['status' => 'failed', 'msg' => 'Stripe has completed checkout but has not confirmed payment. Contact the shop before recording cash.'];
			}

			if($sessionStatus === 'open'){
				$expired = self::request('POST', '/checkout/sessions/' . rawurlencode($sessionId) . '/expire');
				if(!$expired['ok']){
					$session = self::request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
					if(!$session['ok']){
						return ['status' => 'failed', 'msg' => 'Could not expire the Stripe checkout. Refresh the booking and retry recording cash.'];
					}
					$sessionStatus = $session['data']['status'] ?? '';
					if($sessionStatus === 'complete' && ($session['data']['payment_status'] ?? '') === 'paid'){
						self::finalizePaidSession($conn, $session['data']);
						return ['status' => 'failed', 'msg' => 'Stripe completed this payment. Refresh the booking to check its paid status before recording cash.'];
					}
					if($sessionStatus !== 'expired'){
						return ['status' => 'failed', 'msg' => 'Could not safely expire the Stripe checkout. Contact the shop before recording cash.'];
					}
				}else{
					$sessionStatus = $expired['data']['status'] ?? '';
					if($sessionStatus === 'complete' && ($expired['data']['payment_status'] ?? '') === 'paid'){
						self::finalizePaidSession($conn, $expired['data']);
						return ['status' => 'failed', 'msg' => 'Stripe completed this payment. Refresh the booking to check its paid status before recording cash.'];
					}
					if($sessionStatus !== 'expired'){
						return ['status' => 'failed', 'msg' => 'Stripe did not confirm checkout expiration. Contact the shop before recording cash.'];
					}
				}
			}elseif($sessionStatus !== 'expired'){
				return ['status' => 'failed', 'msg' => 'Stripe could not confirm the checkout status. Contact the shop before recording cash.'];
			}

			$markExpired = $conn->prepare(
				"UPDATE booking_online_payments
				 SET status = 'expired'
				 WHERE id = ? AND payment_provider = 'stripe'
				   AND transaction_ref = ? AND status IN ('creating', 'pending')"
			);
			if(!$markExpired){
				error_log('Expired Stripe checkout update prepare failed: ' . $conn->error);
				return ['status' => 'failed', 'msg' => 'The Stripe checkout expired, but its payment record could not be updated. Refresh and retry.'];
			}
			$markExpired->bind_param('is', $paymentId, $sessionId);
			if(!$markExpired->execute()){
				error_log('Expired Stripe checkout update failed: ' . $markExpired->error);
				$markExpired->close();
				return ['status' => 'failed', 'msg' => 'The Stripe checkout expired, but its payment record could not be updated. Refresh and retry.'];
			}
			$markExpired->close();
		}
		return ['status' => 'success'];
	}

	public static function create(mysqli $conn, $token){
		if(!self::configured()){
			return ['status' => 'failed', 'msg' => 'Stripe test mode is not configured. Please pay at the shop cashier.'];
		}
		if(!function_exists('curl_init')){
			error_log('Stripe test checkout requires the PHP cURL extension.');
			return ['status' => 'failed', 'msg' => 'Online payment is temporarily unavailable. Please pay at the shop cashier.'];
		}
		if(!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)){
			return ['status' => 'failed', 'msg' => 'Invalid booking payment request.'];
		}

		try{
			$conn->begin_transaction();
			$booking = $conn->prepare(
				"SELECT id, code, email, total_amount, payment_status, status
				 FROM booking_list
				 WHERE receipt_pdf_token = ? AND is_archived = 0
				 LIMIT 1 FOR UPDATE"
			);
			$booking->bind_param('s', $token);
			$booking->execute();
			$booking->bind_result($bookingId, $bookingCode, $email, $totalAmount, $bookingPaymentStatus, $bookingStatus);
			$found = $booking->fetch();
			$booking->close();
			if(!$found || $bookingPaymentStatus !== 'unpaid' || (int) $bookingStatus === 5){
				$conn->rollback();
				return ['status' => 'failed', 'msg' => 'This booking cannot be paid online. Please contact the shop.'];
			}
			$amountCents = (int) round((float) $totalAmount * 100);
			if($amountCents < 1){
				$conn->rollback();
				return ['status' => 'failed', 'msg' => 'The booking total is invalid. Please contact the shop.'];
			}

			$existing = $conn->prepare(
				"SELECT id, transaction_ref, expires_at
				 FROM booking_online_payments
				 WHERE booking_id = ? AND payment_provider = 'stripe'
				   AND status IN ('creating', 'pending')
				   AND expires_at > NOW()
				 ORDER BY id DESC LIMIT 1 FOR UPDATE"
			);
			$existing->bind_param('i', $bookingId);
			$existing->execute();
			$existing->bind_result($paymentId, $sessionId, $expiresAt);
			$hasExistingPayment = $existing->fetch();
			$existing->close();

			if($hasExistingPayment){
				if(!is_null($sessionId) && $sessionId !== ''){
					$conn->commit();
					$existingSession = self::request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
					if(
						$existingSession['ok'] &&
						isset($existingSession['data']['status'], $existingSession['data']['url']) &&
						$existingSession['data']['status'] === 'open' &&
						is_string($existingSession['data']['url'])
					){
						return ['status' => 'success', 'url' => $existingSession['data']['url']];
					}
					return ['status' => 'failed', 'msg' => 'A Stripe checkout is already being processed. Please refresh the booking page or pay at the shop cashier.'];
				}
			}else{
				$paymentType = 'full_payment';
				$paymentStatus = 'creating';
				$insert = $conn->prepare(
					"INSERT INTO booking_online_payments
					 (booking_id, payment_type, payment_provider, amount_cents, status, expires_at)
					 VALUES (?, ?, 'stripe', ?, ?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))"
				);
				$insert->bind_param('isis', $bookingId, $paymentType, $amountCents, $paymentStatus);
				$insert->execute();
				$paymentId = $conn->insert_id;
				$insert->close();
				$expiresQuery = $conn->prepare("SELECT expires_at FROM booking_online_payments WHERE id = ?");
				$expiresQuery->bind_param('i', $paymentId);
				$expiresQuery->execute();
				$expiresQuery->bind_result($expiresAt);
				$expiresQuery->fetch();
				$expiresQuery->close();
			}
			if(!$conn->commit()){
				$conn->rollback();
				error_log('Stripe checkout payment record could not be committed.');
				return ['status' => 'failed', 'msg' => 'Could not start Stripe checkout. Please pay at the shop cashier.'];
			}
		}catch(mysqli_sql_exception $error){
			$conn->rollback();
			error_log('Stripe checkout payment record failed: ' . $error->getMessage());
			return ['status' => 'failed', 'msg' => 'Could not start Stripe checkout. Please pay at the shop cashier.'];
		}

		$expiresTimestamp = strtotime((string) $expiresAt);
		if($expiresTimestamp === false){
			error_log('Stripe checkout payment has an invalid expiration time.');
			return ['status' => 'failed', 'msg' => 'Could not start Stripe checkout. Please pay at the shop cashier.'];
		}
		$successUrl = base_url . 'index.php?p=booking&stripe=success&session_id={CHECKOUT_SESSION_ID}';
		$cancelUrl = base_url . 'index.php?p=booking&stripe=cancelled';
		$checkout = self::request('POST', '/checkout/sessions', [
			'mode' => 'payment',
			'payment_method_types[0]' => 'card',
			'client_reference_id' => (string) $bookingCode,
			'customer_email' => (string) $email,
			'success_url' => $successUrl,
			'cancel_url' => $cancelUrl,
			'expires_at' => $expiresTimestamp,
			'line_items[0][price_data][currency]' => 'php',
			'line_items[0][price_data][product_data][name]' => 'Booking ' . $bookingCode,
			'line_items[0][price_data][unit_amount]' => $amountCents,
			'line_items[0][quantity]' => 1,
			'metadata[booking_id]' => (string) $bookingId,
			'metadata[payment_id]' => (string) $paymentId,
		], 'ocwbs-stripe-payment-' . $paymentId);
		if(!$checkout['ok'] || empty($checkout['data']['id']) || empty($checkout['data']['url'])){
			if(empty($checkout['retryable'])){
				$failed = $conn->prepare(
					"UPDATE booking_online_payments SET status = 'failed'
					 WHERE id = ? AND status = 'creating' AND transaction_ref IS NULL"
				);
				$failed->bind_param('i', $paymentId);
				$failed->execute();
				$failed->close();
			}
			return ['status' => 'failed', 'msg' => 'Could not start Stripe checkout. Please pay at the shop cashier.'];
		}
		$stripeSessionId = (string) $checkout['data']['id'];
		$sessionUpdate = $conn->prepare(
			"UPDATE booking_online_payments
			 SET transaction_ref = ?, status = 'pending'
			 WHERE id = ? AND payment_provider = 'stripe' AND status = 'creating'"
		);
		$sessionUpdate->bind_param('si', $stripeSessionId, $paymentId);
		$sessionUpdate->execute();
		$updated = $sessionUpdate->affected_rows;
		$sessionUpdate->close();
		if($updated !== 1){
			$linkedSession = $conn->prepare(
				"SELECT 1 FROM booking_online_payments
				 WHERE id = ? AND payment_provider = 'stripe'
				   AND status = 'pending' AND transaction_ref = ?
				 LIMIT 1"
			);
			$linkedSession->bind_param('is', $paymentId, $stripeSessionId);
			$linkedSession->execute();
			$linkedSession->store_result();
			$alreadyLinked = $linkedSession->num_rows === 1;
			$linkedSession->close();
			if($alreadyLinked){
				return ['status' => 'success', 'url' => (string) $checkout['data']['url']];
			}
			error_log('Stripe checkout session could not be linked to payment record ' . (int) $paymentId . '.');
			return ['status' => 'failed', 'msg' => 'Stripe checkout was created, but the booking could not be updated. Please contact the shop before retrying.'];
		}
		return ['status' => 'success', 'url' => (string) $checkout['data']['url']];
	}

	public static function confirmReturn(mysqli $conn, $sessionId){
		if(!self::configured() || !function_exists('curl_init')){
			return ['status' => 'failed', 'msg' => 'Stripe payment confirmation is unavailable.'];
		}
		if(!is_string($sessionId) || !preg_match('/^cs_test_[A-Za-z0-9_]+$/', $sessionId)){
			return ['status' => 'failed', 'msg' => 'Invalid Stripe checkout session.'];
		}
		$response = self::request('GET', '/checkout/sessions/' . rawurlencode($sessionId));
		if(!$response['ok']){
			return ['status' => 'failed', 'msg' => 'Could not confirm the Stripe payment yet. Please refresh this page shortly.'];
		}
		$session = $response['data'];
		if(($session['status'] ?? '') !== 'complete' || ($session['payment_status'] ?? '') !== 'paid'){
			return ['status' => 'pending', 'msg' => 'Stripe has not confirmed this payment yet. Please wait a moment or contact the shop.'];
		}
		if(!self::finalizePaidSession($conn, $session)){
			return ['status' => 'failed', 'msg' => 'Stripe received the payment, but the booking needs staff review. Please contact the shop.'];
		}
		return ['status' => 'success', 'msg' => 'Stripe test payment confirmed. Your booking is marked as paid.'];
	}

	public static function handleWebhook(mysqli $conn, $payload, $signature){
		if(!self::configured() || !function_exists('curl_init')){
			error_log('Stripe webhook received while test mode is not correctly configured.');
			return ['http_status' => 503, 'status' => 'failed'];
		}
		$webhookSecret = getenv('STRIPE_WEBHOOK_SECRET');
		if(!is_string($webhookSecret) || strncmp($webhookSecret, 'whsec_', 6) !== 0){
			error_log('Stripe webhook signing secret is not configured.');
			return ['http_status' => 503, 'status' => 'failed'];
		}
		$timestamp = null;
		$signatures = [];
		foreach(explode(',', (string) $signature) as $part){
			$pair = explode('=', trim($part), 2);
			if(count($pair) !== 2){
				continue;
			}
			if($pair[0] === 't' && ctype_digit($pair[1])){
				$timestamp = (int) $pair[1];
			}elseif($pair[0] === 'v1'){
				$signatures[] = $pair[1];
			}
		}
		if($timestamp === null || abs(time() - $timestamp) > 300 || count($signatures) === 0){
			return ['http_status' => 400, 'status' => 'failed'];
		}
		$expectedSignature = hash_hmac('sha256', $timestamp . '.' . $payload, $webhookSecret);
		$signatureIsValid = false;
		foreach($signatures as $candidate){
			if(hash_equals($expectedSignature, $candidate)){
				$signatureIsValid = true;
			}
		}
		if(!$signatureIsValid){
			return ['http_status' => 400, 'status' => 'failed'];
		}
		$event = json_decode($payload, true);
		if(!is_array($event) || !isset($event['type'], $event['data']['object'])){
			return ['http_status' => 400, 'status' => 'failed'];
		}
		if($event['type'] !== 'checkout.session.completed'){
			return ['http_status' => 200, 'status' => 'ignored'];
		}
		if(!self::finalizePaidSession($conn, $event['data']['object'])){
			return ['http_status' => 500, 'status' => 'failed'];
		}
		return ['http_status' => 200, 'status' => 'success'];
	}

	private static function finalizePaidSession(mysqli $conn, array $session){
		$sessionId = isset($session['id']) ? (string) $session['id'] : '';
		$metadata = isset($session['metadata']) && is_array($session['metadata']) ? $session['metadata'] : [];
		$paymentId = isset($metadata['payment_id']) ? filter_var($metadata['payment_id'], FILTER_VALIDATE_INT) : false;
		$bookingIdFromMetadata = isset($metadata['booking_id']) ? filter_var($metadata['booking_id'], FILTER_VALIDATE_INT) : false;
		$amountCents = isset($session['amount_total']) ? filter_var($session['amount_total'], FILTER_VALIDATE_INT) : false;
		if(
			!preg_match('/^cs_test_[A-Za-z0-9_]+$/', $sessionId) ||
			$paymentId === false || $paymentId < 1 ||
			$bookingIdFromMetadata === false || $bookingIdFromMetadata < 1 ||
			$amountCents === false || $amountCents < 1 ||
			($session['mode'] ?? '') !== 'payment' ||
			($session['payment_status'] ?? '') !== 'paid' ||
			strtolower((string) ($session['currency'] ?? '')) !== 'php'
		){
			error_log('Stripe reported a paid checkout session with invalid payment details.');
			return false;
		}

		try{
			$conn->begin_transaction();
			$payment = $conn->prepare(
				"SELECT p.booking_id, p.amount_cents, p.status, p.transaction_ref,
				        b.payment_status, b.status, b.is_archived
				 FROM booking_online_payments p
				 INNER JOIN booking_list b ON b.id = p.booking_id
				 WHERE p.id = ? AND p.payment_provider = 'stripe'
				 LIMIT 1 FOR UPDATE"
			);
			$payment->bind_param('i', $paymentId);
			$payment->execute();
			$payment->bind_result($bookingId, $storedAmountCents, $paymentStatus, $storedSessionId, $bookingPaymentStatus, $bookingStatus, $isArchived);
			$found = $payment->fetch();
			$payment->close();
			if(
				!$found ||
				(int) $bookingId !== (int) $bookingIdFromMetadata ||
				(int) $storedAmountCents !== (int) $amountCents ||
				($storedSessionId !== null && $storedSessionId !== '' && !hash_equals((string) $storedSessionId, $sessionId))
			){
				$conn->rollback();
				error_log('Stripe checkout details did not match the stored payment.');
				return false;
			}
			if($paymentStatus === 'paid' && $bookingPaymentStatus === 'paid'){
				$conn->commit();
				return true;
			}
			if(
				!in_array($paymentStatus, ['creating', 'pending'], true) ||
				$bookingPaymentStatus !== 'unpaid' ||
				(int) $bookingStatus === 5 ||
				(int) $isArchived === 1
			){
				$conn->rollback();
				error_log('Stripe payment could not be applied because the booking is no longer payable.');
				return false;
			}
			$savePayment = $conn->prepare(
				"UPDATE booking_online_payments
				 SET status = 'paid', transaction_ref = ?, paid_at = NOW()
				 WHERE id = ? AND payment_provider = 'stripe'
				   AND status IN ('creating', 'pending')"
			);
			$savePayment->bind_param('si', $sessionId, $paymentId);
			$savePayment->execute();
			if($savePayment->affected_rows !== 1){
				$savePayment->close();
				$conn->rollback();
				return false;
			}
			$savePayment->close();
			$saveBooking = $conn->prepare(
				"UPDATE booking_list
				 SET payment_status = 'paid', payment_method = 'stripe',
				     status = IF(status = 0, 1, status), payment_paid_at = NOW(),
				     payment_received_by = NULL, cash_balance_amount = 0
				 WHERE id = ? AND payment_status = 'unpaid' AND status <> 5 AND is_archived = 0"
			);
			$saveBooking->bind_param('i', $bookingId);
			$saveBooking->execute();
			if($saveBooking->affected_rows !== 1){
				$saveBooking->close();
				$conn->rollback();
				error_log('Stripe payment was received but its booking could not be marked paid.');
				return false;
			}
			$saveBooking->close();
			if(!$conn->commit()){
				$conn->rollback();
				error_log('Stripe payment confirmation transaction could not be committed.');
				return false;
			}
			return true;
		}catch(mysqli_sql_exception $error){
			$conn->rollback();
			error_log('Stripe payment confirmation failed: ' . $error->getMessage());
			return false;
		}
	}
}
