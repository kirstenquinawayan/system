<?php
require_once __DIR__ . '/initialize.php';
require_once __DIR__ . '/classes/DBConnection.php';
require_once __DIR__ . '/classes/ReceiptPdf.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$receiptType = isset($_GET['type']) && is_string($_GET['type']) ? $_GET['type'] : 'confirmation';
$isCashReceipt = $receiptType === 'cash';
$isReservationReceipt = $receiptType === 'reservation';
if (!in_array($receiptType, ['cash', 'reservation', 'confirmation'], true)) {
    http_response_code(400);
    exit('Invalid receipt type.');
}
if ($isCashReceipt && (!isset($_SESSION['userdata']['login_type']) || (int) $_SESSION['userdata']['login_type'] !== 1)) {
    http_response_code(403);
    exit('Administrator or staff access is required to view a payment receipt.');
}

$db = new DBConnection();
$systemInfo = [];
$infoResult = $db->conn->query(
    "SELECT meta_field, meta_value FROM system_info WHERE meta_field IN ('name', 'short_name')"
);
if ($infoResult) {
    while ($info = $infoResult->fetch_assoc()) {
        $systemInfo[$info['meta_field']] = $info['meta_value'];
    }
}
$businessName = !empty($systemInfo['name']) ? $systemInfo['name'] : 'Car Wash';
$booking = null;
if ($isCashReceipt) {
    $bookingId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : false;
    if ($bookingId === false || $bookingId < 1) {
        http_response_code(400);
        exit('Invalid booking ID.');
    }
    $stmt = $db->conn->prepare(
        "SELECT b.code, b.client_name, b.contact, b.plate_number, b.schedule,
                b.total_amount, b.reservation_fee, b.payment_paid_at, b.payment_status, b.payment_method, v.name AS vehicle,
                b.cash_balance_amount, b.reservation_fee_payment_status, b.reservation_fee_payment_method,
                b.reservation_fee_transaction_ref, b.reservation_fee_verified_at
         FROM booking_list b
         INNER JOIN vehicle_list v ON v.id = b.vehicle_id
         WHERE b.id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        error_log('Payment receipt query prepare failed: ' . $db->conn->error);
        http_response_code(500);
        exit('Could not load the payment receipt.');
    }
    $stmt->bind_param('i', $bookingId);
} elseif ($isReservationReceipt) {
    $token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(400);
        exit('Invalid reservation-fee receipt token.');
    }
    $stmt = $db->conn->prepare(
        "SELECT b.code, b.client_name, b.contact, b.plate_number, b.schedule,
                b.total_amount, b.reservation_fee, b.payment_paid_at, b.payment_status, b.payment_method, v.name AS vehicle,
                b.cash_balance_amount, b.reservation_fee_payment_status, b.reservation_fee_payment_method,
                b.reservation_fee_transaction_ref, b.reservation_fee_verified_at
         FROM booking_list b
         INNER JOIN vehicle_list v ON v.id = b.vehicle_id
         WHERE b.receipt_pdf_token = ?
           AND b.reservation_fee_payment_status = 'paid'
         LIMIT 1"
    );
    if (!$stmt) {
        error_log('Reservation fee receipt query prepare failed: ' . $db->conn->error);
        http_response_code(500);
        exit('Could not load the reservation-fee receipt.');
    }
    $stmt->bind_param('s', $token);
} else {
    $token = isset($_GET['token']) && is_string($_GET['token']) ? $_GET['token'] : '';
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        http_response_code(400);
        exit('Invalid booking confirmation token.');
    }
    $stmt = $db->conn->prepare(
        "SELECT b.code, b.client_name, b.contact, b.plate_number, b.schedule,
                b.total_amount, b.reservation_fee, b.payment_paid_at, b.payment_status, b.payment_method, v.name AS vehicle,
                b.cash_balance_amount, b.reservation_fee_payment_status, b.reservation_fee_payment_method,
                b.reservation_fee_transaction_ref, b.reservation_fee_verified_at
         FROM booking_list b
         INNER JOIN vehicle_list v ON v.id = b.vehicle_id
         WHERE b.receipt_pdf_token = ?
         LIMIT 1"
    );
    if (!$stmt) {
        error_log('Booking confirmation query prepare failed: ' . $db->conn->error);
        http_response_code(500);
        exit('Could not load the booking confirmation.');
    }
    $stmt->bind_param('s', $token);
}

if (!$stmt->execute()) {
    error_log('Receipt booking query failed: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    exit('Could not load the receipt.');
}
$stmt->bind_result(
    $code,
    $clientName,
    $contact,
    $plateNumber,
    $schedule,
    $totalAmount,
    $reservationFee,
    $paymentPaidAt,
    $paymentStatus,
    $paymentMethod,
    $vehicle,
    $cashBalanceAmount,
    $reservationFeePaymentStatus,
    $reservationFeePaymentMethod,
    $reservationFeeTransactionReference,
    $reservationFeeVerifiedAt
);
if ($stmt->fetch()) {
    $booking = [
        'code' => $code,
        'client_name' => $clientName,
        'contact' => $contact,
        'plate_number' => $plateNumber,
        'schedule' => $schedule,
        'total_amount' => $totalAmount,
        'reservation_fee' => $reservationFee,
        'payment_paid_at' => $paymentPaidAt,
        'payment_status' => $paymentStatus,
        'payment_method' => $paymentMethod,
        'vehicle' => $vehicle,
        'cash_balance_amount' => (float) $cashBalanceAmount,
        'reservation_fee_payment_status' => $reservationFeePaymentStatus,
        'reservation_fee_payment_method' => $reservationFeePaymentMethod,
        'reservation_fee_transaction_ref' => $reservationFeeTransactionReference,
        'reservation_fee_verified_at' => $reservationFeeVerifiedAt,
    ];
}
$stmt->close();

if (
    !$booking ||
    ($isCashReceipt && $booking['payment_status'] !== 'paid') ||
    ($isReservationReceipt && $booking['reservation_fee_payment_status'] !== 'paid')
) {
    http_response_code(404);
    exit($isCashReceipt ? 'A paid booking was not found.' : ($isReservationReceipt ? 'A verified reservation-fee payment was not found.' : 'Booking confirmation was not found.'));
}

if ($isCashReceipt || $isReservationReceipt) {
    $serviceStmt = $db->conn->prepare(
        "SELECT s.name, bs.price
         FROM booking_services bs
         INNER JOIN service_list s ON s.id = bs.service_id
         WHERE bs.booking_id = ?
         ORDER BY s.name"
    );
    if (!$serviceStmt) {
        error_log('Cash receipt service query prepare failed: ' . $db->conn->error);
        http_response_code(500);
        exit('Could not load receipt services.');
    }
    $serviceStmt->bind_param('i', $bookingId);
} else {
    $serviceStmt = $db->conn->prepare(
        "SELECT s.name, bs.price
         FROM booking_services bs
         INNER JOIN service_list s ON s.id = bs.service_id
         INNER JOIN booking_list b ON b.id = bs.booking_id
         WHERE b.receipt_pdf_token = ?
         ORDER BY s.name"
    );
    if (!$serviceStmt) {
        error_log('Booking confirmation service query prepare failed: ' . $db->conn->error);
        http_response_code(500);
        exit('Could not load receipt services.');
    }
    $serviceStmt->bind_param('s', $token);
}
if (!$serviceStmt->execute()) {
    error_log('Receipt service query failed: ' . $serviceStmt->error);
    $serviceStmt->close();
    http_response_code(500);
    exit('Could not load receipt services.');
}
$serviceStmt->bind_result($serviceName, $servicePrice);
$services = [];
while ($serviceStmt->fetch()) {
    $services[] = [
        'name' => $serviceName,
        'formatted_price' => number_format((float) $servicePrice, 2),
    ];
}
$serviceStmt->close();
$documentDate = $isCashReceipt && $booking['payment_paid_at']
    ? date('M d, Y', strtotime($booking['payment_paid_at']))
    : ($isReservationReceipt && $booking['reservation_fee_verified_at']
        ? date('M d, Y', strtotime($booking['reservation_fee_verified_at']))
        : date('M d, Y'));
$receipt = [
    'business_name' => $businessName,
    'short_name' => isset($systemInfo['short_name']) ? $systemInfo['short_name'] : '',
    'code' => $booking['code'],
    'document_date' => $documentDate,
    'client_name' => $booking['client_name'],
    'contact' => $booking['contact'],
    'plate_number' => $booking['plate_number'],
    'vehicle' => $booking['vehicle'],
    'appointment' => date('M d, Y g:i A', strtotime($booking['schedule'])),
    'services' => $services,
    'formatted_subtotal' => number_format((float) $booking['total_amount'] - (float) $booking['reservation_fee'], 2),
    'reservation_fee' => (float) $booking['reservation_fee'],
    'formatted_reservation_fee' => number_format((float) $booking['reservation_fee'], 2),
    'formatted_total' => number_format((float) $booking['total_amount'], 2),
    'formatted_cash_balance' => number_format(
        max(0, (float) $booking['total_amount'] - ($booking['reservation_fee_payment_status'] === 'paid' ? (float) $booking['reservation_fee'] : 0)),
        2
    ),
    'formatted_amount_paid' => number_format(
        $isReservationReceipt
            ? (float) $booking['reservation_fee']
            : ($isCashReceipt
                ? ((float) $booking['cash_balance_amount'] > 0
                    ? (float) $booking['cash_balance_amount']
                    : ($booking['reservation_fee_payment_status'] === 'paid'
                        ? max(0, (float) $booking['total_amount'] - (float) $booking['reservation_fee'])
                        : (float) $booking['total_amount']))
                : 0),
        2
    ),
    'payment_method' => in_array(
        $isReservationReceipt ? $booking['reservation_fee_payment_method'] : $booking['payment_method'],
        ['demo_online', 'paymongo_qrph', 'stripe'],
        true
    )
        ? ($booking['payment_method'] === 'stripe' ? 'Stripe test (no real payment)' : 'Demo online (no real payment)')
        : ($isReservationReceipt
            ? (string) $booking['reservation_fee_payment_method']
            : ($booking['payment_status'] === 'paid'
                ? (in_array($booking['payment_method'], ['gcash', 'maya'], true) ? strtoupper($booking['payment_method']) . ' transfer reference submitted' : 'Cashier')
                : 'Not paid')),
    'transaction_reference' => $isReservationReceipt
        ? (string) $booking['reservation_fee_transaction_ref']
        : '',
    'reservation_fee_payment_status' => (string) $booking['reservation_fee_payment_status'],
    'reservation_fee_payment_method' => (string) $booking['reservation_fee_payment_method'],
];
ReceiptPdf::output(
    $isCashReceipt
        ? ($booking['payment_method'] === 'stripe'
            ? 'Stripe Test Payment Record (No Real Payment)'
            : (in_array($booking['payment_method'], ['demo_online', 'paymongo_qrph'], true)
            ? 'Demo Online Payment Record (No Real Payment)'
            : (in_array($booking['payment_method'], ['gcash', 'maya'], true)
                ? strtoupper($booking['payment_method']) . ' Transfer Receipt'
                : ((float) $booking['reservation_fee'] > 0 && $booking['reservation_fee_payment_status'] === 'paid' ? 'Cash Balance Receipt' : 'Cash Payment Receipt'))))
        : ($isReservationReceipt ? 'Reservation Fee Receipt' : 'Booking Confirmation'),
    $receipt,
    ($isCashReceipt ? 'cash-receipt-' : ($isReservationReceipt ? 'reservation-fee-receipt-' : 'booking-confirmation-')) . $booking['code'] . '.pdf',
    $receiptType
);
