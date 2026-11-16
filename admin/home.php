<h1>Welcome to <?php echo $_settings->info('name') ?></h1>
<hr>
<div class="card card-outline card-primary booking-status-overview">
  <div class="card-header">
    <h3 class="card-title">Booking Status Overview</h3>
  </div>
  <div class="card-body">
    <div class="row">
      <?php
        $booking_statuses = [
          0 => ['Pending', 'secondary', 'clock'],
          1 => ['Confirmed', 'primary', 'check'],
          3 => ['In Progress', 'info', 'tools'],
          4 => ['Completed', 'success', 'check-circle'],
          5 => ['Cancelled', 'danger', 'times-circle']
        ];
        $booking_status_counts = array_fill_keys(array_keys($booking_statuses), 0);
        $status_query = $conn->query("SELECT CASE WHEN `status` = 2 THEN 3 ELSE `status` END AS `status`, COUNT(*) AS total FROM booking_list WHERE is_archived = 0 GROUP BY CASE WHEN `status` = 2 THEN 3 ELSE `status` END");
        while($status_row = $status_query->fetch_assoc()){
          if(array_key_exists((int)$status_row['status'], $booking_status_counts)){
            $booking_status_counts[(int)$status_row['status']] = (int)$status_row['total'];
          }
        }
        foreach($booking_statuses as $status_id => $status_info):
      ?>
      <div class="col-12 col-sm-6 col-md-4 booking-status-item">
        <div class="info-box">
          <span class="info-box-icon bg-gradient-<?= $status_info[1] ?> elevation-1"><i class="fas fa-<?= $status_info[2] ?>"></i></span>
          <div class="info-box-content">
            <span class="info-box-text"><?= $status_info[0] ?></span>
            <span class="info-box-number"><?= format_num($booking_status_counts[$status_id]) ?></span>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <div class="col-12 col-sm-6 col-md-4 booking-status-item">
        <div class="info-box">
          <span class="info-box-icon bg-gradient-info elevation-1"><i class="fas fa-book"></i></span>
          <div class="info-box-content">
            <span class="info-box-text">Total Bookings</span>
            <span class="info-box-number">
              <?php
                $total_bookings = $conn->query("SELECT COUNT(*) AS total FROM booking_list WHERE is_archived = 0")->fetch_assoc()['total'];
                echo format_num($total_bookings);
              ?>
            </span>
          </div>
        </div>
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
					alert_toast("Could not automatically start bookings due for service.", 'error')
					window.clearInterval(dueBookingsInterval)
				},
				success:function(resp){
					if(resp.status === 'success'){
						if(Number(resp.updated) > 0){
							location.reload()
						}
					}else{
						alert_toast(resp.msg || "Could not automatically start bookings due for service.", 'error')
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
	})
</script>
<?php
  $payment_summary_query = $conn->query(
    "SELECT
      SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid_count,
      SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_count,
      SUM(CASE
          WHEN payment_status = 'paid' AND reservation_fee_payment_status = 'refunded'
              THEN GREATEST(total_amount - reservation_fee, 0)
          WHEN payment_status = 'paid' THEN total_amount
          WHEN reservation_fee_payment_status = 'paid' THEN reservation_fee
          ELSE 0 END) AS payments_received
     FROM booking_list"
  );
  if(!$payment_summary_query){
    error_log('Dashboard payment summary query failed: ' . $conn->error);
    $payment_summary = false;
  }else{
    $payment_summary = $payment_summary_query->fetch_assoc();
  }
  $recent_payments = $conn->query(
    "SELECT payment_rows.*
     FROM (
         SELECT b.id, b.code, b.client_name,
                CASE WHEN b.cash_balance_amount > 0 THEN b.cash_balance_amount
                     WHEN b.reservation_fee_payment_status = 'paid'
                         THEN GREATEST(b.total_amount - b.reservation_fee, 0)
                     ELSE b.total_amount END AS cash_amount,
                b.date_created, b.payment_paid_at,
                COALESCE(b.payment_method, 'cash') AS payment_method,
                CONCAT(u.firstname, ' ', u.lastname) AS payment_received_by_name
         FROM booking_list b
         LEFT JOIN users u ON b.payment_received_by = u.id
         WHERE b.payment_status = 'paid'
           AND COALESCE(b.payment_method, 'cash') NOT IN ('demo_online', 'paymongo_qrph', 'gcash', 'maya', 'stripe')
         UNION ALL
         SELECT b.id, b.code, b.client_name, p.amount_cents / 100 AS cash_amount,
                b.date_created, p.paid_at AS payment_paid_at,
                CASE WHEN p.transaction_ref IS NOT NULL THEN p.payment_provider ELSE 'demo_online' END AS payment_method,
                CASE WHEN p.verified_by IS NOT NULL THEN 'Verified by staff' ELSE 'Paid' END AS payment_received_by_name
         FROM booking_online_payments p
         INNER JOIN booking_list b ON b.id = p.booking_id
         WHERE p.status = 'paid'
     ) AS payment_rows
     ORDER BY payment_paid_at DESC, id DESC
     LIMIT 5"
  );
  if(!$recent_payments){
    error_log('Dashboard recent payments query failed: ' . $conn->error);
  }
  $pending_transfer_payments = $conn->query(
    "SELECT p.id, p.booking_id, p.payment_provider, p.amount_cents, p.transaction_ref, p.date_created,
            b.code, b.client_name
     FROM booking_online_payments p
     INNER JOIN booking_list b ON b.id = p.booking_id
     WHERE p.status = 'pending_verification' AND b.is_archived = 0
     ORDER BY p.date_created ASC, p.id ASC"
  );
  if(!$pending_transfer_payments){
    error_log('Dashboard pending transfer notification query failed: ' . $conn->error);
  }
?>
<div class="card card-outline card-success">
  <div class="card-header">
    <h3 class="card-title">Payment Records</h3>
    <div class="card-tools">
      <a href="<?= base_url ?>admin/?page=bookings" class="btn btn-sm btn-outline-primary">View All Bookings</a>
    </div>
  </div>
  <div class="card-body">
    <?php if(!$pending_transfer_payments): ?>
      <div class="alert alert-danger">Transfer verification notifications are temporarily unavailable.</div>
    <?php elseif($pending_transfer_payments->num_rows > 0): ?>
      <div class="alert alert-warning">
        <h5><i class="fas fa-bell"></i> GCash/Maya transfers awaiting verification (<?= (int) $pending_transfer_payments->num_rows ?>)</h5>
        <div class="table-responsive">
          <table class="table table-sm table-bordered mb-0">
            <thead><tr><th>Booking</th><th>Customer</th><th>Method / Reference</th><th>Amount</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
              <?php while($pendingTransfer = $pending_transfer_payments->fetch_assoc()): ?>
                <tr>
                  <td><?= htmlspecialchars($pendingTransfer['code'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($pendingTransfer['client_name'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars(strtoupper($pendingTransfer['payment_provider']), ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($pendingTransfer['transaction_ref'], ENT_QUOTES, 'UTF-8') ?></td>
                  <td class="text-right">PHP <?= number_format((int) $pendingTransfer['amount_cents'] / 100, 2) ?></td>
                  <td><?= date("M d, Y h:i A", strtotime($pendingTransfer['date_created'])) ?></td>
                  <td><a class="btn btn-sm btn-warning" href="<?= base_url ?>admin/?page=bookings/view_details&id=<?= (int) $pendingTransfer['booking_id'] ?>">Review</a></td>
                </tr>
              <?php endwhile; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>
    <div class="row">
      <div class="col-12 col-sm-6 col-md-4">
        <div class="info-box">
          <span class="info-box-icon bg-gradient-warning elevation-1"><i class="fas fa-hourglass-half"></i></span>
          <div class="info-box-content">
            <span class="info-box-text">Unpaid</span>
            <span class="info-box-number"><?= $payment_summary ? format_num((int) $payment_summary['unpaid_count']) : 'Unavailable' ?></span>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-md-4">
        <div class="info-box">
          <span class="info-box-icon bg-gradient-success elevation-1"><i class="fas fa-check-circle"></i></span>
          <div class="info-box-content">
            <span class="info-box-text">Paid Bookings (test payments included)</span>
            <span class="info-box-number"><?= $payment_summary ? format_num((int) $payment_summary['paid_count']) : 'Unavailable' ?></span>
          </div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-md-4">
        <div class="info-box">
          <span class="info-box-icon bg-gradient-info elevation-1"><i class="fas fa-money-bill-wave"></i></span>
          <div class="info-box-content">
            <span class="info-box-text">Payments Recorded (test payments included)</span>
            <span class="info-box-number"><?= $payment_summary ? format_num((float) $payment_summary['payments_received']) : 'Unavailable' ?></span>
          </div>
        </div>
      </div>
    </div>
    <h5 class="mt-2">Recent Payment History</h5>
    <div class="table-responsive">
      <table class="table table-hover table-striped table-bordered">
        <thead>
          <tr>
            <th>Booking Code</th>
            <th>Customer</th>
            <th>Amount Paid</th>
            <th>Method</th>
            <th>Booked On</th>
            <th>Date Paid</th>
            <th>Recorded</th>
            <th>Record</th>
          </tr>
        </thead>
        <tbody>
          <?php if(!$recent_payments): ?>
            <tr><td colspan="8" class="text-center text-danger">Payment history is temporarily unavailable.</td></tr>
          <?php elseif($recent_payments->num_rows === 0): ?>
            <tr><td colspan="8" class="text-center">No payments have been recorded yet.</td></tr>
          <?php else: ?>
            <?php while($payment = $recent_payments->fetch_assoc()): ?>
              <tr>
                <td><?= htmlspecialchars($payment['code'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($payment['client_name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-right"><?= format_num((float) $payment['cash_amount']) ?></td>
                <td><?= $payment['payment_method'] === 'stripe' ? 'Stripe test (no real payment)' : (in_array($payment['payment_method'], ['demo_online', 'paymongo_qrph'], true) ? 'Demo online (no real payment)' : (in_array($payment['payment_method'], ['gcash', 'maya'], true) ? strtoupper($payment['payment_method']) . ' transfer' : 'Cashier')) ?></td>
                <td><?= date("M d, Y h:i A", strtotime($payment['date_created'])) ?></td>
                <td><?= $payment['payment_paid_at'] ? date("M d, Y h:i A", strtotime($payment['payment_paid_at'])) : 'N/A' ?></td>
                <td><?= htmlspecialchars($payment['payment_received_by_name'] ?: 'Not recorded', ENT_QUOTES, 'UTF-8') ?></td>
                <td class="text-center">
                  <a class="btn btn-default btn-sm" href="<?= base_url ?>admin/?page=bookings/view_details&id=<?= (int) $payment['id'] ?>">View</a>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<div class="row">
        <div class="col-12 col-sm-6 col-md-6">
            <div class="info-box">
              <span class="info-box-icon bg-gradient-primary elevation-1"><i class="fas fa-car"></i></span>
              <div class="info-box-content">
                <span class="info-box-text">Vehicle Types</span>
                <span class="info-box-number">
                  <?php 
                    $vehicle = $conn->query("SELECT * FROM vehicle_list where delete_flag = 0")->num_rows;
                    echo format_num($vehicle);
                  ?>
                  <?php ?>
                </span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <!-- /.col -->
          <div class="col-12 col-sm-6 col-md-6">
            <div class="info-box">
              <span class="info-box-icon bg-gradient-light elevation-1"><i class="fas fa-th-list"></i></span>

              <div class="info-box-content">
                <span class="info-box-text">Total Services</span>
                <span class="info-box-number">
                  <?php 
                    $services = $conn->query("SELECT * FROM service_list where delete_flag = 0 ")->num_rows;
                    echo format_num($services);
                  ?>
                  <?php ?>
                </span>
              </div>
              <!-- /.info-box-content -->
            </div>
            <!-- /.info-box -->
          </div>
          <!-- /.col -->
        </div>
<div class="container">
  <?php 
    $files = array();
      $fopen = scandir(base_app.'uploads/banner');
      foreach($fopen as $fname){
        if(in_array($fname,array('.','..')))
          continue;
        $files[]= validate_image('uploads/banner/'.$fname);
      }
  ?>
  <div id="tourCarousel"  class="carousel slide" data-ride="carousel" data-interval="3000">
      <div class="carousel-inner h-100">
          <?php foreach($files as $k => $img): ?>
          <div class="carousel-item  h-100 <?php echo $k == 0? 'active': '' ?>">
              <img class="d-block w-100  h-100" style="object-fit:contain" src="<?php echo $img ?>" alt="">
          </div>
          <?php endforeach; ?>
      </div>
      <a class="carousel-control-prev" href="#tourCarousel" role="button" data-slide="prev">
          <span class="carousel-control-prev-icon" aria-hidden="true"></span>
          <span class="sr-only">Previous</span>
      </a>
      <a class="carousel-control-next" href="#tourCarousel" role="button" data-slide="next">
          <span class="carousel-control-next-icon" aria-hidden="true"></span>
          <span class="sr-only">Next</span>
      </a>
  </div>
</div>
