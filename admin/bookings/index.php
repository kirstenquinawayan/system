<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<?php
$bookingGroups = ['unpaid' => [], 'paid' => []];
$qry = $conn->query("SELECT b.*, v.name as vehicle, concat(u.firstname, ' ', u.lastname) as payment_received_by_name,
		p.payment_provider AS pending_payment_provider, p.transaction_ref AS pending_payment_reference
	FROM `booking_list` b
	INNER JOIN `vehicle_list` v ON b.vehicle_id = v.id
	LEFT JOIN `users` u ON b.payment_received_by = u.id
	LEFT JOIN booking_online_payments p ON p.id = (
		SELECT MAX(pending.id) FROM booking_online_payments pending
		WHERE pending.booking_id = b.id AND pending.status = 'pending_verification'
	)
	WHERE b.is_archived = 0
	ORDER BY unix_timestamp(b.date_updated) DESC");
while($row = $qry->fetch_assoc()){
	$group = $row['payment_status'] === 'paid' ? 'paid' : 'unpaid';
	$bookingGroups[$group][] = $row;
}
?>
<div class="card card-outline rounded-0 card-primary">
	<div class="card-header">
		<h3 class="card-title">List of Bookings</h3>
		<div class="card-tools">
			<button type="button" class="btn btn-warning btn-sm" id="delete_selected" disabled>
				<span class="fa fa-archive"></span> Archive Selected
			</button>
		</div>
	</div>
	<div class="card-body">
		<div class="row">
			<?php foreach(['unpaid' => 'Unpaid', 'paid' => 'Paid'] as $groupKey => $groupLabel): ?>
				<div class="col-12 col-xl-6 mb-3">
					<div class="card card-outline <?= $groupKey === 'paid' ? 'card-success' : 'card-warning' ?> booking-panel h-100">
						<div class="card-header">
							<h3 class="card-title"><?= $groupLabel ?> <span class="badge badge-light"><?= count($bookingGroups[$groupKey]) ?></span></h3>
						</div>
						<div class="card-body">
							<table class="table table-hover table-striped table-bordered booking-table">
									<thead>
										<tr>
											<th class="text-center"><input type="checkbox" class="select-all-bookings" aria-label="Select all <?= strtolower($groupLabel) ?> bookings on this page"></th>
											<th>#</th>
											<th>Booked On</th>
											<th>Code</th>
											<th>Schedule</th>
											<th>Vehicle Type</th>
											<th>Plate Number</th>
											<th>Status</th>
											<th>Payment</th>
											<th>Action</th>
										</tr>
									</thead>
									<tbody>
										<?php $i = 1; foreach($bookingGroups[$groupKey] as $row): ?>
											<tr>
												<td class="text-center"><input type="checkbox" class="booking-select" value="<?php echo (int) $row['id']; ?>" aria-label="Select booking <?php echo htmlspecialchars($row['code'], ENT_QUOTES, 'UTF-8'); ?>"></td>
												<td class="text-center"><?php echo $i++; ?></td>
												<td><p class="m-0"><?= date("M d, Y", strtotime($row['date_created'])) ?><br><?= date("h:i A", strtotime($row['date_created'])) ?></p></td>
												<td><p class="m-0 truncate-1"><?= $row['code'] ?></p></td>
												<td><p class="m-0"><?= date("M d, Y", strtotime($row['schedule'])) ?><br><?= date("g:i A", strtotime($row['schedule'])) ?></p></td>
												<td><p class="m-0 truncate-1"><?= $row['vehicle'] ?></p></td>
												<td><p class="m-0 truncate-1"><?= $row['plate_number'] ?></p></td>
												<td class="text-center">
													<?php
													switch($row['status']){
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
												</td>
												<td class="text-center">
													<?php if($row['payment_status'] === 'paid'): ?>
														<span class="badge badge-success px-3 rounded-pill">Paid</span>
														<?php if(!empty($row['payment_method'])): ?>
															<small class="d-block"><?= $row['payment_method'] === 'stripe' ? 'Stripe test (no real payment)' : (in_array($row['payment_method'], ['demo_online', 'paymongo_qrph'], true) ? 'Demo online (no real payment)' : (in_array($row['payment_method'], ['gcash', 'maya'], true) ? strtoupper($row['payment_method']) . ' transfer reference submitted' : 'Cashier')) ?></small>
														<?php endif; ?>
														<?php if(!empty($row['payment_paid_at'])): ?>
															<small class="d-block">Paid on <?= date("M d, Y", strtotime($row['payment_paid_at'])) ?></small>
															<small class="d-block"><?= date("h:i A", strtotime($row['payment_paid_at'])) ?></small>
														<?php endif; ?>
														<?php if(!empty($row['payment_received_by_name'])): ?>
															<small class="d-block">Recorded by <?= htmlspecialchars($row['payment_received_by_name'], ENT_QUOTES, 'UTF-8') ?></small>
														<?php endif; ?>
													<?php elseif($row['payment_status'] === 'unpaid'): ?>
														<?php if(!empty($row['pending_payment_provider'])): ?>
															<span class="badge badge-warning px-3 rounded-pill">Awaiting verification</span>
															<small class="d-block"><?= htmlspecialchars(strtoupper($row['pending_payment_provider']), ENT_QUOTES, 'UTF-8') ?> ref: <?= htmlspecialchars($row['pending_payment_reference'], ENT_QUOTES, 'UTF-8') ?></small>
														<?php else: ?>
															<span class="badge badge-warning px-3 rounded-pill">Unpaid</span>
														<?php endif; ?>
													<?php else: ?>
														<span class="badge badge-secondary px-3 rounded-pill">N/A</span>
													<?php endif; ?>
												</td>
												<td align="center">
													<a class="btn btn-default btn-flat btn-sm" href="?page=bookings/view_details&id=<?php echo $row['id'] ?>"><span class="fa fa-eye"></span> View</a>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
							</table>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</div>
<script>
	var bookingTables = []

	$(document).ready(function(){
		$('.booking-table').each(function(){
			bookingTables.push($(this).DataTable({
				columnDefs: [
					{ orderable: false, searchable: false, targets: [0, 9] }
				],
				order:[[1,'asc']],
				scrollX: true
			}))
		});
		$('.dataTable td,.dataTable th').addClass('py-1 px-2 align-middle')

		function updateSelectionControls(){
			var selectedCount = 0
			$.each(bookingTables, function(_, table){
				selectedCount += table.rows().nodes().to$().find('.booking-select:checked').length
			})
			$('#delete_selected').prop('disabled', selectedCount === 0)
			$('.booking-panel').each(function(){
				var table = $(this).find('.booking-table').DataTable()
				var checkboxes = table.rows({page: 'current'}).nodes().to$().find('.booking-select')
				var pageSelectedCount = checkboxes.filter(':checked').length
				$(this).find('.select-all-bookings')
					.prop('checked', checkboxes.length > 0 && pageSelectedCount === checkboxes.length)
					.prop('indeterminate', pageSelectedCount > 0 && pageSelectedCount < checkboxes.length)
			})
		}

		$('.select-all-bookings').on('change', function(){
			var table = $(this).closest('.booking-panel').find('.booking-table').DataTable()
			table.rows({page: 'current'}).nodes().to$().find('.booking-select').prop('checked', this.checked)
			updateSelectionControls()
		})
		$(document).on('change', '.booking-select', updateSelectionControls)
		$.each(bookingTables, function(_, table){
			table.on('draw', updateSelectionControls)
		})
		$('#delete_selected').on('click', function(){
			if(getSelectedBookingIds().length){
				_conf("Move the selected bookings to Archived Bookings? You can restore them later.", "archive_selected_bookings", [])
			}
		})
		updateSelectionControls()
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
				error:function(err){
					console.log(err)
					alert_toast("Could not automatically start bookings due for service.",'error')
					window.clearInterval(dueBookingsInterval)
				},
				success:function(resp){
					if(resp.status === 'success'){
						if(Number(resp.updated) > 0){
							location.reload()
						}
					}else{
						alert_toast(resp.msg || "Could not automatically start bookings due for service.",'error')
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

	function getSelectedBookingIds(){
		var bookingIds = []
		$.each(bookingTables, function(_, table){
			table.rows().nodes().to$().find('.booking-select:checked').each(function(){
				bookingIds.push(this.value)
			})
		})
		return bookingIds
	}

	function archive_selected_bookings(){
		var bookingIds = getSelectedBookingIds()
		if(!bookingIds.length){
			return
		}
		start_loader()
		$.ajax({
			url:_base_url_+"classes/Master.php?f=archive_bookings",
			method:"POST",
			data:{booking_ids: bookingIds},
			dataType:"json",
			error:function(err){
				console.log(err)
				alert_toast("Could not archive the selected bookings.",'error')
				end_loader()
			},
			success:function(resp){
				if(resp.status === 'success'){
					location.reload()
				}else{
					alert_toast(resp.msg || "Could not archive the selected bookings.",'error')
					end_loader()
				}
			}
		})
	}
</script>