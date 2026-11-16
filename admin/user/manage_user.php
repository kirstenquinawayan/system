<?php 
if(empty($_SESSION['user_management_csrf'])){
    $_SESSION['user_management_csrf'] = bin2hex(random_bytes(32));
}
if(isset($_GET['id'])){
    $userId = (int) $_GET['id'];
    $user = $conn->query("SELECT * FROM users where id = '{$userId}' ");
    foreach($user->fetch_array() as $k =>$v){
        $meta[$k] = $v;
    }
}
?>
<?php if($_settings->chk_flashdata('success')): ?>
<script>
	alert_toast("<?php echo $_settings->flashdata('success') ?>",'success')
</script>
<?php endif;?>
<div class="card card-outline rounded-0 card-primary">
	<div class="card-body">
		<div class="container-fluid">
			<div id="msg"></div>
			<form action="" id="manage-user">	
				<input type="hidden" name="id" value="<?= isset($meta['id']) ? $meta['id'] : '' ?>">
				<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['user_management_csrf'], ENT_QUOTES, 'UTF-8') ?>">
				<div class="form-group">
					<label for="name">First Name</label>
					<input type="text" name="firstname" id="firstname" class="form-control" value="<?php echo isset($meta['firstname']) ? $meta['firstname']: '' ?>" required>
				</div>
				<div class="form-group">
					<label for="name">Last Name</label>
					<input type="text" name="lastname" id="lastname" class="form-control" value="<?php echo isset($meta['lastname']) ? $meta['lastname']: '' ?>" required>
				</div>
				<div class="form-group">
					<label for="username">Username</label>
					<input type="text" name="username" id="username" class="form-control" value="<?php echo isset($meta['username']) ? $meta['username']: '' ?>" required  autocomplete="off">
				</div>
				<div class="form-group">
					<label for="email">Recovery Email</label>
					<input type="email" name="email" id="email" class="form-control" value="<?php echo isset($meta['email']) ? htmlspecialchars($meta['email'], ENT_QUOTES, 'UTF-8'): '' ?>" maxlength="254" autocomplete="email" required>
					<small class="form-text text-muted">Password reset links will be sent to this address.</small>
				</div>
				<div class="form-group">
					<label for="password"><?= isset($meta['id']) ? "New" : "" ?> Password</label>
					<div class="input-group">
						<input type="password" name="password" id="password" class="form-control" value="" autocomplete="new-password" minlength="12" maxlength="72" <?= isset($meta['id']) ? '' : 'required' ?>>
						<div class="input-group-append">
							<button type="button" class="btn btn-outline-secondary toggle-password-visibility" data-target="password" aria-label="Show password" aria-pressed="false"><i class="fas fa-eye" aria-hidden="true"></i></button>
						</div>
					</div>
                    <?php if(isset($meta['id'])): ?>
					<small><i>Leave blank to keep the current password. New passwords must be at least 12 characters.</i></small>
                    <?php else: ?>
					<small><i>Use at least 12 characters.</i></small>
                    <?php endif; ?>
				</div>
                <div class="form-group">
                    <label for="type" class="control-label">Type</label>
                    <select name="type" id="type" class="form-control form-control-sm rounded-0" required>
                    <option value="1" <?php echo isset($meta['type']) && $meta['type'] == 1 ? 'selected' : '' ?>>Administrator</option>
                    <option value="2" <?php echo isset($meta['type']) && $meta['type'] == 2 ? 'selected' : '' ?>>Staff</option>
                    </select>
                </div>
				<div class="form-group">
					<label for="" class="control-label">Avatar</label>
					<div class="custom-file">
		              <input type="file" class="custom-file-input rounded-circle" id="customFile" name="img" onchange="displayImg(this,$(this))" accept="image/png, image/jpeg">
		              <label class="custom-file-label" for="customFile">Choose file</label>
		            </div>
				</div>
				<div class="form-group d-flex justify-content-center">
					<img src="<?php echo validate_image(isset($meta['avatar']) ? $meta['avatar'] :'') ?>" alt="" id="cimg" class="img-fluid img-thumbnail">
				</div>
			</form>
		</div>
	</div>
	<div class="card-footer">
			<div class="col-md-12">
				<div class="row">
					<button class="btn btn-sm btn-primary rounded-0 mr-3" form="manage-user">Save User Details</button>
					<a href="./?page=user/list" class="btn btn-sm btn-default border rounded-0" form="manage-user"><i class="fa fa-angle-left"></i> Cancel</a>
				</div>
			</div>
		</div>
</div>
<style>
	img#cimg{
		height: 15vh;
		width: 15vh;
		object-fit: cover;
		border-radius: 100% 100%;
	}
</style>
<script>
	function displayImg(input,_this) {
	    if (input.files && input.files[0]) {
	        var reader = new FileReader();
	        reader.onload = function (e) {
	        	$('#cimg').attr('src', e.target.result);
	        }

	        reader.readAsDataURL(input.files[0]);
	    }else{
			$('#cimg').attr('src', "<?php echo validate_image(isset($meta['avatar']) ? $meta['avatar'] :'') ?>");
		}
	}
	$('.toggle-password-visibility').on('click', function(){
		var button = $(this);
		var input = $('#' + button.data('target'));
		var showPassword = input.attr('type') === 'password';
		input.attr('type', showPassword ? 'text' : 'password');
		button.attr('aria-pressed', showPassword ? 'true' : 'false')
			.attr('aria-label', showPassword ? 'Hide password' : 'Show password');
		button.find('i').toggleClass('fa-eye', !showPassword).toggleClass('fa-eye-slash', showPassword);
	});
	$('#manage-user').submit(function(e){
		e.preventDefault();
		start_loader()
		$.ajax({
			url:_base_url_+'classes/Users.php?f=save',
			data: new FormData($(this)[0]),
		    cache: false,
		    contentType: false,
		    processData: false,
		    method: 'POST',
		    type: 'POST',
			success:function(resp){
				if(resp ==1){
					location.href='./?page=user/list'
				}else{
					var message = resp == 2 ? 'Username or email is already in use.' : (resp == 5 ? 'You cannot remove or downgrade the last administrator account.' : 'Unable to save this account. Check the email and password requirements, then try again.')
					$('#msg').html($('<div>', {class: 'alert alert-danger', text: message}))
					end_loader()
				}
			},
			error:function(){
				$('#msg').html($('<div>', {class: 'alert alert-danger', text: 'Unable to save this account. Please sign in as an administrator and try again.'}))
				end_loader()
			}
		})
	})

</script>