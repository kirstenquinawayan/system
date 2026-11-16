<?php
require_once '../config.php';

class CustomerNotifications extends DBConnection {
	private function respond($status, $message){
		header('Content-Type: application/json; charset=utf-8');
		return json_encode(array('status' => $status, 'msg' => $message));
	}

	private function customerId(){
		$id = isset($_SESSION['customer_userdata']['id'])
			? filter_var($_SESSION['customer_userdata']['id'], FILTER_VALIDATE_INT)
			: false;
		return $id !== false && $id > 0 ? $id : false;
	}

	public function dismiss(){
		if($_SERVER['REQUEST_METHOD'] !== 'POST'){
			http_response_code(405);
			return $this->respond('failed', 'Please submit the request again.');
		}
		$customerId = $this->customerId();
		if($customerId === false){
			http_response_code(401);
			return $this->respond('failed', 'Sign in to manage your notifications.');
		}
		if(
			!isset($_POST['csrf_token'], $_SESSION['customer_auth_csrf']) ||
			!is_string($_POST['csrf_token']) ||
			!hash_equals((string) $_SESSION['customer_auth_csrf'], $_POST['csrf_token'])
		){
			http_response_code(400);
			return $this->respond('failed', 'Your session expired. Refresh the page and try again.');
		}

		$notificationId = isset($_POST['notification_id'])
			? filter_var($_POST['notification_id'], FILTER_VALIDATE_INT)
			: false;
		$bookingId = isset($_POST['booking_id'])
			? filter_var($_POST['booking_id'], FILTER_VALIDATE_INT)
			: false;
		if(
			($notificationId === false && $bookingId === false) ||
			($notificationId !== false && $bookingId !== false) ||
			($notificationId !== false && $notificationId < 1) ||
			($bookingId !== false && $bookingId < 1)
		){
			http_response_code(400);
			return $this->respond('failed', 'Choose a valid notification or booking.');
		}

		if($notificationId !== false){
			return $this->dismissNotification($customerId, $notificationId);
		}
		return $this->dismissBooking($customerId, $bookingId);
	}

	private function dismissNotification($customerId, $notificationId){
		$update = $this->conn->prepare(
			"UPDATE customer_booking_notifications
			 SET dismissed_at = COALESCE(dismissed_at, NOW())
			 WHERE id = ? AND customer_id = ?"
		);
		if(!$update){
			error_log('Customer notification dismiss prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Could not remove this notification. Please try again.');
		}
		$update->bind_param('ii', $notificationId, $customerId);
		if(!$update->execute()){
			error_log('Customer notification dismiss failed: ' . $update->error);
			$update->close();
			http_response_code(503);
			return $this->respond('failed', 'Could not remove this notification. Please try again.');
		}
		$found = $update->affected_rows === 1;
		$update->close();
		if(!$found){
			$check = $this->conn->prepare(
				"SELECT id FROM customer_booking_notifications
				 WHERE id = ? AND customer_id = ? AND dismissed_at IS NOT NULL LIMIT 1"
			);
			if(!$check){
				error_log('Customer dismissed notification lookup prepare failed: ' . $this->conn->error);
				http_response_code(503);
				return $this->respond('failed', 'Could not confirm this notification was removed.');
			}
			$check->bind_param('ii', $notificationId, $customerId);
			if(!$check->execute()){
				error_log('Customer dismissed notification lookup failed: ' . $check->error);
				$check->close();
				http_response_code(503);
				return $this->respond('failed', 'Could not confirm this notification was removed.');
			}
			$check->store_result();
			$alreadyDismissed = $check->num_rows === 1;
			$check->close();
			if(!$alreadyDismissed){
				http_response_code(404);
				return $this->respond('failed', 'This notification was not found.');
			}
		}
		return $this->respond('success', 'Notification removed.');
	}

	private function dismissBooking($customerId, $bookingId){
		$check = $this->conn->prepare(
			"SELECT id FROM booking_list WHERE id = ? AND customer_id = ? LIMIT 1"
		);
		if(!$check){
			error_log('Customer booking dismiss lookup prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Could not remove this booking. Please try again.');
		}
		$check->bind_param('ii', $bookingId, $customerId);
		if(!$check->execute()){
			error_log('Customer booking dismiss lookup failed: ' . $check->error);
			$check->close();
			http_response_code(503);
			return $this->respond('failed', 'Could not remove this booking. Please try again.');
		}
		$check->store_result();
		$bookingExists = $check->num_rows === 1;
		$check->close();
		if(!$bookingExists){
			http_response_code(404);
			return $this->respond('failed', 'This booking was not found.');
		}

		$insert = $this->conn->prepare(
			"INSERT INTO customer_booking_dismissals (customer_id, booking_id)
			 VALUES (?, ?)
			 ON DUPLICATE KEY UPDATE booking_id = VALUES(booking_id)"
		);
		if(!$insert){
			error_log('Customer booking dismiss prepare failed: ' . $this->conn->error);
			http_response_code(503);
			return $this->respond('failed', 'Could not remove this booking. Please try again.');
		}
		$insert->bind_param('ii', $customerId, $bookingId);
		if(!$insert->execute()){
			error_log('Customer booking dismiss failed: ' . $insert->error);
			$insert->close();
			http_response_code(503);
			return $this->respond('failed', 'Could not remove this booking. Please try again.');
		}
		$insert->close();
		return $this->respond('success', 'Booking removed from notifications.');
	}
}

$notifications = new CustomerNotifications();
echo $notifications->dismiss();
