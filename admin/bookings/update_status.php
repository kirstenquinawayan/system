<?php 
require_once('../../config.php');
if(isset($_GET['id']) && $_GET['id'] > 0){
    $qry = $conn->query("SELECT * from `booking_list` where id = '{$_GET['id']}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}
$decisionMode = isset($_GET['mode']) && $_GET['mode'] === 'decision';
?>
<div class="container-fluid">
    <div class="alert alert-info py-2">
        <?php if($decisionMode): ?>
            Choose whether to approve this customer's booking or cancel it.
        <?php else: ?>
            Record cash only after it is received. Paid bookings enter In Progress automatically; they are marked Completed after the combined duration of their selected services.
        <?php endif; ?>
    </div>
    <form action="" id="update_status-form">
        <input type="hidden" name="id" value="<?= isset($id) ? $id : '' ?>">
        <div class="form-group mb-3">
            <label for="status" class="control-label"><?= $decisionMode ? 'Booking decision' : 'Status' ?></label>
            <select name="status" id="status" class="form-control rounded-0">
                <?php if($decisionMode): ?>
                <option value="1" <?= isset($status) && (int) $status === 1 ? 'selected' : '' ?>>Approve</option>
                <option value="5" <?= isset($status) && (int) $status === 5 ? 'selected' : '' ?>>Cancel</option>
                <?php else: ?>
                <option value="0" <?= isset($status) && $status == 0 ? "selected" : "" ?>>Pending</option>
                <option value="1" <?= isset($status) && $status == 1 ? "selected" : "" ?>>Confirmed</option>
                <?php if(isset($status) && in_array((int) $status, [2, 3], true)): ?>
                <option value="3" selected disabled>In Progress (automatic)</option>
                <?php endif; ?>
                <option value="4" <?= isset($status) && $status == 4 ? "selected" : "" ?>>Completed</option>
                <option value="5" <?= isset($status) && $status == 5 ? "selected" : "" ?>>Cancelled</option>
                <?php if(isset($payment_status) && $payment_status === 'unpaid' && (!isset($status) || (int) $status !== 5)): ?>
                <option value="paid">Paid (record cash payment)</option>
                <?php endif; ?>
                <?php endif; ?>
            </select>
        </div>
    </form>
</div>
<script>
	$(document).ready(function(){
		$('#update_status-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			 $('.err-msg').remove();
            if($('#status').val() === 'paid' && !window.confirm("Confirm that the cash payment was received? This will automatically confirm the paid booking and place it in the appointment queue.")){
                return
            }
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=update_status",
				data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: 'json',
				error:err=>{
					console.log(err)
					alert_toast("An error occured",'error');
					end_loader();
				},
				success:function(resp){
					if(typeof resp =='object' && resp.status == 'success'){
						$.ajax({
							url:_base_url_+"classes/Master.php?f=advance_due_bookings",
							method:"POST",
							dataType:"json",
							success:function(progressResp){
								if(progressResp.status !== 'success'){
									alert_toast(progressResp.msg || "The next paid booking could not be advanced automatically.", 'error')
								}
							},
							error:function(){
								alert_toast("The next paid booking could not be advanced automatically. It will be retried from the dashboard or booking list.", 'error')
							},
							complete:function(){
								location.reload()
							}
						})
					}else if(resp.status == 'failed' && !!resp.msg){
                        var el = $('<div>')
                            el.addClass("alert alert-danger err-msg").text(resp.msg)
                            _this.prepend(el)
                            el.show('slow')
                            $("html, body").animate({ scrollTop: _this.closest('.card').offset().top }, "fast");
                            end_loader()
                    }else{
						alert_toast("An error occured",'error');
						end_loader();
					}
				}
			})
		})

	})
</script>