<?php
require_once __DIR__ . '/../../config.php';

$userType = isset($_SESSION['userdata']['type']) ? (int) $_SESSION['userdata']['type'] : 0;
if(
    !isset($_SESSION['userdata']['login_type']) ||
    (int) $_SESSION['userdata']['login_type'] !== 1 ||
    !in_array($userType, [1, 2], true)
){
    http_response_code(403);
    exit('Administrator or staff access is required.');
}
$startDate = isset($_GET['start_date']) && is_string($_GET['start_date']) ? $_GET['start_date'] : '';
$endDate = isset($_GET['end_date']) && is_string($_GET['end_date']) ? $_GET['end_date'] : '';
$dateType = isset($_GET['date_type']) && is_string($_GET['date_type']) ? $_GET['date_type'] : 'booking';
$dateColumn = $dateType === 'booking' ? 'date_created' : 'schedule';
$isDate = function($value){
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    return $date &&
        ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) &&
        $date->format('Y-m-d') === $value;
};
if(!in_array($dateType, ['appointment', 'booking'], true) || !$isDate($startDate) || !$isDate($endDate) || $startDate > $endDate){
    http_response_code(400);
    exit('A valid report date type and date range are required.');
}
$statement = $conn->prepare(
    "SELECT b.code, b.client_name, b.email, b.contact, b.schedule, b.date_created, v.name AS vehicle,
            b.plate_number, b.status, b.payment_status, b.payment_method, b.total_amount, b.reservation_fee,
            b.reservation_fee_payment_status, b.reservation_fee_payment_method,
            b.reservation_fee_transaction_ref, b.reservation_fee_refund_ref,
            b.payment_paid_at, CONCAT(u.firstname, ' ', u.lastname) AS payment_received_by
     FROM booking_list b
     INNER JOIN vehicle_list v ON v.id = b.vehicle_id
     LEFT JOIN users u ON u.id = b.payment_received_by
     WHERE b.{$dateColumn} >= ? AND b.{$dateColumn} < DATE_ADD(?, INTERVAL 1 DAY)
     ORDER BY b.{$dateColumn} DESC, b.schedule, b.code"
);
if(!$statement){
    error_log('Booking CSV report prepare failed: ' . $conn->error);
    http_response_code(500);
    exit('The report is temporarily unavailable.');
}
$rangeStart = $startDate . ' 00:00:00';
$rangeEnd = $endDate . ' 00:00:00';
$statement->bind_param('ss', $rangeStart, $rangeEnd);
if(!$statement->execute()){
    error_log('Booking CSV report query failed: ' . $statement->error);
    $statement->close();
    http_response_code(500);
    exit('The report is temporarily unavailable.');
}
$rows = $statement->get_result();
$filename = 'booking-report-' . $dateType . '-' . $startDate . '-to-' . $endDate . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
$output = fopen('php://output', 'w');
if($output === false){
    $statement->close();
    http_response_code(500);
    exit('The report could not be generated.');
}
fputcsv($output, ['Booking reference', 'Customer', 'Email', 'Contact', 'Booking date', 'Appointment', 'Vehicle', 'Plate', 'Booking status', 'Reservation fee status', 'Reservation fee method', 'Transfer reference', 'Refund reference', 'Payment status', 'Payment method', 'Amount paid PHP', 'Total PHP', 'Reservation fee PHP', 'Paid at', 'Recorded by']);
$statusLabels = ['Pending', 'Confirmed', 'In Progress', 'In Progress', 'Completed', 'Cancelled'];
while($row = $rows->fetch_assoc()){
    $values = [
        $row['code'],
        $row['client_name'],
        $row['email'],
        $row['contact'],
        $row['date_created'],
        $row['schedule'],
        $row['vehicle'],
        $row['plate_number'],
        isset($statusLabels[(int) $row['status']]) ? $statusLabels[(int) $row['status']] : 'Unknown',
        $row['reservation_fee_payment_status'],
        in_array($row['reservation_fee_payment_method'], ['demo_online', 'paymongo_qrph'], true)
            ? 'Demo online (no real payment)'
            : ($row['reservation_fee_payment_method'] ?: ''),
        $row['reservation_fee_transaction_ref'] ?: '',
        $row['reservation_fee_refund_ref'] ?: '',
        $row['payment_status'],
        $row['payment_method'] === 'stripe'
            ? 'Stripe test (no real payment)'
            : (in_array($row['payment_method'], ['demo_online', 'paymongo_qrph'], true)
            ? 'Demo online (no real payment)'
            : (in_array($row['payment_method'], ['gcash', 'maya'], true)
                ? strtoupper($row['payment_method']) . ' transfer reference submitted'
                : ($row['payment_method'] === 'cash' ? 'Cashier' : $row['payment_method']))),
        number_format(
            $row['payment_status'] === 'paid'
                ? ($row['reservation_fee_payment_status'] === 'refunded'
                    ? max(0, (float) $row['total_amount'] - (float) $row['reservation_fee'])
                    : (float) $row['total_amount'])
                : ($row['reservation_fee_payment_status'] === 'paid' ? (float) $row['reservation_fee'] : 0),
            2,
            '.',
            ''
        ),
        number_format((float) $row['total_amount'], 2, '.', ''),
        number_format((float) $row['reservation_fee'], 2, '.', ''),
        $row['payment_paid_at'] ?: '',
        $row['payment_received_by'] ?: '',
    ];
    foreach($values as &$value){
        $value = (string) $value;
        if(preg_match('/^\s*[=+\-@]/', $value)){
            $value = "'" . $value;
        }
    }
    unset($value);
    fputcsv($output, $values);
}
fclose($output);
$statement->close();
