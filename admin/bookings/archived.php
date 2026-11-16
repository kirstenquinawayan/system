<?php
$archivedBookings = $conn->query(
	"SELECT b.id, b.code, b.client_name, b.schedule, b.date_created, b.plate_number, b.status, v.name AS vehicle
	 FROM booking_list b
	 INNER JOIN vehicle_list v ON v.id = b.vehicle_id
	 WHERE b.is_archived = 1
	 ORDER BY b.date_updated DESC, b.id DESC"
);
if(!$archivedBookings){
	error_log('Archived bookings query failed: ' . $conn->error);
}
?>
<div class="card card-outline rounded-0 card-secondary">
	<div class="card-header">
		<h3 class="card-title">Archived Bookings</h3>
		<div class="card-tools">
			<a href="?page=bookings" class="btn btn-default btn-sm">Back to Booking List</a>
			<button type="button" class="btn btn-primary btn-sm" id="restore_selected" disabled>
				<span class="fa fa-undo"></span> Restore Selected
			</button>
		</div>
	</div>
	<div class="card-body">
		<?php if(!$archivedBookings): ?>
			<div class="alert alert-danger">Archived bookings are temporarily unavailable.</div>
		<?php else: ?>
			<div class="table-responsive">
				<table class="table table-hover table-striped table-bordered">
					<thead>
						<tr>
							<th class="text-center"><input type="checkbox" id="select_all_archived" aria-label="Select archived bookings on this page"></th>
							<th>#</th>
							<th>Booked On</th>
							<th>Code</th>
							<th>Customer</th>
							<th>Schedule</th>
							<th>Vehicle</th>
							<th>Plate Number</th>
							<th>Status</th>
							<th>Action</th>
						</tr>
					</thead>
					<tbody>
						<?php $index = 1; while($row = $archivedBookings->fetch_assoc()): ?>
							<tr>
								<td class="text-center"><input type="checkbox" class="archived-booking-select" value="<?= (int) $row['id'] ?>" aria-label="Select archived booking <?= htmlspecialchars($row['code'], ENT_QUOTES, 'UTF-8') ?>"></td>
								<td><?= $index++ ?></td>
								<td><?= date('M d, Y g:i A', strtotime($row['date_created'])) ?></td>
								<td><?= htmlspecialchars($row['code'], ENT_QUOTES, 'UTF-8') ?></td>
								<td><?= htmlspecialchars($row['client_name'], ENT_QUOTES, 'UTF-8') ?></td>
								<td><?= date('M d, Y g:i A', strtotime($row['schedule'])) ?></td>
								<td><?= htmlspecialchars($row['vehicle'], ENT_QUOTES, 'UTF-8') ?></td>
								<td><?= htmlspecialchars($row['plate_number'], ENT_QUOTES, 'UTF-8') ?></td>
								<td>
									<?php
									$statusLabels = [0 => 'Pending', 1 => 'Confirmed', 2 => 'In Progress', 3 => 'In Progress', 4 => 'Completed', 5 => 'Cancelled'];
									echo htmlspecialchars(isset($statusLabels[(int) $row['status']]) ? $statusLabels[(int) $row['status']] : 'Unknown', ENT_QUOTES, 'UTF-8');
									?>
								</td>
								<td class="text-center text-nowrap">
									<a class="btn btn-default btn-sm" href="?page=bookings/view_details&id=<?= (int) $row['id'] ?>"><i class="fa fa-eye"></i> View</a>
									<button type="button" class="btn btn-primary btn-sm restore-archived-booking" data-booking-id="<?= (int) $row['id'] ?>"><i class="fa fa-undo"></i> Restore</button>
								</td>
							</tr>
						<?php endwhile; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>
<script>
	$(function(){
		var archivedTable = $('.table').DataTable({
			columnDefs:[{orderable:false, searchable:false, targets:[0, 9]}],
			order:[[1, 'asc']]
		})
		function updateRestoreControls(){
			var pageBoxes = archivedTable.rows({page:'current'}).nodes().to$().find('.archived-booking-select')
			var checked = pageBoxes.filter(':checked').length
			$('#restore_selected').prop('disabled', $('.archived-booking-select:checked').length === 0)
			$('#select_all_archived')
				.prop('checked', pageBoxes.length > 0 && checked === pageBoxes.length)
				.prop('indeterminate', checked > 0 && checked < pageBoxes.length)
		}
		$('#select_all_archived').on('change', function(){
			archivedTable.rows({page:'current'}).nodes().to$().find('.archived-booking-select').prop('checked', this.checked)
			updateRestoreControls()
		})
		$(document).on('change', '.archived-booking-select', updateRestoreControls)
		archivedTable.on('draw', updateRestoreControls)
		$('#restore_selected').on('click', function(){
			if($('.archived-booking-select:checked').length){
				_conf("Restore the selected bookings to the Booking List?", "restore_selected_bookings", [])
			}
		})
		$('.restore-archived-booking').on('click', function(){
			_conf("Restore this booking to the Booking List?", "restore_one_booking", [$(this).data('booking-id')])
		})
		updateRestoreControls()
	})
	function restoreArchivedBookings(ids){
		start_loader()
		$.ajax({
			url:_base_url_+"classes/Master.php?f=restore_bookings",
			method:"POST",
			data:{booking_ids:ids},
			dataType:"json",
			error:function(){
				alert_toast("Could not restore the selected bookings.", 'error')
				end_loader()
			},
			success:function(resp){
				if(resp.status === 'success'){
					location.reload()
				}else{
					alert_toast(resp.msg || "Could not restore the selected bookings.", 'error')
					end_loader()
				}
			}
		})
	}
	function restore_selected_bookings(){
		var ids = $('.archived-booking-select:checked').map(function(){ return this.value }).get()
		if(ids.length){
			restoreArchivedBookings(ids)
		}
	}
	function restore_one_booking(id){
		restoreArchivedBookings([id])
	}
</script>
