<?php
require_once('../config.php');
require_once(__DIR__ . '/StripeTestCheckout.php');
Class Master extends DBConnection {
	private $settings;
	public function __construct(){
		global $_settings;
		$this->settings = $_settings;
		parent::__construct();
	}
	public function __destruct(){
		parent::__destruct();
	}
	function capture_err(){
		if(!$this->conn->error)
			return false;
		else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
			return json_encode($resp);
			exit;
		}
	}
	function save_vehicle(){
		extract($_POST);
		$data = "";
		foreach($_POST as $k =>$v){
			if(!in_array($k,array('id'))){
				if(!empty($data)) $data .=",";
				$v = addslashes(trim($v));
				$data .= " `{$k}`='{$v}' ";
			}
		}
		$check = $this->conn->query("SELECT * FROM `vehicle_list` where `name` = '{$name}' ".(!empty($id) ? " and id != {$id} " : "")." ")->num_rows;
		if($this->capture_err())
			return $this->capture_err();
		if($check > 0){
			$resp['status'] = 'failed';
			$resp['msg'] = "Vehicle Name already exists.";
			return json_encode($resp);
			exit;
		}
		if(empty($id)){
			$sql = "INSERT INTO `vehicle_list` set {$data} ";
		}else{
			$sql = "UPDATE `vehicle_list` set {$data} where id = '{$id}' ";
		}
			$save = $this->conn->query($sql);
		if($save){
			$bid = !empty($id) ? $id : $this->conn->insert_id;
			$resp['status'] = 'success';
			if(empty($id))
				$resp['msg'] = "New Vehicle successfully saved.";
			else
				$resp['msg'] = " Vehicle successfully updated.";
			
		}else{
			$resp['status'] = 'failed';
			$resp['err'] = $this->conn->error."[{$sql}]";
		}
		if($resp['status'] == 'success')
			$this->settings->set_flashdata('success',$resp['msg']);
			return json_encode($resp);
	}
	function delete_vehicle(){
		extract($_POST);
		$del = $this->conn->query("UPDATE `vehicle_list` set `delete_flag` = 1 where id = '{$id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," Vehicle successfully deleted.");
		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);

	}
	function save_service(){
		extract($_POST);
		$durationMinutes = isset($_POST['duration_minutes'])
			? filter_var($_POST['duration_minutes'], FILTER_VALIDATE_INT)
			: false;
		if($durationMinutes === false || $durationMinutes < 1 || $durationMinutes > 4320){
			return json_encode(['status' => 'failed', 'msg' => 'Service duration must be between 1 and 4320 minutes (3 days).']);
		}
		$data = "";
		foreach($_POST as $k =>$v){
			if(!in_array($k,array('id'))){
				if(!empty($data)) $data .=",";
				$data .= " `{$k}`='{$this->conn->real_escape_string($v)}' ";
			}
		}
		$check = $this->conn->query("SELECT * FROM `service_list` where `name` = '{$name}' ".(!empty($id) ? " and id != {$id} " : "")." ")->num_rows;
		if($this->capture_err())
			return $this->capture_err();
		if($check > 0){
			$resp['status'] = 'failed';
			$resp['msg'] = "Service already exist.";
			return json_encode($resp);
			exit;
		}
		if(empty($id)){
			$sql = "INSERT INTO `service_list` set {$data} ";
			$save = $this->conn->query($sql);
		}else{
			$sql = "UPDATE `service_list` set {$data} where id = '{$id}' ";
			$save = $this->conn->query($sql);
		}
		if($save){
			$resp['status'] = 'success';
			if(empty($id))
				$this->settings->set_flashdata('success'," New Service successfully saved.");
			else
				$this->settings->set_flashdata('success'," Service successfully updated.");
		}else{
			$resp['status'] = 'failed';
			$resp['err'] = $this->conn->error."[{$sql}]";
		}
		return json_encode($resp);
	}
	function delete_service(){
		extract($_POST);
		$del = $this->conn->query("UPDATE `service_list` set delete_flag = 1 where id = '{$id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," Service successfully deleted.");
		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);

	}
	function save_price(){
		extract($_POST);
		$data = "";
		foreach($price as $k => $v){
			if(!empty($data)) $data .=", ";
			$data .= "('{$id}', '{$vehicle_id[$k]}', '{$v}')";
		}
		if(!empty($data)){
			$this->conn->query("DELETE FROM `price_list` where service_id = '{$id}'");
			$sql = "INSERT INTO `price_list` (`service_id`, `vehicle_id`, `price`) VALUES {$data}";
			$save = $this->conn->query($sql);
			if($save){
				$resp['status'] = 'success';
				$resp['msg'] = 'Price List has been updated successfully';
			}else{
				$resp['status'] = 'failed';
				$resp['msg'] = 'An error occurred while saving the data.';
				$resp['error'] = $this->conn->error;
				$resp['sql'] = $sql;
			}
		}else{
			$resp['status'] = 'failed';
			$resp['msg'] = 'No Price Data has been sent.';
		}
		if($resp['status'] == 'success' && isset($resp['msg']))
		$this->settings->set_flashdata('success', $resp['msg']);
		return json_encode($resp);
	}
	function get_vehicle_service(){
		$vehicleId = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : false;
		if($vehicleId === false || $vehicleId < 1){
			return json_encode(['status' => 'failed', 'msg' => 'Select a valid vehicle type.']);
		}
		$vehicleStatement = $this->conn->prepare(
			"SELECT id FROM vehicle_list WHERE id = ? AND status = 1 AND delete_flag = 0 LIMIT 1"
		);
		if(!$vehicleStatement){
			error_log('Booking vehicle price validation prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Vehicle prices are temporarily unavailable.']);
		}
		$vehicleStatement->bind_param('i', $vehicleId);
		if(!$vehicleStatement->execute()){
			error_log('Booking vehicle price validation failed: ' . $vehicleStatement->error);
			$vehicleStatement->close();
			return json_encode(['status' => 'failed', 'msg' => 'Vehicle prices are temporarily unavailable.']);
		}
		$vehicleStatement->store_result();
		$vehicleFound = $vehicleStatement->num_rows === 1;
		$vehicleStatement->close();
		if(!$vehicleFound){
			return json_encode(['status' => 'failed', 'msg' => 'The selected vehicle type is unavailable.']);
		}
		$priceStatement = $this->conn->prepare(
			"SELECT s.id, s.name, p.price, s.duration_minutes
			 FROM service_list s
			 LEFT JOIN price_list p ON p.service_id = s.id AND p.vehicle_id = ?
			 WHERE s.status = 1 AND s.delete_flag = 0
			 ORDER BY CASE s.name
				WHEN 'Car Wash' THEN 0
				WHEN 'Car Repaint' THEN 1
				ELSE 2
			 END, s.name ASC"
		);
		if(!$priceStatement){
			error_log('Booking vehicle prices prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Vehicle prices are temporarily unavailable.']);
		}
		$priceStatement->bind_param('i', $vehicleId);
		if(!$priceStatement->execute()){
			error_log('Booking vehicle prices query failed: ' . $priceStatement->error);
			$priceStatement->close();
			return json_encode(['status' => 'failed', 'msg' => 'Vehicle prices are temporarily unavailable.']);
		}
		$priceStatement->bind_result($serviceId, $serviceName, $servicePrice, $durationMinutes);
		$data = [];
		$durationEstimates = [
			'car wash' => '30-45 minutes',
			'engine wash' => '30-60 minutes',
			'under wash' => '30-45 minutes',
			'underwash' => '30-45 minutes',
			'undercoat' => '1-3 hours',
			'detailing' => '2-5 hours',
			'car detailing' => '2-5 hours',
			'ceramic' => '3-8 hours',
			'ceramic coating' => '3-8 hours',
			'painting' => '1-3 days',
			'car painting' => '1-3 days',
		];
		while($priceStatement->fetch()){
			$serviceNameKey = strtolower(trim($serviceName));
			$data[] = [
				'id' => (int) $serviceId,
				'name' => $serviceName,
				'price' => $servicePrice === null ? null : (float) $servicePrice,
				'formatted_price' => $servicePrice === null ? null : format_num($servicePrice),
				'estimated_duration' => isset($durationEstimates[$serviceNameKey])
					? $durationEstimates[$serviceNameKey]
					: format_duration_minutes($durationMinutes),
				'duration_minutes' => (int) $durationMinutes,
			];
		}
		$priceStatement->close();
		return json_encode(['status' => 'success', 'data' => $data]);
	}
	private function acquire_booking_slot_lock($schedule){
		$lockName = 'ocwbs_' . substr(hash('sha256', $schedule), 0, 32);
		$statement = $this->conn->prepare("SELECT GET_LOCK(?, 5)");
		if(!$statement){
			error_log('Booking slot lock prepare failed: ' . $this->conn->error);
			return false;
		}
		$statement->bind_param('s', $lockName);
		if(!$statement->execute()){
			error_log('Booking slot lock failed: ' . $statement->error);
			$statement->close();
			return false;
		}
		$statement->bind_result($locked);
		$acquired = $statement->fetch() && (int) $locked === 1;
		$statement->close();
		return $acquired ? $lockName : false;
	}
	private function release_booking_slot_lock($lockName){
		$statement = $this->conn->prepare("SELECT RELEASE_LOCK(?)");
		if(!$statement){
			error_log('Booking slot unlock prepare failed: ' . $this->conn->error);
			return;
		}
		$statement->bind_param('s', $lockName);
		if(!$statement->execute()){
			error_log('Booking slot unlock failed: ' . $statement->error);
		}
		$statement->close();
	}
	private function release_booking_slot_locks($lockNames){
		foreach($lockNames as $lockName){
			$this->release_booking_slot_lock($lockName);
		}
	}
	private function is_authorized_booking_staff(){
		$userType = isset($_SESSION['userdata']['type']) ? (int) $_SESSION['userdata']['type'] : 0;
		return isset($_SESSION['userdata']['login_type']) &&
			(int) $_SESSION['userdata']['login_type'] === 1 &&
			in_array($userType, [1, 2], true) &&
			isset($_SESSION['userdata']['id']) &&
			filter_var($_SESSION['userdata']['id'], FILTER_VALIDATE_INT) > 0;
	}
	function get_available_slots(){
		$date = isset($_POST['schedule']) && is_string($_POST['schedule']) ? $_POST['schedule'] : '';
		$parsedDate = DateTime::createFromFormat('!Y-m-d', $date);
		$dateErrors = DateTime::getLastErrors();
		if(
			!$parsedDate ||
			($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
			$parsedDate->format('Y-m-d') !== $date ||
			$parsedDate->format('Y-m-d') < date('Y-m-d')
		){
			return json_encode(['status' => 'success', 'data' => []]);
		}
		$scheduleBounds = booking_schedule_bounds($date);
		if(!$scheduleBounds){
			return json_encode(['status' => 'failed', 'msg' => 'Could not load appointment hours for this date.']);
		}
		if($date === date('Y-m-d') && time() >= $scheduleBounds['closing']){
			return json_encode(['status' => 'success', 'data' => []]);
		}
		$countsStatement = $this->conn->prepare(
			"SELECT TIME_FORMAT(schedule, '%H:%i') AS slot_time, COUNT(*) AS total
			 FROM booking_list
			 WHERE schedule >= ? AND schedule < DATE_ADD(?, INTERVAL 1 DAY)
			   AND status <> 5 AND is_archived = 0
			 GROUP BY schedule"
		);
		if(!$countsStatement){
			error_log('Booking slot availability prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Appointment availability is temporarily unavailable.']);
		}
		$dayStart = $date . ' 00:00:00';
		$countsStatement->bind_param('ss', $dayStart, $dayStart);
		if(!$countsStatement->execute()){
			error_log('Booking slot availability query failed: ' . $countsStatement->error);
			$countsStatement->close();
			return json_encode(['status' => 'failed', 'msg' => 'Appointment availability is temporarily unavailable.']);
		}
		$countsStatement->bind_result($slotTime, $bookingCount);
		$booked = [];
		while($countsStatement->fetch()){
			$booked[$slotTime] = (int) $bookingCount;
		}
		$countsStatement->close();
		$slots = [];
		$opening = $scheduleBounds['opening'];
		$closing = $scheduleBounds['closing'];
		$now = time();
		for($timestamp = $opening; $timestamp < $closing; $timestamp += BOOKING_SLOT_MINUTES * 60){
			$time = date('H:i', $timestamp);
			$remaining = max(0, BOOKING_SLOT_CAPACITY - (isset($booked[$time]) ? $booked[$time] : 0));
			$isPast = $date === date('Y-m-d') && $timestamp < $now;
			$slots[] = [
				'time' => $time,
				'available' => $remaining,
				'past' => $isPast,
			];
		}
		return json_encode(['status' => 'success', 'data' => $slots]);
	}
	function save_booking(){
		$rawFields = ['plate_number', 'client_name', 'contact', 'email', 'address', 'schedule', 'schedule_time'];
		foreach($rawFields as $field){
			if(!isset($_POST[$field]) || !is_string($_POST[$field])){
				return json_encode(['status' => 'failed', 'msg' => 'Please enter valid booking details.']);
			}
		}
		$plate_number = strtoupper(trim($_POST['plate_number']));
		$plate_number = preg_replace('/\s+/', ' ', $plate_number);
		if(!preg_match('/^(?:[A-Z]{3} ?[0-9]{4}|[A-Z]{2} ?[0-9]{5}|[0-9]{1,6})$/', $plate_number)){
			return json_encode([
				'status' => 'failed',
				'msg' => 'Enter a vehicle plate like AAA 0123, a motorcycle plate like AA 12345, a protocol number, or a 6-digit diplomatic plate.'
			]);
		}

		$clientName = trim($_POST['client_name']);
		$contact = trim($_POST['contact']);
		$email = trim($_POST['email']);
		$address = trim($_POST['address']);
		$vehicleId = isset($_POST['vehicle_id']) ? filter_var($_POST['vehicle_id'], FILTER_VALIDATE_INT) : false;
		$schedule = $_POST['schedule'];
		$scheduleTime = $_POST['schedule_time'];
		$selectedServices = isset($_POST['service_check']) && is_array($_POST['service_check'])
			? array_keys($_POST['service_check'])
			: [];

		if($clientName === '' || $address === '' || !preg_match('/^0[0-9]{10}$/', $contact) || !filter_var($email, FILTER_VALIDATE_EMAIL)){
			return json_encode(['status' => 'failed', 'msg' => 'Please enter valid booking and contact details.']);
		}
		$appointment = DateTime::createFromFormat('!Y-m-d H:i', $schedule . ' ' . $scheduleTime);
		$dateErrors = DateTime::getLastErrors();
		if(
			!$appointment ||
			($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) ||
			$appointment->format('Y-m-d') !== $schedule ||
			$appointment->format('H:i') !== $scheduleTime ||
			$appointment->format('Y-m-d') < date('Y-m-d')
		){
			return json_encode(['status' => 'failed', 'msg' => 'Please choose a valid appointment date and a 1-hour appointment slot.']);
		}
		$scheduleBounds = booking_schedule_bounds($schedule);
		if(
			!$scheduleBounds ||
			$appointment->getTimestamp() < $scheduleBounds['opening'] ||
			$appointment->getTimestamp() >= $scheduleBounds['closing'] ||
			($schedule === date('Y-m-d') && time() >= $scheduleBounds['closing'])
		){
			return json_encode(['status' => 'failed', 'msg' => 'Please choose an available slot within the shop hours: Monday-Friday 7:00 AM-11:00 PM, Saturday-Sunday 7:00 AM-12:00 AM.']);
		}
		if(($appointment->getTimestamp() - $scheduleBounds['opening']) % (BOOKING_SLOT_MINUTES * 60) !== 0){
			return json_encode(['status' => 'failed', 'msg' => 'Please choose an available appointment time in 1-hour intervals from the shop opening time.']);
		}
		$schedule = $appointment->format('Y-m-d H:i:s');
		if($vehicleId === false || $vehicleId < 1 || count($selectedServices) === 0){
			return json_encode(['status' => 'failed', 'msg' => 'Please select a vehicle type and at least one service.']);
		}
		$vehicleStatement = $this->conn->prepare(
			"SELECT name FROM vehicle_list WHERE id = ? AND status = 1 AND delete_flag = 0 LIMIT 1"
		);
		if(!$vehicleStatement){
			error_log('Booking vehicle lookup prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'An error occurred while preparing your booking.']);
		}
		$vehicleStatement->bind_param('i', $vehicleId);
		if(!$vehicleStatement->execute()){
			$error = $vehicleStatement->error;
			$vehicleStatement->close();
			error_log('Booking vehicle lookup failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'An error occurred while preparing your booking.']);
		}
		$vehicleStatement->bind_result($vehicleName);
		$vehicleIsAvailable = $vehicleStatement->fetch();
		$vehicleStatement->close();
		if(!$vehicleIsAvailable){
			return json_encode(['status' => 'failed', 'msg' => 'The selected vehicle type is not available.']);
		}

		$selectedServices = array_values(array_unique(array_map('intval', $selectedServices)));
		$services = [];
		$totalCents = 0;
		$priceStatement = $this->conn->prepare(
			"SELECT s.name, p.price, s.duration_minutes
			 FROM price_list p
			 INNER JOIN service_list s ON s.id = p.service_id
			 WHERE p.service_id = ? AND p.vehicle_id = ? AND s.status = 1 AND s.delete_flag = 0
			 LIMIT 1"
		);
		if(!$priceStatement){
			error_log('Booking price lookup prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'An error occurred while preparing your booking.']);
		}
		foreach($selectedServices as $serviceId){
			if($serviceId < 1){
				$priceStatement->close();
				return json_encode(['status' => 'failed', 'msg' => 'Please select valid services.']);
			}
			$priceStatement->bind_param('ii', $serviceId, $vehicleId);
			if(!$priceStatement->execute()){
				$error = $priceStatement->error;
				$priceStatement->close();
				error_log('Booking price lookup failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'An error occurred while preparing your booking.']);
			}
			$priceStatement->bind_result($serviceName, $servicePrice, $serviceDuration);
			if(!$priceStatement->fetch()){
				$priceStatement->free_result();
				$priceStatement->close();
				return json_encode(['status' => 'failed', 'msg' => 'One or more selected services are not available for this vehicle.']);
			}
			$priceCents = (int) round((float) $servicePrice * 100);
			$services[] = [
				'id' => $serviceId,
				'name' => $serviceName,
				'price' => $priceCents / 100,
				'formatted_price' => number_format($priceCents / 100, 2),
				'duration_minutes' => (int) $serviceDuration,
			];
			$totalCents += $priceCents;
			$priceStatement->free_result();
		}
		$priceStatement->close();
		if($totalCents < 1){
			return json_encode(['status' => 'failed', 'msg' => 'The booking total must be greater than zero.']);
		}

		$prefix = date("Ym");
		$receiptToken = bin2hex(random_bytes(32));
		$codeNumber = 1;
		$codeStatement = $this->conn->prepare("SELECT 1 FROM booking_list WHERE code = ? LIMIT 1");
		if(!$codeStatement){
			error_log('Booking code lookup prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'An error occurred while preparing your booking.']);
		}
		do{
			$code = $prefix . sprintf('%05d', $codeNumber++);
			$codeStatement->bind_param('s', $code);
			if(!$codeStatement->execute()){
				$error = $codeStatement->error;
				$codeStatement->close();
				error_log('Booking code lookup failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'An error occurred while preparing your booking.']);
			}
			$codeStatement->store_result();
			$exists = $codeStatement->num_rows > 0;
			$codeStatement->free_result();
		}while($exists);
		$codeStatement->close();

		$slotLock = $this->acquire_booking_slot_lock($schedule);
		if($slotLock === false){
			return json_encode(['status' => 'failed', 'msg' => 'This appointment slot is being booked. Please try again.']);
		}
		$capacityStatement = $this->conn->prepare(
			"SELECT COUNT(*) FROM booking_list WHERE schedule = ? AND status <> 5 AND is_archived = 0"
		);
		if(!$capacityStatement){
			$this->release_booking_slot_lock($slotLock);
			error_log('Booking capacity check prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Appointment availability is temporarily unavailable.']);
		}
		$capacityStatement->bind_param('s', $schedule);
		if(!$capacityStatement->execute()){
			$error = $capacityStatement->error;
			$capacityStatement->close();
			$this->release_booking_slot_lock($slotLock);
			error_log('Booking capacity check failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'Appointment availability is temporarily unavailable.']);
		}
		$capacityStatement->bind_result($bookingsInSlot);
		$capacityStatement->fetch();
		$capacityStatement->close();
		if((int) $bookingsInSlot >= BOOKING_SLOT_CAPACITY){
			$this->release_booking_slot_lock($slotLock);
			return json_encode(['status' => 'failed', 'msg' => 'That appointment slot is full. Please select another available time.']);
		}
		$this->conn->begin_transaction();
		$paymentStatus = 'unpaid';
		$reservationFeePaymentStatus = 'not_required';
		$reservationFee = BOOKING_RESERVATION_FEE;
		$totalAmount = ($totalCents / 100) + $reservationFee;
		$status = 0;
		$insert = $this->conn->prepare(
			"INSERT INTO booking_list
			 (code, client_name, contact, plate_number, email, address, vehicle_id, schedule, total_amount, reservation_fee, reservation_fee_payment_status, status, payment_status, receipt_pdf_token)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
		);
		if(!$insert){
			$this->conn->rollback();
			$this->release_booking_slot_lock($slotLock);
			error_log('Booking insert prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Your booking could not be saved. Please try again.']);
		}
		$insert->bind_param(
			'ssssssisddsiss',
			$code,
			$clientName,
			$contact,
			$plate_number,
			$email,
			$address,
			$vehicleId,
			$schedule,
			$totalAmount,
			$reservationFee,
			$reservationFeePaymentStatus,
			$status,
			$paymentStatus,
			$receiptToken
		);
		if(!$insert->execute()){
			$error = $insert->error;
			$insert->close();
			$this->conn->rollback();
			$this->release_booking_slot_lock($slotLock);
			error_log('Booking insert failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'Your booking could not be saved. Please try again.']);
		}
		$bookingId = $this->conn->insert_id;
		$insert->close();

		$serviceInsert = $this->conn->prepare(
			"INSERT INTO booking_services (booking_id, service_id, price, duration_minutes) VALUES (?, ?, ?, ?)"
		);
		if(!$serviceInsert){
			$this->conn->rollback();
			$this->release_booking_slot_lock($slotLock);
			error_log('Booking service insert prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Your booking could not be saved. Please try again.']);
		}
		foreach($services as $service){
			$serviceId = $service['id'];
			$servicePrice = $service['price'];
			$serviceDuration = $service['duration_minutes'];
			$serviceInsert->bind_param('iidi', $bookingId, $serviceId, $servicePrice, $serviceDuration);
			if(!$serviceInsert->execute()){
				$error = $serviceInsert->error;
				$serviceInsert->close();
				$this->conn->rollback();
				$this->release_booking_slot_lock($slotLock);
				error_log('Booking service insert failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'Your booking could not be saved. Please try again.']);
			}
		}
		$serviceInsert->close();
		if(!$this->conn->commit()){
			$this->conn->rollback();
			$this->release_booking_slot_lock($slotLock);
			error_log('Booking transaction commit failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Your booking could not be saved. Please try again.']);
		}
		$this->release_booking_slot_lock($slotLock);

		$receiptServices = [];
		foreach($services as $service){
			$receiptServices[] = [
				'name' => $service['name'],
				'formatted_price' => $service['formatted_price'],
			];
		}
		$receipt = [
			'code' => $code,
			'client_name' => $clientName,
			'contact' => $contact,
			'plate_number' => $plate_number,
			'vehicle' => $vehicleName,
			'schedule' => date('M d, Y g:i A', strtotime($schedule)),
			'services' => $receiptServices,
			'formatted_subtotal' => number_format($totalCents / 100, 2),
			'formatted_reservation_fee' => number_format($reservationFee, 2),
			'formatted_total' => number_format($totalAmount, 2),
			'pdf_token' => $receiptToken,
		];
		return json_encode([
			'status' => 'success',
			'bid' => $bookingId,
			'code' => $code,
			'msg' => 'Booking submitted successfully. Booking reference: ' . $code . '. Please pay at the shop cashier.',
			'receipt' => $receipt,
		]);
	}
	private function send_payment_verification_sms($phoneNumber, $message){
		if(!function_exists('curl_init')){
			error_log('Payment verification SMS unavailable: PHP cURL extension is not enabled.');
			return false;
		}
		$apiKey = getenv('SEMAPHORE_API_KEY');
		if(!is_string($apiKey) || trim($apiKey) === ''){
			error_log('Payment verification SMS unavailable: SEMAPHORE_API_KEY is not configured.');
			return false;
		}
		$payload = [
			'apikey' => trim($apiKey),
			'number' => $phoneNumber,
			'message' => $message,
		];
		$senderName = getenv('SEMAPHORE_SENDER_NAME');
		if(is_string($senderName) && trim($senderName) !== ''){
			$payload['sendername'] = trim($senderName);
		}
		$curl = curl_init('https://api.semaphore.co/api/v4/messages');
		if($curl === false){
			error_log('Payment verification SMS unavailable: could not initialize HTTPS request.');
			return false;
		}
		$optionsSet = curl_setopt_array($curl, [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => http_build_query($payload),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
		]);
		if(!$optionsSet){
			curl_close($curl);
			error_log('Payment verification SMS unavailable: could not configure HTTPS request.');
			return false;
		}
		$response = curl_exec($curl);
		$curlError = curl_error($curl);
		$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);
		if($response === false){
			error_log('Payment verification SMS request failed: ' . $curlError);
			return false;
		}
		$responseData = json_decode($response, true);
		if(
			$statusCode < 200 ||
			$statusCode >= 300 ||
			!is_array($responseData) ||
			empty($responseData[0]['message_id'])
		){
			error_log('Payment verification SMS provider rejected the request (HTTP ' . $statusCode . ').');
			return false;
		}
		return true;
	}
	function send_payment_verification_code(){
		$token = isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : '';
		if(!preg_match('/^[a-f0-9]{64}$/', $token)){
			return json_encode(['status' => 'failed', 'msg' => 'Invalid booking verification request.']);
		}
		if(!function_exists('curl_init') || !is_string(getenv('SEMAPHORE_API_KEY')) || trim(getenv('SEMAPHORE_API_KEY')) === ''){
			error_log('Payment verification SMS unavailable: configure PHP cURL and SEMAPHORE_API_KEY.');
			return json_encode(['status' => 'failed', 'msg' => 'SMS verification is not configured yet. Please contact the shop or pay at the cashier.']);
		}
		$this->conn->begin_transaction();
		$booking = $this->conn->prepare(
			"SELECT id, contact, payment_status, status
			 FROM booking_list WHERE receipt_pdf_token = ? AND is_archived = 0 LIMIT 1 FOR UPDATE"
		);
		if(!$booking){
			$this->conn->rollback();
			error_log('Payment OTP booking lookup prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not load this booking for verification.']);
		}
		$booking->bind_param('s', $token);
		if(!$booking->execute()){
			$error = $booking->error;
			$booking->close();
			$this->conn->rollback();
			error_log('Payment OTP booking lookup failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not load this booking for verification.']);
		}
		$booking->bind_result($bookingId, $contact, $paymentStatus, $bookingStatus);
		$found = $booking->fetch();
		$booking->close();
		if(!$found || (int) $bookingStatus === 5 || $paymentStatus === 'paid'){
			$this->conn->rollback();
			return json_encode(['status' => 'failed', 'msg' => 'This booking is unavailable for payment verification.']);
		}
		if(!preg_match('/^09[0-9]{9}$/', (string) $contact)){
			$this->conn->rollback();
			return json_encode(['status' => 'failed', 'msg' => 'The customer contact number is not a valid Philippine mobile number. Please contact the shop.']);
		}
		$rateLimit = $this->conn->prepare(
			"SELECT COUNT(*), MAX(date_created)
			 FROM booking_payment_otps
			 WHERE booking_id = ? AND date_created >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
		);
		if(!$rateLimit){
			$this->conn->rollback();
			error_log('Payment OTP rate-limit prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not request an SMS code. Please try again later.']);
		}
		$rateLimit->bind_param('i', $bookingId);
		if(!$rateLimit->execute()){
			$error = $rateLimit->error;
			$rateLimit->close();
			$this->conn->rollback();
			error_log('Payment OTP rate-limit query failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not request an SMS code. Please try again later.']);
		}
		$rateLimit->bind_result($sentToday, $lastSentAt);
		$rateLimit->fetch();
		$rateLimit->close();
		if((int) $sentToday >= 5){
			$this->conn->rollback();
			return json_encode(['status' => 'failed', 'msg' => 'Too many verification codes were requested. Please try again tomorrow.']);
		}
		if($lastSentAt && time() - strtotime($lastSentAt) < 60){
			$this->conn->rollback();
			return json_encode(['status' => 'failed', 'msg' => 'Please wait one minute before requesting another verification code.']);
		}
		try{
			$code = (string) random_int(100000, 999999);
		}catch(Throwable $error){
			$this->conn->rollback();
			error_log('Payment OTP generation failed: ' . $error->getMessage());
			return json_encode(['status' => 'failed', 'msg' => 'Could not generate an SMS code. Please try again.']);
		}
		$codeHash = password_hash($code, PASSWORD_DEFAULT);
		if($codeHash === false){
			$this->conn->rollback();
			error_log('Payment OTP hashing failed.');
			return json_encode(['status' => 'failed', 'msg' => 'Could not generate an SMS code. Please try again.']);
		}
		$insert = $this->conn->prepare(
			"INSERT INTO booking_payment_otps (booking_id, otp_hash, status, expires_at)
			 VALUES (?, ?, 'sending', DATE_ADD(NOW(), INTERVAL 10 MINUTE))"
		);
		if(!$insert){
			$this->conn->rollback();
			error_log('Payment OTP insert prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not request an SMS code. Please try again.']);
		}
		$insert->bind_param('is', $bookingId, $codeHash);
		if(!$insert->execute()){
			$error = $insert->error;
			$insert->close();
			$this->conn->rollback();
			error_log('Payment OTP insert failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not request an SMS code. Please try again.']);
		}
		$otpId = (int) $insert->insert_id;
		$insert->close();
		if(!$this->conn->commit()){
			$this->conn->rollback();
			error_log('Payment OTP transaction commit failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not request an SMS code. Please try again.']);
		}
		$phoneNumber = '63' . substr($contact, 1);
		$message = 'Your OCWBS payment verification code is ' . $code . '. It expires in 10 minutes. Do not share this code.';
		if(!$this->send_payment_verification_sms($phoneNumber, $message)){
			$fail = $this->conn->prepare("UPDATE booking_payment_otps SET status = 'failed' WHERE id = ? AND status = 'sending'");
			if($fail){
				$fail->bind_param('i', $otpId);
				if(!$fail->execute()){
					error_log('Payment OTP failure status update failed: ' . $fail->error);
				}
				$fail->close();
			}else{
				error_log('Payment OTP failure status prepare failed: ' . $this->conn->error);
			}
			return json_encode(['status' => 'failed', 'msg' => 'Could not send the SMS code. Check the Semaphore setup or try again later.']);
		}
		$sent = $this->conn->prepare("UPDATE booking_payment_otps SET status = 'sent' WHERE id = ? AND status = 'sending'");
		if(!$sent){
			error_log('Payment OTP sent status prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'The SMS request was accepted but could not be confirmed. Contact the shop before requesting another code.']);
		}
		$sent->bind_param('i', $otpId);
		if(!$sent->execute()){
			error_log('Payment OTP sent status update failed: ' . $sent->error);
			$sent->close();
			return json_encode(['status' => 'failed', 'msg' => 'The SMS request was accepted but could not be confirmed. Contact the shop before requesting another code.']);
		}
		$sent->close();
		$maskedContact = substr($contact, 0, 2) . '******' . substr($contact, -3);
		return json_encode([
			'status' => 'success',
			'phone' => $maskedContact,
			'msg' => 'A verification code was sent by SMS to ' . $maskedContact . '.',
		]);
	}
	function verify_payment_verification_code(){
		$token = isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : '';
		$code = isset($_POST['code']) && is_string($_POST['code']) ? trim($_POST['code']) : '';
		if(!preg_match('/^[a-f0-9]{64}$/', $token) || !preg_match('/^[0-9]{6}$/', $code)){
			return json_encode(['status' => 'failed', 'msg' => 'Enter the six-digit SMS verification code.']);
		}
		$this->conn->begin_transaction();
		$booking = $this->conn->prepare(
			"SELECT id, payment_status, status
			 FROM booking_list WHERE receipt_pdf_token = ? AND is_archived = 0 LIMIT 1 FOR UPDATE"
		);
		if(!$booking){
			$this->conn->rollback();
			error_log('Payment OTP verification booking prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not verify this code. Please try again.']);
		}
		$booking->bind_param('s', $token);
		if(!$booking->execute()){
			$error = $booking->error;
			$booking->close();
			$this->conn->rollback();
			error_log('Payment OTP verification booking lookup failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not verify this code. Please try again.']);
		}
		$booking->bind_result($bookingId, $paymentStatus, $bookingStatus);
		$found = $booking->fetch();
		$booking->close();
		if(!$found || (int) $bookingStatus === 5 || $paymentStatus === 'paid'){
			$this->conn->rollback();
			return json_encode(['status' => 'failed', 'msg' => 'This booking is unavailable for payment verification.']);
		}
		$otp = $this->conn->prepare(
			"SELECT id, otp_hash, status, attempts, expires_at
			 FROM booking_payment_otps
			 WHERE booking_id = ? AND status IN ('sent', 'verified', 'locked')
			 ORDER BY id DESC LIMIT 1 FOR UPDATE"
		);
		if(!$otp){
			$this->conn->rollback();
			error_log('Payment OTP lookup prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not verify this code. Please request a new SMS code.']);
		}
		$otp->bind_param('i', $bookingId);
		if(!$otp->execute()){
			$error = $otp->error;
			$otp->close();
			$this->conn->rollback();
			error_log('Payment OTP lookup failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not verify this code. Please request a new SMS code.']);
		}
		$otp->bind_result($otpId, $otpHash, $otpStatus, $attempts, $expiresAt);
		$otpFound = $otp->fetch();
		$otp->close();
		if(!$otpFound || $otpStatus === 'locked' || strtotime($expiresAt) <= time()){
			$this->conn->rollback();
			return json_encode(['status' => 'failed', 'msg' => 'This SMS code is unavailable or expired. Request a new code.']);
		}
		if($otpStatus === 'verified'){
			if(!password_verify($code, $otpHash)){
				$this->conn->rollback();
				return json_encode(['status' => 'failed', 'msg' => 'The verification code is incorrect.']);
			}
			if(!$this->conn->commit()){
				$this->conn->rollback();
				error_log('Payment OTP idempotent verification commit failed: ' . $this->conn->error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not verify this code. Please try again.']);
			}
			return json_encode(['status' => 'success', 'msg' => 'Phone number verified.']);
		}
		if(!password_verify($code, $otpHash)){
			$nextAttempts = (int) $attempts + 1;
			$newStatus = $nextAttempts >= 5 ? 'locked' : 'sent';
			$attemptUpdate = $this->conn->prepare(
				"UPDATE booking_payment_otps SET attempts = ?, status = ? WHERE id = ? AND status = 'sent'"
			);
			if(!$attemptUpdate){
				$this->conn->rollback();
				error_log('Payment OTP attempts update prepare failed: ' . $this->conn->error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not verify this code. Please try again.']);
			}
			$attemptUpdate->bind_param('isi', $nextAttempts, $newStatus, $otpId);
			if(!$attemptUpdate->execute()){
				$error = $attemptUpdate->error;
				$attemptUpdate->close();
				$this->conn->rollback();
				error_log('Payment OTP attempts update failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not verify this code. Please try again.']);
			}
			$attemptUpdate->close();
			if(!$this->conn->commit()){
				$this->conn->rollback();
				error_log('Payment OTP failed-attempt commit failed: ' . $this->conn->error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not verify this code. Please try again.']);
			}
			return json_encode([
				'status' => 'failed',
				'msg' => $newStatus === 'locked' ? 'Too many incorrect codes. Request a new SMS code.' : 'The verification code is incorrect.',
			]);
		}
		$verified = $this->conn->prepare(
			"UPDATE booking_payment_otps SET status = 'verified', verified_at = NOW()
			 WHERE id = ? AND status = 'sent' AND expires_at > NOW()"
		);
		if(!$verified){
			$this->conn->rollback();
			error_log('Payment OTP verification update prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not verify this code. Please try again.']);
		}
		$verified->bind_param('i', $otpId);
		if(!$verified->execute() || $verified->affected_rows !== 1){
			$error = $verified->error;
			$verified->close();
			$this->conn->rollback();
			error_log('Payment OTP verification update failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'This SMS code expired. Request a new code.']);
		}
		$verified->close();
		if(!$this->conn->commit()){
			$this->conn->rollback();
			error_log('Payment OTP verification commit failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not verify this code. Please try again.']);
		}
		return json_encode(['status' => 'success', 'msg' => 'Phone number verified.']);
	}
	function create_stripe_checkout(){
		$token = isset($_POST['token']) && is_string($_POST['token']) ? trim($_POST['token']) : '';
		return json_encode(StripeTestCheckout::create($this->conn, $token));
	}
	function confirm_stripe_checkout(){
		$sessionId = isset($_POST['session_id']) && is_string($_POST['session_id']) ? trim($_POST['session_id']) : '';
		return json_encode(StripeTestCheckout::confirmReturn($this->conn, $sessionId));
	}
	function submit_manual_online_payment(){
		return json_encode([
			'status' => 'failed',
			'msg' => 'Online payments are unavailable. Please pay at the shop cashier.',
		]);
	}
	function verify_online_payment_submission(){
		if(!$this->is_authorized_booking_staff()){
			http_response_code(403);
			return json_encode(['status' => 'failed', 'msg' => 'Only authorized staff can verify online transfers.']);
		}
		$id = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : false;
		if($id === false || $id < 1){
			return json_encode(['status' => 'failed', 'msg' => 'Invalid payment submission.']);
		}
		$userId = (int) $_SESSION['userdata']['id'];
		$this->conn->begin_transaction();
		$payment = $this->conn->prepare(
			"SELECT p.booking_id, p.amount_cents, p.payment_provider, p.status,
			        b.payment_status, b.status
			 FROM booking_online_payments p
			 INNER JOIN booking_list b ON b.id = p.booking_id
			 WHERE p.id = ? AND b.is_archived = 0 LIMIT 1 FOR UPDATE"
		);
		if(!$payment){
			$this->conn->rollback();
			error_log('Online payment verification lookup prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not load the payment submission.']);
		}
		$payment->bind_param('i', $id);
		if(!$payment->execute()){
			$paymentError = $payment->error;
			$payment->close();
			$this->conn->rollback();
			error_log('Online payment verification lookup failed: ' . $paymentError);
			return json_encode(['status' => 'failed', 'msg' => 'Could not load the payment submission.']);
		}
		$payment->bind_result($bookingId, $amountCents, $paymentProvider, $paymentStatus, $bookingPaymentStatus, $bookingStatus);
		$found = $payment->fetch();
		$payment->close();
		if(
			!$found ||
			$paymentStatus !== 'pending_verification' ||
			$bookingPaymentStatus !== 'unpaid' ||
			(int) $bookingStatus === 5 ||
			!in_array($paymentProvider, ['gcash', 'maya'], true)
		){
			$this->conn->rollback();
			return json_encode(['status' => 'failed', 'msg' => 'This transfer is no longer awaiting verification.']);
		}
		$amount = (float) $amountCents / 100;
		$markPayment = $this->conn->prepare(
			"UPDATE booking_online_payments
			 SET status = 'paid', paid_at = NOW(), verified_by = ?, verified_at = NOW()
			 WHERE id = ? AND status = 'pending_verification'"
		);
		if(!$markPayment){
			$this->conn->rollback();
			error_log('Online payment verification update prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not verify this transfer.']);
		}
		$markPayment->bind_param('ii', $userId, $id);
		if(!$markPayment->execute() || $markPayment->affected_rows !== 1){
			$error = $markPayment->error;
			$markPayment->close();
			$this->conn->rollback();
			error_log('Online payment verification update failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'This transfer could not be verified.']);
		}
		$markPayment->close();
		$markBooking = $this->conn->prepare(
			"UPDATE booking_list
			 SET payment_status = 'paid', payment_method = ?,
			     status = IF(status = 0, 1, status), payment_paid_at = NOW(),
			     payment_received_by = ?, cash_balance_amount = ?
			 WHERE id = ? AND payment_status = 'unpaid' AND status <> 5 AND is_archived = 0"
		);
		if(!$markBooking){
			$this->conn->rollback();
			error_log('Verified booking payment update prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not update the booking payment.']);
		}
		$markBooking->bind_param('sidi', $paymentProvider, $userId, $amount, $bookingId);
		if(!$markBooking->execute() || $markBooking->affected_rows !== 1){
			$error = $markBooking->error;
			$markBooking->close();
			$this->conn->rollback();
			error_log('Verified booking payment update failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'The booking payment could not be updated.']);
		}
		$markBooking->close();
		if(!$this->conn->commit()){
			$this->conn->rollback();
			error_log('Online payment verification commit failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not finish verifying this transfer.']);
		}
		return json_encode(['status' => 'success', 'msg' => 'Transfer verified and booking payment recorded.']);
	}
	function reject_online_payment_submission(){
		if(!$this->is_authorized_booking_staff()){
			http_response_code(403);
			return json_encode(['status' => 'failed', 'msg' => 'Only authorized staff can review online transfers.']);
		}
		$id = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : false;
		if($id === false || $id < 1){
			return json_encode(['status' => 'failed', 'msg' => 'Invalid payment submission.']);
		}
		$userId = (int) $_SESSION['userdata']['id'];
		$update = $this->conn->prepare(
			"UPDATE booking_online_payments
			 SET status = 'rejected', rejected_by = ?, rejected_at = NOW()
			 WHERE id = ? AND status = 'pending_verification'"
		);
		if(!$update){
			error_log('Online payment rejection prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not reject this transfer.']);
		}
		$update->bind_param('ii', $userId, $id);
		if(!$update->execute()){
			error_log('Online payment rejection failed: ' . $update->error);
			$update->close();
			return json_encode(['status' => 'failed', 'msg' => 'Could not reject this transfer.']);
		}
		$updated = $update->affected_rows;
		$update->close();
		if($updated !== 1){
			return json_encode(['status' => 'failed', 'msg' => 'This transfer is no longer awaiting review.']);
		}
		return json_encode(['status' => 'success', 'msg' => 'Transfer reference rejected.']);
	}
	function verify_reservation_fee_payment(){
		if(!$this->is_authorized_booking_staff()){
			http_response_code(403);
			return json_encode(['status' => 'failed', 'msg' => 'Only authorized staff can verify reservation payments.']);
		}
		$id = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : false;
		if($id === false || $id < 1){
			return json_encode(['status' => 'failed', 'msg' => 'Invalid booking.']);
		}
		$userId = (int) $_SESSION['userdata']['id'];
		$update = $this->conn->prepare(
			"UPDATE booking_list
			 SET reservation_fee_payment_status = 'paid',
			     reservation_fee_verified_at = NOW(),
			     reservation_fee_verified_by = ?
			 WHERE id = ? AND status <> 5 AND payment_status = 'unpaid' AND is_archived = 0
			   AND reservation_fee_payment_status = 'pending_verification'
			   AND reservation_fee_transaction_ref IS NOT NULL"
		);
		if(!$update){
			error_log('Reservation payment verification prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not verify the reservation payment.']);
		}
		$update->bind_param('ii', $userId, $id);
		if(!$update->execute()){
			error_log('Reservation payment verification failed: ' . $update->error);
			$update->close();
			return json_encode(['status' => 'failed', 'msg' => 'Could not verify the reservation payment.']);
		}
		$updated = $update->affected_rows;
		$update->close();
		if($updated !== 1){
			return json_encode(['status' => 'failed', 'msg' => 'No pending reservation transfer was found for this booking.']);
		}
		return json_encode(['status' => 'success', 'msg' => 'Reservation fee verified and recorded.']);
	}
	function reject_reservation_fee_payment(){
		if(!$this->is_authorized_booking_staff()){
			http_response_code(403);
			return json_encode(['status' => 'failed', 'msg' => 'Only authorized staff can review reservation payments.']);
		}
		$id = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : false;
		if($id === false || $id < 1){
			return json_encode(['status' => 'failed', 'msg' => 'Invalid booking.']);
		}
		$update = $this->conn->prepare(
			"UPDATE booking_list
			 SET reservation_fee_payment_status = 'rejected',
			     reservation_fee_rejected_at = NOW(),
			     reservation_fee_rejected_by = ?
			 WHERE id = ? AND reservation_fee_payment_status = 'pending_verification' AND is_archived = 0"
		);
		if(!$update){
			error_log('Reservation payment rejection prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not reject the submitted transfer.']);
		}
		$userId = (int) $_SESSION['userdata']['id'];
		$update->bind_param('ii', $userId, $id);
		if(!$update->execute()){
			error_log('Reservation payment rejection failed: ' . $update->error);
			$update->close();
			return json_encode(['status' => 'failed', 'msg' => 'Could not reject the submitted transfer.']);
		}
		$updated = $update->affected_rows;
		$update->close();
		if($updated !== 1){
			return json_encode(['status' => 'failed', 'msg' => 'No pending reservation transfer was found for this booking.']);
		}
		return json_encode(['status' => 'success', 'msg' => 'Transfer rejected. Contact the shop to resolve the payment.']);
	}
	function refund_reservation_fee(){
		if(!$this->is_authorized_booking_staff()){
			http_response_code(403);
			return json_encode(['status' => 'failed', 'msg' => 'Only authorized staff can record reservation refunds.']);
		}
		$id = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : false;
		$reference = isset($_POST['refund_reference']) && is_string($_POST['refund_reference'])
			? trim($_POST['refund_reference'])
			: '';
		if($id === false || $id < 1 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 -]{4,99}$/', $reference)){
			return json_encode(['status' => 'failed', 'msg' => 'Enter a valid booking and the completed refund transaction reference.']);
		}
		$userId = (int) $_SESSION['userdata']['id'];
		$update = $this->conn->prepare(
			"UPDATE booking_list
			 SET reservation_fee_payment_status = 'refunded',
			     reservation_fee_refunded_at = NOW(),
			     reservation_fee_refund_ref = ?,
			     reservation_fee_refunded_by = ?
			 WHERE id = ? AND status = 5 AND payment_status = 'unpaid'
			   AND reservation_fee_payment_status = 'paid' AND is_archived = 0"
		);
		if(!$update){
			error_log('Reservation refund prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not record the reservation refund.']);
		}
		$update->bind_param('sii', $reference, $userId, $id);
		if(!$update->execute()){
			error_log('Reservation refund failed: ' . $update->error);
			$update->close();
			return json_encode(['status' => 'failed', 'msg' => 'Could not record the reservation refund.']);
		}
		$updated = $update->affected_rows;
		$update->close();
		if($updated !== 1){
			return json_encode(['status' => 'failed', 'msg' => 'Only a verified reservation fee on a cancelled, otherwise-unpaid booking can be refunded.']);
		}
		return json_encode(['status' => 'success', 'msg' => 'Reservation refund recorded.']);
	}
	function mark_cash_payment_received(){
		$userId = isset($_SESSION['userdata']['id']) ? filter_var($_SESSION['userdata']['id'], FILTER_VALIDATE_INT) : false;
		$userType = isset($_SESSION['userdata']['type']) ? (int) $_SESSION['userdata']['type'] : 0;
		if(
			!isset($_SESSION['userdata']['login_type']) ||
			(int) $_SESSION['userdata']['login_type'] !== 1 ||
			!in_array($userType, [1, 2], true) ||
			$userId === false ||
			$userId < 1
		){
			http_response_code(403);
			return json_encode(['status' => 'failed', 'msg' => 'Only an administrator or staff member can record a cash payment.']);
		}
		$id = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : false;
		if($id === false || $id < 1){
			return json_encode(['status' => 'failed', 'msg' => 'Invalid booking.']);
		}
		$stripeCheckout = StripeTestCheckout::expireActiveForCash($this->conn, $id);
		if($stripeCheckout['status'] !== 'success'){
			return json_encode($stripeCheckout);
		}
		try{
			$this->conn->begin_transaction();
			$check = $this->conn->prepare(
				"SELECT total_amount, reservation_fee, reservation_fee_payment_status
				 FROM booking_list
				 WHERE id = ? AND payment_status = 'unpaid' AND status <> 5 AND is_archived = 0
				 LIMIT 1 FOR UPDATE"
			);
			if(!$check){
				$this->conn->rollback();
				error_log('Cash balance lookup prepare failed: ' . $this->conn->error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not load the cash payment amount.']);
			}
			$check->bind_param('i', $id);
			if(!$check->execute()){
				$error = $check->error;
				$check->close();
				$this->conn->rollback();
				error_log('Cash balance lookup failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not load the cash payment amount.']);
			}
			$check->bind_result($totalAmount, $reservationFee, $reservationFeeStatus);
			$found = $check->fetch();
			$check->close();
			if(!$found){
				$this->conn->rollback();
				return json_encode(['status' => 'failed', 'msg' => 'Booking not found or already fully paid.']);
			}

			$activeStripe = $this->conn->prepare(
				"SELECT id FROM booking_online_payments
				 WHERE booking_id = ? AND payment_provider = 'stripe'
				   AND status IN ('creating', 'pending')
				 LIMIT 1 FOR UPDATE"
			);
			if(!$activeStripe){
				$this->conn->rollback();
				error_log('Cash payment Stripe status lookup prepare failed: ' . $this->conn->error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not verify the Stripe checkout status.']);
			}
			$activeStripe->bind_param('i', $id);
			if(!$activeStripe->execute()){
				$error = $activeStripe->error;
				$activeStripe->close();
				$this->conn->rollback();
				error_log('Cash payment Stripe status lookup failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not verify the Stripe checkout status.']);
			}
			$activeStripe->bind_result($activeStripePaymentId);
			$hasActiveStripe = $activeStripe->fetch();
			$activeStripe->close();
			if($hasActiveStripe){
				$this->conn->rollback();
				return json_encode(['status' => 'failed', 'msg' => 'A Stripe checkout is still being created or active. Refresh the booking and retry recording cash.']);
			}

			$cashBalance = (float) $totalAmount - ($reservationFeeStatus === 'paid' ? (float) $reservationFee : 0.0);
			$update = $this->conn->prepare(
				"UPDATE booking_list
				 SET payment_status = 'paid', payment_method = 'cash',
				     status = IF(status = 0, 1, status), payment_paid_at = NOW(),
				     payment_received_by = ?, cash_balance_amount = ?
				 WHERE id = ? AND payment_status = 'unpaid' AND is_archived = 0"
			);
			if(!$update){
				$this->conn->rollback();
				error_log('Cash payment update prepare failed: ' . $this->conn->error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not record the cash payment.']);
			}
			$update->bind_param('idi', $userId, $cashBalance, $id);
			if(!$update->execute()){
				$error = $update->error;
				$update->close();
				$this->conn->rollback();
				error_log('Cash payment update failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not record the cash payment.']);
			}
			$updated = $update->affected_rows;
			$update->close();
			if($updated !== 1){
				$this->conn->rollback();
				return json_encode(['status' => 'failed', 'msg' => 'This booking was already paid or could not be found.']);
			}
			if(!$this->conn->commit()){
				$this->conn->rollback();
				error_log('Cash payment transaction commit failed: ' . $this->conn->error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not record the cash payment.']);
			}
			return json_encode(['status' => 'success', 'msg' => 'Cash payment recorded.']);
		}catch(mysqli_sql_exception $error){
			$this->conn->rollback();
			error_log('Cash payment transaction failed: ' . $error->getMessage());
			return json_encode(['status' => 'failed', 'msg' => 'Could not record the cash payment. Please retry.']);
		}
	}
	function update_status(){
		$userType = isset($_SESSION['userdata']['type']) ? (int) $_SESSION['userdata']['type'] : 0;
		if(
			!isset($_SESSION['userdata']['login_type']) ||
			(int) $_SESSION['userdata']['login_type'] !== 1 ||
			!in_array($userType, [1, 2], true)
		){
			http_response_code(403);
			return json_encode(['status' => 'failed', 'msg' => 'Only an administrator or staff member can update booking status.']);
		}
		$id = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : false;
		$statusValue = isset($_POST['status']) ? trim((string) $_POST['status']) : '';
		if($id === false || $id < 1){
			return json_encode(['status' => 'failed', 'msg' => 'Invalid booking status.']);
		}
		if($statusValue === 'paid'){
			return $this->mark_cash_payment_received();
		}
		$status = filter_var($statusValue, FILTER_VALIDATE_INT);
		if($status === false || !in_array($status, [0, 1, 4, 5], true)){
			return json_encode(['status' => 'failed', 'msg' => 'Invalid booking status.']);
		}
		$check = $this->conn->prepare("SELECT schedule, payment_status, status, is_archived FROM booking_list WHERE id = ? LIMIT 1");
		if(!$check){
			error_log('Booking status lookup prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Booking Status has failed to update.']);
		}
		$check->bind_param('i', $id);
		if(!$check->execute()){
			$error = $check->error;
			$check->close();
			error_log('Booking status lookup failed: ' . $error);
			return json_encode(['status' => 'failed', 'msg' => 'Booking Status has failed to update.']);
		}
		$check->bind_result($schedule, $paymentStatus, $currentStatus, $isArchived);
		$found = $check->fetch();
		$check->close();
		if(!$found){
			return json_encode(['status' => 'failed', 'msg' => 'Booking was not found.']);
		}
		if((int) $isArchived === 1){
			return json_encode(['status' => 'failed', 'msg' => 'Restore this booking before updating its status.']);
		}
		if($status === 4 && !in_array((int) $currentStatus, [2, 3], true)){
			return json_encode(['status' => 'failed', 'msg' => 'A booking can only be marked Completed after it has automatically moved to In Progress.']);
		}
		if($status === 4 && $paymentStatus === 'unpaid'){
			return json_encode(['status' => 'failed', 'msg' => 'Record the cash payment before starting or completing the service.']);
		}
		$slotLock = $this->acquire_booking_slot_lock($schedule);
		if($slotLock === false){
			return json_encode(['status' => 'failed', 'msg' => 'The appointment slot is being updated. Please try again.']);
		}
		if($status !== 5){
			$capacityCheck = $this->conn->prepare(
				"SELECT COUNT(*) FROM booking_list WHERE schedule = ? AND status <> 5 AND id <> ? AND is_archived = 0"
			);
			if(!$capacityCheck){
				$this->release_booking_slot_lock($slotLock);
				error_log('Booking status capacity check prepare failed: ' . $this->conn->error);
				return json_encode(['status' => 'failed', 'msg' => 'Appointment availability could not be checked.']);
			}
			$capacityCheck->bind_param('si', $schedule, $id);
			if(!$capacityCheck->execute()){
				$error = $capacityCheck->error;
				$capacityCheck->close();
				$this->release_booking_slot_lock($slotLock);
				error_log('Booking status capacity check failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'Appointment availability could not be checked.']);
			}
			$capacityCheck->bind_result($activeBookings);
			$capacityCheck->fetch();
			$capacityCheck->close();
			if((int) $activeBookings >= BOOKING_SLOT_CAPACITY){
				$this->release_booking_slot_lock($slotLock);
				return json_encode(['status' => 'failed', 'msg' => 'This appointment slot is already at capacity.']);
			}
		}
		$update = $this->conn->prepare("UPDATE booking_list SET status = ? WHERE id = ?");
		if(!$update){
			$this->release_booking_slot_lock($slotLock);
			error_log('Booking status update prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Booking Status has failed to update.']);
		}
		$update->bind_param('ii', $status, $id);
		if($update->execute()){
			$resp['status'] = 'success';
			$resp['msg'] = "Booking Status has been updated successfully";
		}else{
			$resp['status'] = 'failed';
			$resp['msg'] = "Booking Status has failed to update";
		}
		$update->close();
		$this->release_booking_slot_lock($slotLock);
		return json_encode($resp);
	}
	function advance_due_bookings(){
		$userType = isset($_SESSION['userdata']['type']) ? (int) $_SESSION['userdata']['type'] : 0;
		if(
			!isset($_SESSION['userdata']['login_type']) ||
			(int) $_SESSION['userdata']['login_type'] !== 1 ||
			!in_array($userType, [1, 2], true)
		){
			http_response_code(403);
			return json_encode(['status' => 'failed', 'msg' => 'Only an administrator or staff member can update booking status.']);
		}
		if(!$this->conn->begin_transaction()){
			error_log('Automatic booking progression transaction could not start: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not automatically advance paid bookings.']);
		}
		$confirm = $this->conn->query(
			"UPDATE booking_list SET status = 1
			 WHERE status = 0 AND payment_status = 'paid' AND is_archived = 0"
		);
		if(!$confirm){
			$this->conn->rollback();
			error_log('Automatic paid booking confirmation failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not automatically confirm paid bookings.']);
		}
		$updated = $this->conn->affected_rows;

		$legacyActive = $this->conn->query(
			"UPDATE booking_list
			 SET status = 3, service_started_at = COALESCE(service_started_at, NOW())
			 WHERE status = 2 AND payment_status = 'paid' AND is_archived = 0"
		);
		if(!$legacyActive){
			$this->conn->rollback();
			error_log('Legacy active booking migration failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not update active paid bookings.']);
		}
		$updated += $this->conn->affected_rows;

		$startTimes = $this->conn->query(
			"UPDATE booking_list SET service_started_at = NOW()
			 WHERE status = 3 AND service_started_at IS NULL AND is_archived = 0"
		);
		if(!$startTimes){
			$this->conn->rollback();
			error_log('Automatic service start time update failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not track active service times.']);
		}
		$updated += $this->conn->affected_rows;

		$complete = $this->conn->query(
			"UPDATE booking_list b
			 INNER JOIN (
				SELECT booking_id, SUM(duration_minutes) AS total_minutes
				FROM booking_services
				GROUP BY booking_id
			 ) durations ON durations.booking_id = b.id
			 SET b.status = 4
			 WHERE b.status = 3
			   AND b.payment_status = 'paid'
			   AND b.is_archived = 0
			   AND b.service_started_at IS NOT NULL
			   AND TIMESTAMPADD(MINUTE, durations.total_minutes, b.service_started_at) <= NOW()"
		);
		if(!$complete){
			$this->conn->rollback();
			error_log('Automatic service completion failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not automatically complete finished services.']);
		}
		$updated += $this->conn->affected_rows;

		$active = $this->conn->query(
			"SELECT id FROM booking_list WHERE status IN (2, 3) AND is_archived = 0 LIMIT 1 FOR UPDATE"
		);
		if(!$active){
			$this->conn->rollback();
			error_log('Automatic active booking lookup failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not check the current booking in service.']);
		}
		if($active->num_rows === 0){
			$next = $this->conn->query(
				"SELECT id FROM booking_list
				 WHERE status = 1 AND payment_status = 'paid' AND schedule <= NOW() AND is_archived = 0
				 ORDER BY schedule ASC, id ASC
				 LIMIT 1 FOR UPDATE"
			);
			if(!$next){
				$this->conn->rollback();
				error_log('Automatic next booking lookup failed: ' . $this->conn->error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not find the next paid booking.']);
			}
			if($next->num_rows > 0){
				$nextBooking = $next->fetch_assoc();
				$nextBookingId = (int) $nextBooking['id'];
				$start = $this->conn->prepare(
					"UPDATE booking_list SET status = 3, service_started_at = NOW()
					 WHERE id = ? AND status = 1 AND payment_status = 'paid' AND is_archived = 0"
				);
				if(!$start){
					$next->free();
					$this->conn->rollback();
					error_log('Automatic next booking update prepare failed: ' . $this->conn->error);
					return json_encode(['status' => 'failed', 'msg' => 'Could not automatically start the next paid booking.']);
				}
				$start->bind_param('i', $nextBookingId);
				if(!$start->execute()){
					$startError = $start->error;
					$start->close();
					$next->free();
					$this->conn->rollback();
					error_log('Automatic next booking update failed: ' . $startError);
					return json_encode(['status' => 'failed', 'msg' => 'Could not automatically start the next paid booking.']);
				}
				$updated += $start->affected_rows;
				$start->close();
			}
			$next->free();
		}
		$active->free();
		if(!$this->conn->commit()){
			$this->conn->rollback();
			error_log('Automatic booking progression commit failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not save automatic booking progress.']);
		}
		return json_encode(['status' => 'success', 'updated' => $updated]);
	}
	function archive_booking(){
		return $this->archive_bookings();
	}
	function archive_bookings(){
		if(!$this->is_authorized_booking_staff()){
			http_response_code(403);
			return json_encode(['status' => 'failed', 'msg' => 'Only authorized staff can archive bookings.']);
		}
		$submittedIds = isset($_POST['booking_ids']) && is_array($_POST['booking_ids']) ? $_POST['booking_ids'] : [];
		if(isset($_POST['id'])){
			$submittedIds[] = $_POST['id'];
		}
		$bookingIds = [];
		foreach($submittedIds as $id){
			$validatedId = filter_var($id, FILTER_VALIDATE_INT);
			if($validatedId === false || $validatedId <= 0){
				return json_encode(['status' => 'failed', 'msg' => 'The selected booking list is invalid.']);
			}
			$bookingIds[] = (int) $validatedId;
		}
		$bookingIds = array_values(array_unique($bookingIds));
		if(empty($bookingIds)){
			return json_encode(['status' => 'failed', 'msg' => 'Select at least one valid booking to archive.']);
		}

		if(!$this->conn->begin_transaction()){
			error_log('Booking archive transaction failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not archive the selected bookings.']);
		}
		$archive = $this->conn->prepare("UPDATE booking_list SET is_archived = 1 WHERE id = ? AND is_archived = 0");
		if(!$archive){
			$this->conn->rollback();
			error_log('Booking archive prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not archive the selected bookings.']);
		}

		$archivedCount = 0;
		foreach($bookingIds as $bookingId){
			$bookingId = (int) $bookingId;
			if(!$archive->bind_param('i', $bookingId) || !$archive->execute()){
				$error = $archive->error;
				$archive->close();
				$this->conn->rollback();
				error_log('Booking archive failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not archive the selected bookings.']);
			}
			$archivedCount += $archive->affected_rows;
		}
		$archive->close();
		if(!$this->conn->commit()){
			$this->conn->rollback();
			error_log('Booking archive commit failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not archive the selected bookings.']);
		}

		$this->settings->set_flashdata('success', $archivedCount . ' booking(s) moved to Archived Bookings.');
		return json_encode(['status' => 'success', 'archived' => $archivedCount]);
	}
	function restore_bookings(){
		if(!$this->is_authorized_booking_staff()){
			http_response_code(403);
			return json_encode(['status' => 'failed', 'msg' => 'Only authorized staff can restore bookings.']);
		}
		$submittedIds = isset($_POST['booking_ids']) && is_array($_POST['booking_ids']) ? $_POST['booking_ids'] : [];
		if(isset($_POST['id'])){
			$submittedIds[] = $_POST['id'];
		}
		$bookingIds = [];
		foreach($submittedIds as $id){
			$validatedId = filter_var($id, FILTER_VALIDATE_INT);
			if($validatedId === false || $validatedId <= 0){
				return json_encode(['status' => 'failed', 'msg' => 'The selected booking list is invalid.']);
			}
			$bookingIds[] = (int) $validatedId;
		}
		$bookingIds = array_values(array_unique($bookingIds));
		if(empty($bookingIds)){
			return json_encode(['status' => 'failed', 'msg' => 'Select at least one booking to restore.']);
		}
		if(!$this->conn->begin_transaction()){
			error_log('Booking restore transaction failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not restore the selected bookings.']);
		}
		$lookup = $this->conn->prepare(
			"SELECT schedule, status FROM booking_list WHERE id = ? AND is_archived = 1 FOR UPDATE"
		);
		if(!$lookup){
			$this->conn->rollback();
			error_log('Booking restore lookup prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not load the archived bookings.']);
		}
		$bookings = [];
		foreach($bookingIds as $bookingId){
			$lookup->bind_param('i', $bookingId);
			if(!$lookup->execute()){
				$error = $lookup->error;
				$lookup->close();
				$this->conn->rollback();
				error_log('Booking restore lookup failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not load the archived bookings.']);
			}
			$lookup->bind_result($schedule, $status);
			if(!$lookup->fetch()){
				$lookup->free_result();
				$lookup->close();
				$this->conn->rollback();
				return json_encode(['status' => 'failed', 'msg' => 'One or more selected bookings are not archived. Refresh the list and try again.']);
			}
			$bookings[] = ['id' => $bookingId, 'schedule' => $schedule, 'status' => (int) $status];
			$lookup->free_result();
		}
		$lookup->close();

		$schedules = array_values(array_unique(array_column($bookings, 'schedule')));
		sort($schedules);
		$slotLocks = [];
		foreach($schedules as $schedule){
			$slotLock = $this->acquire_booking_slot_lock($schedule);
			if($slotLock === false){
				$this->release_booking_slot_locks($slotLocks);
				$this->conn->rollback();
				return json_encode(['status' => 'failed', 'msg' => 'An appointment slot is being updated. Please try again.']);
			}
			$slotLocks[] = $slotLock;
		}

		$restoreCounts = [];
		foreach($bookings as $booking){
			if($booking['status'] !== 5){
				$key = $booking['schedule'];
				$restoreCounts[$key] = isset($restoreCounts[$key]) ? $restoreCounts[$key] + 1 : 1;
			}
		}
		$restoredActiveCount = 0;
		foreach($bookings as $booking){
			if(in_array($booking['status'], [2, 3], true)){
				$restoredActiveCount++;
			}
		}
		$activeLookup = $this->conn->query(
			"SELECT COUNT(*) AS total FROM booking_list WHERE status IN (2, 3) AND is_archived = 0"
		);
		if(!$activeLookup){
			$this->release_booking_slot_locks($slotLocks);
			$this->conn->rollback();
			error_log('Booking restore active service lookup failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not check the current booking in service.']);
		}
		$activeCount = (int) $activeLookup->fetch_assoc()['total'];
		$activeLookup->free();
		if($activeCount + $restoredActiveCount > 1){
			$this->release_booking_slot_locks($slotLocks);
			$this->conn->rollback();
			return json_encode(['status' => 'failed', 'msg' => 'An in-progress booking cannot be restored while another booking is in service.']);
		}
		$capacityCheck = $this->conn->prepare(
			"SELECT COUNT(*) FROM booking_list WHERE schedule = ? AND status <> 5 AND is_archived = 0"
		);
		if(!$capacityCheck){
			$this->release_booking_slot_locks($slotLocks);
			$this->conn->rollback();
			error_log('Booking restore capacity prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Appointment availability could not be checked.']);
		}
		foreach($restoreCounts as $schedule => $restoreCount){
			$capacityCheck->bind_param('s', $schedule);
			if(!$capacityCheck->execute()){
				$error = $capacityCheck->error;
				$capacityCheck->close();
				$this->release_booking_slot_locks($slotLocks);
				$this->conn->rollback();
				error_log('Booking restore capacity check failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'Appointment availability could not be checked.']);
			}
			$capacityCheck->bind_result($activeBookings);
			$capacityCheck->fetch();
			$capacityCheck->free_result();
			if((int) $activeBookings + $restoreCount > BOOKING_SLOT_CAPACITY){
				$capacityCheck->close();
				$this->release_booking_slot_locks($slotLocks);
				$this->conn->rollback();
				return json_encode(['status' => 'failed', 'msg' => 'A selected booking cannot be restored because its appointment slot is already full.']);
			}
		}
		$capacityCheck->close();
		$restore = $this->conn->prepare("UPDATE booking_list SET is_archived = 0 WHERE id = ? AND is_archived = 1");
		if(!$restore){
			$this->release_booking_slot_locks($slotLocks);
			$this->conn->rollback();
			error_log('Booking restore prepare failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not restore the selected bookings.']);
		}
		foreach($bookingIds as $bookingId){
			$restore->bind_param('i', $bookingId);
			if(!$restore->execute() || $restore->affected_rows !== 1){
				$error = $restore->error;
				$restore->close();
				$this->release_booking_slot_locks($slotLocks);
				$this->conn->rollback();
				error_log('Booking restore failed: ' . $error);
				return json_encode(['status' => 'failed', 'msg' => 'Could not restore the selected bookings.']);
			}
		}
		$restore->close();
		if(!$this->conn->commit()){
			$this->conn->rollback();
			$this->release_booking_slot_locks($slotLocks);
			error_log('Booking restore commit failed: ' . $this->conn->error);
			return json_encode(['status' => 'failed', 'msg' => 'Could not restore the selected bookings.']);
		}
		$this->release_booking_slot_locks($slotLocks);
		$this->settings->set_flashdata('success', count($bookingIds) . ' booking(s) restored.');
		return json_encode(['status' => 'success', 'restored' => count($bookingIds)]);
	}
	function delete_img(){
		extract($_POST);
		if(is_file($path)){
			if(unlink($path)){
				$resp['status'] = 'success';
			}else{
				$resp['status'] = 'failed';
				$resp['error'] = 'failed to delete '.$path;
			}
		}else{
			$resp['status'] = 'failed';
			$resp['error'] = 'Unkown '.$path.' path';
		}
		return json_encode($resp);
	}
}

$Master = new Master();
$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);
$sysset = new SystemSettings();
switch ($action) {
	case 'save_vehicle':
		echo $Master->save_vehicle();
	break;
	case 'delete_vehicle':
		echo $Master->delete_vehicle();
	break;
	case 'save_service':
		echo $Master->save_service();
	break;
	case 'delete_service':
		echo $Master->delete_service();
	break;
	case 'save_price':
		echo $Master->save_price();
	break;
	case 'update_status':
		echo $Master->update_status();
	break;
	case 'advance_due_bookings':
		echo $Master->advance_due_bookings();
	break;
	case 'get_vehicle_service':
		echo $Master->get_vehicle_service();
	break;
	case 'get_available_slots':
	echo $Master->get_available_slots();
	break;
	case 'save_booking':
		echo $Master->save_booking();
	break;
	case 'create_stripe_checkout':
		echo $Master->create_stripe_checkout();
	break;
	case 'confirm_stripe_checkout':
		echo $Master->confirm_stripe_checkout();
	break;
	case 'submit_manual_online_payment':
		echo $Master->submit_manual_online_payment();
	break;
	case 'verify_online_payment_submission':
		echo $Master->verify_online_payment_submission();
	break;
	case 'reject_online_payment_submission':
		echo $Master->reject_online_payment_submission();
	break;
	case 'mark_cash_payment_received':
		echo $Master->mark_cash_payment_received();
	break;
	case 'verify_reservation_fee_payment':
		echo $Master->verify_reservation_fee_payment();
	break;
	case 'reject_reservation_fee_payment':
		echo $Master->reject_reservation_fee_payment();
	break;
	case 'refund_reservation_fee':
		echo $Master->refund_reservation_fee();
	break;
	case 'archive_booking':
		echo $Master->archive_booking();
	break;
	case 'archive_bookings':
		echo $Master->archive_bookings();
	break;
	case 'restore_bookings':
		echo $Master->restore_bookings();
	break;
	case 'delete_img':
		echo $Master->delete_img();
	break;
	default:
		// echo $sysset->index();
		break;
}