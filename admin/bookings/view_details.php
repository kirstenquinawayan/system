<?php 
if(isset($_GET['id'])){
    $bookingId = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    $qry = $bookingId
        ? $conn->query("SELECT b.*, v.name as vehicle,
                concat(u.firstname, ' ', u.lastname) as payment_received_by_name,
                concat(vu.firstname, ' ', vu.lastname) as reservation_fee_verified_by_name,
                concat(ju.firstname, ' ', ju.lastname) as reservation_fee_rejected_by_name,
                concat(ru.firstname, ' ', ru.lastname) as reservation_fee_refunded_by_name
            FROM `booking_list` b
            INNER JOIN `vehicle_list` v ON b.vehicle_id = v.id
            LEFT JOIN `users` u ON b.payment_received_by = u.id
            LEFT JOIN `users` vu ON b.reservation_fee_verified_by = vu.id
            LEFT JOIN `users` ju ON b.reservation_fee_rejected_by = ju.id
            LEFT JOIN `users` ru ON b.reservation_fee_refunded_by = ru.id
            WHERE b.id = '{$bookingId}'")
        : false;
    if($qry && $qry->num_rows > 0){
        $res = $qry->fetch_array();
        foreach($res as $k => $v){
            if(!is_numeric($k)){
                $$k = $v;
            }
        }
    }else{
        echo '<script> alert("Unknown Booking\'s ID."); location.replace("./?page=bookings"); </script>';
    }
}else{
    echo '<script> alert("Booking\'s ID is required to access the page."); location.replace("./?page=bookings"); </script>';
}
?>
<style>
    .booking-details-summary {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        margin: 0;
        text-align: left;
    }
    .booking-details-summary dt,
    .booking-details-summary dd {
        align-self: center;
        margin: 0;
        padding: 0.8rem 0;
        border-bottom: 1px solid #dee2e6;
    }
    .booking-details-summary dd {
        text-align: right;
    }
    .booking-details-summary .booking-payment-heading,
    .booking-details-summary .booking-payment-content {
        grid-column: 1 / -1;
    }
    .booking-details-summary .booking-payment-heading {
        padding-bottom: 0.35rem;
        border-bottom: 0;
    }
    .booking-details-summary .booking-payment-content {
        padding-top: 0;
        text-align: left;
    }
    .booking-details-summary .booking-total-value h3 {
        margin: 0;
        font-size: 2rem;
        font-weight: 700;
    }
    .booking-payment-result {
        text-align: right;
    }
    .paid-label-i {
        font-size: 0.75em;
    }
    .booking-delete-button {
        width: 100%;
        padding: 0.75rem;
    }
    @media (max-width: 575.98px) {
        .booking-details-summary .booking-total-value h3 {
            font-size: 1.6rem;
        }
        .booking-payment-result {
            text-align: left;
        }
    }
</style>
<div class="content py-3">
    <div class="card card-outline card-primary rounded-0 shadow">
        <div class="card-header">
            <h4 class="card-title">Booking Details: <b><?= isset($code) ? $code : "" ?></b></h4>
            <div class="card-tools">
                <a href="./?page=<?= !empty($is_archived) ? 'bookings/archived' : 'bookings' ?>" class="btn btn-default border btn-sm"><i class="fa fa-angle-left"></i> Back to List</a>
            </div>
        </div>
        <div class="card-body">
            <div class="container-fluid">
                <?php if(!empty($is_archived)): ?>
                    <div class="alert alert-secondary"><i class="fa fa-archive"></i> This booking is archived and hidden from the active Booking List.</div>
                <?php endif; ?>
                <div class="row mb-0">
                    <div class="col-3 border border-primary bg-gradient-primary mb-0"><b>Client Name</b></div>
                    <div class="col-9 border mb-0"><?= isset($client_name) ? $client_name : '' ?></div>
                    <div class="col-3 border border-primary bg-gradient-primary mb-0"><b>Contact #</b></div>
                    <div class="col-9 border mb-0"><?= isset($contact) ? $contact : '' ?></div>
                    <div class="col-3 border border-primary bg-gradient-primary mb-0"><b>Plate Number</b></div>
                    <div class="col-9 border mb-0"><?= isset($plate_number) ? $plate_number : '' ?></div>
                    <div class="col-3 border border-primary bg-gradient-primary mb-0"><b>Email</b></div>
                    <div class="col-9 border mb-0"><?= isset($email) ? $email : '' ?></div>
                    <div class="col-3 border border-primary bg-gradient-primary mb-0"><b>Address</b></div>
                    <div class="col-9 border mb-0"><?= isset($address) ? $address : '' ?></div>
                    <div class="col-3 border border-primary bg-gradient-primary mb-0"><b>Booked On</b></div>
                    <div class="col-9 border mb-0"><?= isset($date_created) ? date("M d, Y h:i A", strtotime($date_created)) : '' ?></div>
                    <div class="col-3 border border-primary bg-gradient-primary mb-0"><b>Vehicle Type</b></div>
                    <div class="col-9 border mb-0"><?= isset($vehicle) ? $vehicle : '' ?></div>
                    <div class="col-3 border border-primary bg-gradient-primary mb-0"><b>Appointment Schedule</b></div>
                    <div class="col-9 border mb-0"><?= isset($schedule) ? date("M d, Y g:i A", strtotime($schedule)) : '' ?></div>
                </div>
                <fieldset class="border-bottom">
                    <legend id="text-muted">Services:</legend>
                    <hr>
                    <div class="row">
                        <div class="col-12">
                            <div class="row m-0">
                                <div class="col-5 border bg-gradient-primary border-primary text-center mb-0"><b>Service Name</b></div>
                                <div class="col-7 border bg-gradient-primary border-primary text-center mb-0"><b>Price</b></div>
                                <?php 
                                $services = $conn->query("SELECT bs.*, s.name as `service` FROM `booking_services` bs inner join `service_list` s on bs.service_id = s.id where bs.booking_id = '{$id}' ");
                                while($row = $services->fetch_assoc()):
                                ?>
                                <div class="col-5 border mb-0"><b><?= $row['service'] ?></b></div>
                                <div class="col-7 border mb-0 text-right"><?= format_num($row['price']) ?></div>
                                <?php endwhile; ?>
                            </div>
                        </div>
                        <div class="col-12">
                            <dl class="booking-details-summary">
                                <?php $feePaymentStatus = isset($reservation_fee_payment_status) ? $reservation_fee_payment_status : 'not_required'; ?>
                                <dt><b><?= (float) $reservation_fee > 0 ? 'Services Subtotal' : 'Services' ?></b></dt>
                                <dd><?= isset($total_amount, $reservation_fee) ? format_num((float) $total_amount - (float) $reservation_fee) : '0.00' ?></dd>
                                <?php if((float) $reservation_fee > 0): ?>
                                <dt><b>Reservation Fee</b></dt>
                                <dd><?= isset($reservation_fee) ? format_num($reservation_fee) : '0.00' ?></dd>
                                <?php endif; ?>
                                <dt><b>Total Amount</b></dt>
                                <dd class="booking-total-value"><h3><?= isset($total_amount) ? format_num($total_amount) : '0.00' ?></h3></dd>
                                <dt class="booking-payment-heading"><b>Payment</b></dt>
                                <dd class="booking-payment-content">
                                    <?php if((float) $reservation_fee > 0): ?>
                                    <strong>Reservation fee:</strong>
                                    <?php
                                    $feePaymentLabels = [
                                        'unpaid' => 'Not submitted',
                                        'pending_verification' => 'Awaiting verification',
                                        'paid' => isset($reservation_fee_payment_method) && in_array($reservation_fee_payment_method, ['demo_online', 'paymongo_qrph'], true) ? 'Paid (demo)' : 'Verified',
                                        'rejected' => 'Rejected',
                                        'refunded' => 'Refunded',
                                        'not_required' => 'Included in booking balance',
                                    ];
                                    echo htmlspecialchars(isset($feePaymentLabels[$feePaymentStatus]) ? $feePaymentLabels[$feePaymentStatus] : 'Unknown', ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <?php if(!empty($reservation_fee_payment_method)): ?>
                                        <small class="d-block"><?= in_array($reservation_fee_payment_method, ['demo_online', 'paymongo_qrph'], true) ? 'Booking reference' : htmlspecialchars($reservation_fee_payment_method, ENT_QUOTES, 'UTF-8') . ' reference' ?>: <?= htmlspecialchars(in_array($reservation_fee_payment_method, ['demo_online', 'paymongo_qrph'], true) ? $code : $reservation_fee_transaction_ref, ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php endif; ?>
                                    <?php if(!empty($reservation_fee_submitted_at)): ?>
                                        <small class="d-block">Submitted <?= date("M d, Y g:i A", strtotime($reservation_fee_submitted_at)) ?></small>
                                    <?php endif; ?>
                                    <?php if(!empty($reservation_fee_verified_at)): ?>
                                        <small class="d-block"><?= isset($reservation_fee_payment_method) && in_array($reservation_fee_payment_method, ['demo_online', 'paymongo_qrph'], true) ? 'Demo payment recorded' : 'Verified' ?> <?= date("M d, Y g:i A", strtotime($reservation_fee_verified_at)) ?><?php if(!empty($reservation_fee_verified_by_name)): ?> by <?= htmlspecialchars($reservation_fee_verified_by_name, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></small>
                                    <?php endif; ?>
                                    <?php if(!empty($reservation_fee_rejected_at)): ?>
                                        <small class="d-block text-danger">Last rejected <?= date("M d, Y g:i A", strtotime($reservation_fee_rejected_at)) ?><?php if(!empty($reservation_fee_rejected_by_name)): ?> by <?= htmlspecialchars($reservation_fee_rejected_by_name, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></small>
                                    <?php endif; ?>
                                    <?php if($feePaymentStatus === 'refunded'): ?>
                                        <small class="d-block">Refund reference: <?= htmlspecialchars($reservation_fee_refund_ref, ENT_QUOTES, 'UTF-8') ?><?php if(!empty($reservation_fee_refunded_at)): ?> — <?= date("M d, Y g:i A", strtotime($reservation_fee_refunded_at)) ?><?php endif; ?><?php if(!empty($reservation_fee_refunded_by_name)): ?> by <?= htmlspecialchars($reservation_fee_refunded_by_name, ENT_QUOTES, 'UTF-8') ?><?php endif; ?></small>
                                    <?php endif; ?>
                                    <?php if($feePaymentStatus === 'pending_verification'): ?>
                                        <div class="mt-2">
                                            <?php if((int) $status !== 5 && empty($is_archived)): ?>
                                                <button class="btn btn-sm btn-success" id="verify_reservation_fee" type="button">Verify Transfer</button>
                                            <?php endif; ?>
                                            <?php if(empty($is_archived)): ?>
                                                <button class="btn btn-sm btn-outline-danger" id="reject_reservation_fee" type="button">Reject Reference</button>
                                            <?php endif; ?>
                                        </div>
                                    <?php elseif(isset($status, $payment_status) && (int) $status === 5 && $payment_status === 'unpaid' && $feePaymentStatus === 'paid'): ?>
                                        <div class="mt-2">
                                            <?php if(empty($is_archived)): ?>
                                                <button class="btn btn-sm btn-warning" id="refund_reservation_fee" type="button">Record Reservation Fee Refund</button>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                        Pay the remaining balance at the shop cashier. When Stripe test mode is configured, the customer can also use the test checkout on their booking confirmation; it does not collect real money.
                                    <?php endif; ?>
                                    <div class="booking-payment-result mt-3">
                                    <?php if(isset($payment_status) && $payment_status === 'paid'): ?>
                                        <span class="badge badge-success px-3 rounded-pill"><?= isset($payment_method) && in_array($payment_method, ['demo_online', 'paymongo_qrph', 'stripe'], true) ? ($payment_method === 'stripe' ? 'Stripe test payment recorded' : 'Demo payment recorded') : 'P<span class="paid-label-i">i</span>aid' ?></span>
                                        <small class="d-block"><?= isset($payment_method) && $payment_method === 'stripe' ? 'Test payment only; no real funds collected' : (in_array(isset($payment_method) ? $payment_method : '', ['demo_online', 'paymongo_qrph'], true) ? 'No real payment' : (in_array(isset($payment_method) ? $payment_method : '', ['gcash', 'maya'], true) ? 'Marked paid on reference submission' : 'Cashier payment received')) ?>: PHP <?= format_num(isset($cash_balance_amount) && (float) $cash_balance_amount > 0 ? $cash_balance_amount : ($feePaymentStatus === 'paid' ? max(0, (float) $total_amount - (float) $reservation_fee) : $total_amount)) ?></small>
                                        <?php if(!empty($payment_paid_at)): ?>
                                            <small class="d-block mt-1">Paid on <?= date("M d, Y", strtotime($payment_paid_at)) ?></small>
                                            <small class="d-block"><?= date("h:i A", strtotime($payment_paid_at)) ?></small>
                                        <?php endif; ?>
                                        <?php if(!empty($payment_received_by_name)): ?>
                                            <small class="d-block">Recorded by <?= htmlspecialchars($payment_received_by_name, ENT_QUOTES, 'UTF-8') ?></small>
                                        <?php endif; ?>
                                    <?php elseif(isset($payment_status) && $payment_status === 'unpaid'): ?>
                                        <span class="badge badge-warning px-3 rounded-pill">Unpaid</span>
                                        <small class="d-block">Balance due: PHP <?= format_num(max(0, (float) $total_amount - ($feePaymentStatus === 'paid' ? (float) $reservation_fee : 0))) ?></small>
                                    <?php else: ?>
                                        <span class="badge badge-secondary px-3 rounded-pill">Not required</span>
                                    <?php endif; ?>
                                    </div>
                                </dd>
                                <dt><b>Status</b></dt>
                                <dd>
                                    <?php 
                                    $status = isset($status) ? $status : '';
                                    switch($status){
                                        case 0:
                                            echo '<span class="badge badge-default border px-3 rounded-pill">Pending</span>';
                                            break;
                                        case 1:
                                            echo '<span class="badge badge-primary px-3 rounded-pill">Confirmed</span>';
                                            break;
                                        case 2:
                                        case 3:
                                            echo '<span class="badge badge-info px-3 rounded-pill">In Progress</span>';
                                            break;
                                        case 4:
                                            echo '<span class="badge badge-success px-3 rounded-pill">Completed</span>';
                                            break;
                                        case 5:
                                            echo '<span class="badge badge-danger px-3 rounded-pill">Cancelled</span>';
                                            break;
                                    }
                                    ?>
                                </dd>
                            </dl>
                        </div>
                    </div>
                </fieldset>
                <div class="clear-fix mb-2"></div>
                <div class="d-flex flex-wrap justify-content-center align-items-center mb-3">
                    <?php if(empty($is_archived) && isset($payment_status) && $payment_status === 'unpaid' && (int) $status !== 5): ?>
                    <button class="btn btn-success border rounded-pill mx-1 mb-2" id="mark_cash_paid" type="button"><i class="fa fa-money"></i> Record Cash Payment</button>
                    <?php endif; ?>
                    <?php if(empty($is_archived) && isset($payment_status) && $payment_status === 'paid'): ?>
                    <button class="btn btn-success border rounded-pill mx-1 mb-2" id="print_cash_receipt" type="button"><i class="fa fa-file-pdf-o"></i> View Payment Receipt PDF</button>
                    <?php endif; ?>
                    <?php if(empty($is_archived)): ?>
                    <button class="btn btn-primary bg-gradient-primary border rounded-pill mx-1 mb-2" id="update_status" type="button"><i class="fa fa-edit"></i> Update Status</button>
                    <?php endif; ?>
                </div>
                <?php if(!empty($is_archived)): ?>
                    <button class="btn btn-primary bg-gradient-primary border rounded-pill booking-delete-button" id="restore_booking" type="button"><i class="fa fa-undo"></i> Restore Booking</button>
                <?php else: ?>
                    <button class="btn btn-warning bg-gradient-warning border rounded-pill booking-delete-button" id="delete_booking" type="button"><i class="fa fa-archive"></i> Archive Booking</button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>

$(function(){
    var advancingDueBookings = false
    function advanceDueBookings(){
        if(advancingDueBookings || document.hidden){
            return
        }
        advancingDueBookings = true
        $.ajax({
            url:_base_url_+"classes/Master.php?f=advance_due_bookings",
            method:"POST",
            dataType:"json",
            error:function(){
                alert_toast("Could not automatically advance paid bookings.", 'error')
                window.clearInterval(dueBookingsInterval)
            },
            success:function(resp){
                if(resp.status === 'success'){
                    if(Number(resp.updated) > 0){
                        location.reload()
                    }
                }else{
                    alert_toast(resp.msg || "Could not automatically advance paid bookings.", 'error')
                    window.clearInterval(dueBookingsInterval)
                }
            },
            complete:function(){
                advancingDueBookings = false
            }
        })
    }
    advanceDueBookings()
    var dueBookingsInterval = window.setInterval(advanceDueBookings, 30000)

    $('#print_cash_receipt').click(function(){
        location.href = _base_url_+"receipt_pdf.php?type=cash&id=<?= isset($id) ? (int) $id : 0 ?>"
    })
    $('#mark_cash_paid').click(function(){
        _conf("Confirm that PHP <?= isset($cash_balance_amount) ? format_num(max(0, (float) $total_amount - ($feePaymentStatus === 'paid' ? (float) $reservation_fee : 0))) : (isset($total_amount) ? format_num($total_amount) : '0.00') ?> cash balance was received at the shop? Any open Stripe checkout will be expired before recording cash.", "mark_cash_payment_received", [<?= isset($id) ? (int) $id : 0 ?>])
    })
    $('#verify_reservation_fee').click(function(){
        _conf("Verify that this GCash/Maya transaction is visible in the shop account and matches the reservation fee?", "verify_reservation_fee_payment", [])
    })
    $('#reject_reservation_fee').click(function(){
        _conf("Reject this submitted transfer reference?", "reject_reservation_fee_payment", [])
    })
    $('.verify-online-payment').click(function(){
        var paymentId = $(this).attr('data-payment-id')
        _conf("Verify that this GCash/Maya transfer is visible in the shop account and matches the submitted amount and reference?", "verify_online_payment_submission", [paymentId])
    })
    $('.reject-online-payment').click(function(){
        var paymentId = $(this).attr('data-payment-id')
        _conf("Reject this GCash/Maya transfer reference?", "reject_online_payment_submission", [paymentId])
    })
    $('#refund_reservation_fee').click(function(){
        var reference = window.prompt("Enter the completed refund transaction reference:")
        if(reference === null || reference.trim() === ''){
            return
        }
        review_reservation_fee_payment("refund_reservation_fee", reference.trim())
    })
    $('#update_status').click(function(){
        uni_modal("Update Booking Status", "bookings/update_status.php?id=<?= isset($id) ? $id : '' ?>")
    })
    $('#delete_booking').click(function(){
        _conf("Move this booking to Archived Bookings? You can restore it later.","archive_booking",[])
    })
    $('#restore_booking').click(function(){
        _conf("Restore this booking to the Booking List?","restore_booking",[])
    })
})
function verify_reservation_fee_payment(){
    review_reservation_fee_payment("verify_reservation_fee_payment")
}
function reject_reservation_fee_payment(){
    review_reservation_fee_payment("reject_reservation_fee_payment")
}
function verify_online_payment_submission($id){
    review_online_payment_submission("verify_online_payment_submission", $id)
}
function reject_online_payment_submission($id){
    review_online_payment_submission("reject_online_payment_submission", $id)
}
function review_online_payment_submission(action, id){
    start_loader()
    $.ajax({
        url:_base_url_+"classes/Master.php?f="+action,
        method:"POST",
        data:{id:id},
        dataType:"json",
        error:function(){
            alert_toast("Could not update the transfer verification.", 'error')
            end_loader()
        },
        success:function(resp){
            if(resp.status === 'success'){
                location.reload()
            }else{
                alert_toast(resp.msg || "Could not update the transfer verification.", 'error')
                end_loader()
            }
        }
    })
}
function review_reservation_fee_payment(action, refundReference){
    start_loader()
    var data = {id: <?= isset($id) ? (int) $id : 0 ?>}
    if(refundReference){
        data.refund_reference = refundReference
    }
    $.ajax({
        url:_base_url_+"classes/Master.php?f="+action,
        method:"POST",
        data:data,
        dataType:"json",
        error:function(err){
            console.log(err)
            alert_toast("Could not update the reservation-fee payment.",'error')
            end_loader()
        },
        success:function(resp){
            if(resp.status === 'success'){
                location.reload()
            }else{
                alert_toast(resp.msg || "Could not update the reservation-fee payment.",'error')
                end_loader()
            }
        }
    })
}
function mark_cash_payment_received($id){
    start_loader()
    $.ajax({
        url:_base_url_+"classes/Master.php?f=mark_cash_payment_received",
        method:"POST",
        data:{id:$id},
        dataType:"json",
        error:err=>{
            console.log(err)
            alert_toast("Could not record cash payment.",'error')
            end_loader()
        },
        success:function(resp){
            if(resp.status === 'success'){
                location.reload()
            }else{
                alert_toast(resp.msg || "Could not record cash payment.",'error')
                end_loader()
            }
        }
    })
}
function archive_booking($id){
		start_loader();
		$.ajax({
			url:_base_url_+"classes/Master.php?f=archive_booking",
			method:"POST",
			data:{id: '<?= isset($id) ? $id : "" ?>'},
			dataType:"json",
			error:err=>{
				console.log(err)
				alert_toast("Could not archive this booking.",'error');
				end_loader();
			},
			success:function(resp){
				if(typeof resp== 'object' && resp.status == 'success'){
					location.replace('./?page=bookings/archived');
				}else{
					alert_toast(resp.msg || "Could not archive this booking.",'error');
					end_loader();
				}
			}
		})
	}
function restore_booking($id){
	start_loader()
	$.ajax({
		url:_base_url_+"classes/Master.php?f=restore_bookings",
		method:"POST",
		data:{id:'<?= isset($id) ? (int) $id : 0 ?>'},
		dataType:"json",
		error:function(){
			alert_toast("Could not restore this booking.", 'error')
			end_loader()
		},
		success:function(resp){
			if(resp.status === 'success'){
				location.replace('./?page=bookings')
			}else{
				alert_toast(resp.msg || "Could not restore this booking.", 'error')
				end_loader()
			}
		}
	})
}
</script>