<?php

require_once('../config.php');
$serviceId = isset($_GET['id']) ? filter_var($_GET['id'], FILTER_VALIDATE_INT) : false;
$serviceName = '';
$vehicles = false;
if($serviceId !== false && $serviceId > 0){
    $serviceStatement = $conn->prepare(
        "SELECT name FROM service_list WHERE id = ? AND status = 1 AND delete_flag = 0 LIMIT 1"
    );
    if($serviceStatement){
        $serviceStatement->bind_param('i', $serviceId);
        if($serviceStatement->execute()){
            $serviceStatement->bind_result($serviceName);
            $serviceStatement->fetch();
        }else{
            error_log('Public service price name query failed: ' . $serviceStatement->error);
        }
        $serviceStatement->close();
    }else{
        error_log('Public service price name query prepare failed: ' . $conn->error);
    }
    if($serviceName !== ''){
        $priceStatement = $conn->prepare(
            "SELECT v.name, p.price
             FROM vehicle_list v
             LEFT JOIN price_list p ON p.vehicle_id = v.id AND p.service_id = ?
             WHERE v.status = 1 AND v.delete_flag = 0
             ORDER BY CASE v.name
                WHEN 'Sedan' THEN 1
                WHEN 'AUV' THEN 2
                WHEN 'MPV' THEN 3
                WHEN 'Pick Up' THEN 4
                WHEN 'Ordinary Van' THEN 5
                WHEN 'Premium Van' THEN 6
                ELSE 99
             END, v.name ASC"
        );
        if($priceStatement){
            $priceStatement->bind_param('i', $serviceId);
            if($priceStatement->execute()){
                $priceStatement->bind_result($vehicleName, $vehiclePrice);
                $vehicles = [];
                while($priceStatement->fetch()){
                    $vehicles[] = [
                        'name' => $vehicleName,
                        'price' => $vehiclePrice,
                    ];
                }
            }else{
                error_log('Public service price list query failed: ' . $priceStatement->error);
            }
            $priceStatement->close();
        }else{
            error_log('Public service price list query prepare failed: ' . $conn->error);
        }
    }
}
?>
<style>
    #uni_modal .modal-footer{
        display:none !important;
    }
</style>
<div class="container-fluid">
    <?php if($serviceName === ''): ?>
        <div class="alert alert-warning">Service prices are unavailable.</div>
    <?php elseif($vehicles === false): ?>
        <div class="alert alert-danger">Could not load prices for this service. Please try again later.</div>
    <?php else: ?>
        <div class="row mx-0 w-100">
            <div class="col-6 border px-2 py-1 font-weight-bold">Vehicle Type</div>
            <div class="col-6 border px-2 py-1 font-weight-bold text-right">Price</div>
            <?php foreach($vehicles as $vehicle): ?>
                <div class="col-6 border px-2 py-1"><?= htmlspecialchars($vehicle['name'], ENT_QUOTES, 'UTF-8') ?></div>
                <div class="col-6 border px-2 py-1 text-right">
                    <?= $vehicle['price'] !== null ? 'PHP ' . number_format((float) $vehicle['price'], 2) : 'Price unavailable' ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="clear-fix mb-4"></div>
    <div class="text-right">
        <button class="btn btn-default border btn-sm" type="button" data-dismiss="modal"><i class="fa fa-times"></i> Close</button>
    </div>
</div>