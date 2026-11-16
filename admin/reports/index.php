<div class="card card-outline card-primary">
    <div class="card-header">
        <h3 class="card-title">Booking and Cash Payment Reports</h3>
    </div>
    <div class="card-body">
        <form method="get" class="form-inline mb-3">
            <input type="hidden" name="page" value="reports">
            <label class="mr-2" for="date-type">Filter by</label>
            <select class="form-control mr-3 mb-2" id="date-type" name="date_type">
                <option value="appointment" <?= $dateType === 'appointment' ? 'selected' : '' ?>>Appointment date</option>
                <option value="booking" <?= $dateType === 'booking' ? 'selected' : '' ?>>Booking date</option>
            </select>
            <small class="text-muted mr-3 mb-2">Choose Booking date to find recently submitted bookings, or Appointment date to plan the schedule.</small>
            <label class="mr-2" for="start-date"><span id="date-label"><?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?></span> from</label>
            <input class="form-control mr-3 mb-2" type="date" id="start-date" name="start_date" value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') ?>" required>
            <label class="mr-2" for="end-date">through</label>
            <input class="form-control mr-3 mb-2" type="date" id="end-date" name="end_date" value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') ?>" required>
            <button class="btn btn-primary mb-2" type="submit">View Report</button>
        </form>
        <?php if($dateError): ?>
            <div class="alert alert-danger">The report could not be loaded. Check the date range or try again later.</div>
        <?php elseif($summary && $reportRows): ?>
            <div class="row">
                <div class="col-md-3"><div class="info-box"><div class="info-box-content"><span class="info-box-text">Bookings</span><span class="info-box-number"><?= format_num($summary['booking_count']) ?></span></div></div></div>
                <div class="col-md-3"><div class="info-box"><div class="info-box-content"><span class="info-box-text">Paid / demo bookings</span><span class="info-box-number"><?= format_num($summary['paid_count']) ?></span></div></div></div>
                <div class="col-md-3"><div class="info-box"><div class="info-box-content"><span class="info-box-text">Balance due</span><span class="info-box-number"><?= format_num($summary['unpaid_count']) ?></span></div></div></div>
                <div class="col-md-3"><div class="info-box"><div class="info-box-content"><span class="info-box-text">Payments recorded incl. demo (PHP)</span><span class="info-box-number"><?= number_format($summary['cash_received'], 2) ?></span></div></div></div>
            </div>
            <p>Total booking value (including unpaid): <strong>PHP <?= number_format($summary['booking_value'], 2) ?></strong></p>
            <div class="mb-3">
                <a class="btn btn-outline-success" href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>"><i class="fas fa-file-csv"></i> Download CSV</a>
                <button class="btn btn-outline-secondary" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <thead><tr><th>Reference</th><th>Customer</th><th><?= htmlspecialchars($dateLabel, ENT_QUOTES, 'UTF-8') ?></th><th>Appointment</th><th>Vehicle</th><th>Plate</th><th>Status</th><th>Reservation fee</th><th>Payment</th><th>Total (PHP)</th><th>Paid on</th><th>Recorded by</th></tr></thead>
                    <tbody>
                    <?php if($reportRows->num_rows === 0): ?>
                        <tr><td colspan="12" class="text-center">No bookings found for this <?= strtolower($dateLabel) ?> range.</td></tr>
                    <?php else: ?>
                        <?php while($row = $reportRows->fetch_assoc()): ?>
                            <?php $statusLabels = ['Pending', 'Confirmed', 'In Progress', 'In Progress', 'Completed', 'Cancelled']; ?>
                            <tr>
                                <td><?= htmlspecialchars($row['code'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['client_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(date('M d, Y h:i A', strtotime($row[$dateColumn])), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($row['schedule'])), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['vehicle'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($row['plate_number'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars(isset($statusLabels[(int) $row['status']]) ? $statusLabels[(int) $row['status']] : 'Unknown', ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if((float) $row['reservation_fee'] > 0): ?>
                                        PHP <?= number_format((float) $row['reservation_fee'], 2) ?>
                                        <small class="d-block"><?= $row['reservation_fee_payment_status'] === 'not_required' ? 'Included in booking balance' : (in_array($row['reservation_fee_payment_method'], ['demo_online', 'paymongo_qrph'], true) ? 'Paid (demo)' : htmlspecialchars(str_replace('_', ' ', ucfirst($row['reservation_fee_payment_status'])), ENT_QUOTES, 'UTF-8')) ?></small>
                                    <?php else: ?>
                                        <?= htmlspecialchars(str_replace('_', ' ', ucfirst($row['reservation_fee_payment_status'])), ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                    <?php if($row['reservation_fee_payment_method']): ?>
                                        <small class="d-block"><?= in_array($row['reservation_fee_payment_method'], ['demo_online', 'paymongo_qrph'], true) ? 'Booking reference' : htmlspecialchars($row['reservation_fee_payment_method'], ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars(in_array($row['reservation_fee_payment_method'], ['demo_online', 'paymongo_qrph'], true) ? $row['code'] : $row['reservation_fee_transaction_ref'], ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if($row['payment_status'] === 'paid'): ?>
                                        Paid: PHP <?= number_format($row['reservation_fee_payment_status'] === 'refunded' ? max(0, (float) $row['total_amount'] - (float) $row['reservation_fee']) : (float) $row['total_amount'], 2) ?>
                                        <small class="d-block"><?= $row['payment_method'] === 'stripe' ? 'Stripe test (no real payment)' : (in_array($row['payment_method'], ['demo_online', 'paymongo_qrph'], true) ? 'Demo online (no real payment)' : (in_array($row['payment_method'], ['gcash', 'maya'], true) ? strtoupper($row['payment_method']) . ' transfer reference submitted' : 'Cashier')) ?></small>
                                    <?php elseif($row['payment_status'] === 'unpaid' && in_array($row['reservation_fee_payment_status'], ['paid', 'not_required'], true)): ?>
                                        <?php if($row['reservation_fee_payment_status'] === 'paid'): ?>
                                            Received: PHP <?= number_format((float) $row['reservation_fee'], 2) ?>
                                            <small class="d-block">Balance due: PHP <?= number_format(max(0, (float) $row['total_amount'] - (float) $row['reservation_fee']), 2) ?></small>
                                        <?php else: ?>
                                            Due: PHP <?= number_format((float) $row['total_amount'], 2) ?>
                                        <?php endif; ?>
                                    <?php elseif((int) $row['status'] === 5): ?>
                                        Cancelled
                                    <?php elseif($row['payment_status'] === 'not_required'): ?>
                                        Not required
                                    <?php else: ?>
                                        Awaiting fee verification
                                    <?php endif; ?>
                                </td>
                                <td class="text-right"><?= number_format((float) $row['total_amount'], 2) ?></td>
                                <td><?= $row['payment_paid_at'] ? htmlspecialchars(date('M d, Y h:i A', strtotime($row['payment_paid_at'])), ENT_QUOTES, 'UTF-8') : '—' ?></td>
                                <td><?= htmlspecialchars($row['payment_received_by'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php $reportRows->free(); ?>
            <?php $rowsStatement->close(); ?>
        <?php endif; ?>
    </div>
</div>
<script>
$(function(){
    $('#date-type').on('change', function(){
        $('#date-label').text(this.value === 'booking' ? 'Booking date' : 'Appointment date')
    })
})
</script>
<style>
@media print {
    .main-header, .main-sidebar, .content-header, .card-header, form, .btn, .main-footer { display: none !important; }
    .content-wrapper, .card, .card-body { margin: 0 !important; padding: 0 !important; box-shadow: none !important; border: 0 !important; }
}
</style>

