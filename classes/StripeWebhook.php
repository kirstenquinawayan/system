<?php
require_once('../config.php');
require_once(__DIR__ . '/StripeTestCheckout.php');

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
	http_response_code(405);
	header('Allow: POST');
	exit;
}

$payload = file_get_contents('php://input');
if($payload === false){
	http_response_code(400);
	exit;
}
$result = StripeTestCheckout::handleWebhook(
	$conn,
	$payload,
	isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : ''
);
http_response_code($result['http_status']);
header('Content-Type: application/json');
echo json_encode(['status' => $result['status']]);
