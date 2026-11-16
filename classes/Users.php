<?php
require_once('../config.php');

class Users extends DBConnection {
	private $settings;

	public function __construct(){
		global $_settings;
		$this->settings = $_settings;
		parent::__construct();
	}

	private function is_admin(){
		return (int) $this->settings->userdata('login_type') === 1;
	}

	private function valid_csrf_token(){
		return isset($_POST['csrf_token'], $_SESSION['user_management_csrf']) &&
			is_string($_POST['csrf_token']) &&
			hash_equals($_SESSION['user_management_csrf'], $_POST['csrf_token']);
	}

	private function update_session_user($id, $firstname, $lastname, $username, $email, $type){
		if((int) $this->settings->userdata('id') === (int) $id){
			$this->settings->set_userdata('firstname', $firstname);
			$this->settings->set_userdata('lastname', $lastname);
			$this->settings->set_userdata('username', $username);
			$this->settings->set_userdata('email', $email);
			$this->settings->set_userdata('type', $type);
		}
	}

	private function save_avatar($id){
		if(!isset($_FILES['img']) || $_FILES['img']['error'] === UPLOAD_ERR_NO_FILE){
			return true;
		}
		if($_FILES['img']['error'] !== UPLOAD_ERR_OK){
			error_log('User avatar upload failed with code ' . $_FILES['img']['error']);
			return false;
		}
		$imageInfo = getimagesize($_FILES['img']['tmp_name']);
		if(!$imageInfo || !in_array($imageInfo['mime'], array('image/jpeg', 'image/png'), true)){
			error_log('User avatar upload rejected: unsupported image type.');
			return false;
		}
		if(!function_exists('imagecreatefromjpeg') || !function_exists('imagecreatefrompng')){
			error_log('User avatar upload requires the PHP GD extension.');
			return false;
		}
		if(!is_dir(base_app . 'uploads/avatars') &&
			!mkdir(base_app . 'uploads/avatars', 0755, true) &&
			!is_dir(base_app . 'uploads/avatars')){
			error_log('Could not create the user avatar directory.');
			return false;
		}

		$image = $imageInfo['mime'] === 'image/jpeg'
			? imagecreatefromjpeg($_FILES['img']['tmp_name'])
			: imagecreatefrompng($_FILES['img']['tmp_name']);
		if(!$image){
			error_log('User avatar upload rejected: image could not be decoded.');
			return false;
		}
		$scaled = imagescale($image, 200, 200);
		imagedestroy($image);
		if(!$scaled){
			error_log('User avatar could not be resized.');
			return false;
		}
		$avatarPath = 'uploads/avatars/' . $id . '.png';
		$written = imagepng($scaled, base_app . $avatarPath);
		imagedestroy($scaled);
		if(!$written){
			error_log('User avatar could not be saved.');
			return false;
		}
		$avatar = $avatarPath . '?v=' . time();
		$stmt = $this->conn->prepare("UPDATE users SET avatar = ? WHERE id = ?");
		if(!$stmt){
			error_log('User avatar update prepare failed: ' . $this->conn->error);
			return false;
		}
		$stmt->bind_param('si', $avatar, $id);
		$updated = $stmt->execute();
		if(!$updated){
			error_log('User avatar update failed: ' . $stmt->error);
		}
		$stmt->close();
		if($updated && (int) $this->settings->userdata('id') === (int) $id){
			$this->settings->set_userdata('avatar', $avatar);
		}
		return $updated;
	}

	public function save_users(){
		if(!$this->is_admin()){
			http_response_code(403);
			return 4;
		}
		if(!$this->valid_csrf_token()){
			http_response_code(400);
			return 4;
		}
		$id = !isset($_POST['id']) || trim((string) $_POST['id']) === ''
			? 0
			: filter_var($_POST['id'], FILTER_VALIDATE_INT);
		if($id === false || $id < 0){
			return 3;
		}
		$firstname = trim(isset($_POST['firstname']) ? (string) $_POST['firstname'] : '');
		$lastname = trim(isset($_POST['lastname']) ? (string) $_POST['lastname'] : '');
		$username = trim(isset($_POST['username']) ? (string) $_POST['username'] : '');
		$email = strtolower(trim(isset($_POST['email']) ? (string) $_POST['email'] : ''));
		$password = isset($_POST['password']) ? (string) $_POST['password'] : '';
		$type = isset($_POST['type']) ? filter_var($_POST['type'], FILTER_VALIDATE_INT) : false;
		if($firstname === '' || $lastname === '' || $username === '' ||
			strlen($firstname) > 250 || strlen($lastname) > 250 || strlen($username) > 250 ||
			!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254 ||
			!in_array($type, array(1, 2), true) ||
			($id === 0 && strlen($password) < 12) ||
			($password !== '' && (strlen($password) < 12 || strlen($password) > 72))){
			return 3;
		}

		$duplicate = $this->conn->prepare(
			"SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ? LIMIT 1"
		);
		if(!$duplicate){
			error_log('User uniqueness check prepare failed: ' . $this->conn->error);
			return 3;
		}
		$duplicate->bind_param('ssi', $username, $email, $id);
		if(!$duplicate->execute()){
			error_log('User uniqueness check failed: ' . $duplicate->error);
			$duplicate->close();
			return 3;
		}
		$duplicate->store_result();
		$hasDuplicate = $duplicate->num_rows > 0;
		$duplicate->close();
		if($hasDuplicate){
			return 2;
		}

		$hashedPassword = '';
		if($password !== ''){
			$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
			if($hashedPassword === false){
				error_log('User password hashing failed.');
				return 3;
			}
		}

		if($id === 0){
			$stmt = $this->conn->prepare(
				"INSERT INTO users (firstname, lastname, username, email, password, type) VALUES (?, ?, ?, ?, ?, ?)"
			);
			if(!$stmt){
				error_log('User insert prepare failed: ' . $this->conn->error);
				return 3;
			}
			$stmt->bind_param('sssssi', $firstname, $lastname, $username, $email, $hashedPassword, $type);
		}else{
			$exists = $this->conn->prepare("SELECT type, email FROM users WHERE id = ?");
			if(!$exists){
				error_log('User existence check prepare failed: ' . $this->conn->error);
				return 3;
			}
			$exists->bind_param('i', $id);
			if(!$exists->execute()){
				error_log('User existence check failed: ' . $exists->error);
				$exists->close();
				return 3;
			}
			$exists->bind_result($existingType, $existingEmail);
			$userExists = $exists->fetch();
			$exists->close();
			if(!$userExists){
				return 3;
			}
			if((int) $existingType === 1 && $type !== 1){
				$admins = $this->conn->query("SELECT COUNT(*) AS total FROM users WHERE type = 1");
				if(!$admins){
					error_log('Administrator count query failed: ' . $this->conn->error);
					return 3;
				}
				$adminCount = $admins->fetch_assoc();
				if((int) $adminCount['total'] <= 1){
					return 5;
				}
			}
			if($password !== '' || $email !== (string) $existingEmail){
				$invalidateTokens = $this->conn->prepare("DELETE FROM admin_password_resets WHERE user_id = ?");
				if(!$invalidateTokens){
					error_log('Password reset token invalidation prepare failed: ' . $this->conn->error);
					return 3;
				}
				$invalidateTokens->bind_param('i', $id);
				if(!$invalidateTokens->execute()){
					error_log('Password reset token invalidation failed: ' . $invalidateTokens->error);
					$invalidateTokens->close();
					return 3;
				}
				$invalidateTokens->close();
			}
			if($password !== ''){
				$stmt = $this->conn->prepare(
					"UPDATE users SET firstname = ?, lastname = ?, username = ?, email = ?, password = ?, type = ? WHERE id = ?"
				);
				if(!$stmt){
					error_log('User update prepare failed: ' . $this->conn->error);
					return 3;
				}
				$stmt->bind_param('sssssii', $firstname, $lastname, $username, $email, $hashedPassword, $type, $id);
			}else{
				$stmt = $this->conn->prepare(
					"UPDATE users SET firstname = ?, lastname = ?, username = ?, email = ?, type = ? WHERE id = ?"
				);
				if(!$stmt){
					error_log('User update prepare failed: ' . $this->conn->error);
					return 3;
				}
				$stmt->bind_param('ssssii', $firstname, $lastname, $username, $email, $type, $id);
			}
		}

		$saved = $stmt->execute();
		if(!$saved){
			error_log('User save failed: ' . $stmt->error);
		}
		$stmt->close();
		if(!$saved){
			return 3;
		}
		if($id === 0){
			$id = $this->conn->insert_id;
			$this->settings->set_flashdata('success', 'User Details successfully saved.');
		}else{
			$this->settings->set_flashdata('success', 'User Details successfully updated.');
		}
		$this->update_session_user($id, $firstname, $lastname, $username, $email, $type);
		if(!$this->save_avatar($id)){
			$this->settings->set_flashdata('success', 'User details saved, but the avatar could not be updated.');
		}
		return 1;
	}

	public function delete_users(){
		if(!$this->is_admin()){
			http_response_code(403);
			return 4;
		}
		if(!$this->valid_csrf_token()){
			http_response_code(400);
			return 4;
		}
		$id = isset($_POST['id']) ? filter_var($_POST['id'], FILTER_VALIDATE_INT) : false;
		if($id === false || $id < 1 || (int) $this->settings->userdata('id') === (int) $id){
			return 3;
		}
		$target = $this->conn->prepare("SELECT type FROM users WHERE id = ?");
		if(!$target){
			error_log('User delete target prepare failed: ' . $this->conn->error);
			return 3;
		}
		$target->bind_param('i', $id);
		if(!$target->execute()){
			error_log('User delete target lookup failed: ' . $target->error);
			$target->close();
			return 3;
		}
		$target->bind_result($targetType);
		$targetExists = $target->fetch();
		$target->close();
		if(!$targetExists){
			return 3;
		}
		if((int) $targetType === 1){
			$admins = $this->conn->query("SELECT COUNT(*) AS total FROM users WHERE type = 1");
			if(!$admins){
				error_log('Administrator count query failed: ' . $this->conn->error);
				return 3;
			}
			$adminCount = $admins->fetch_assoc();
			if((int) $adminCount['total'] <= 1){
				return 5;
			}
		}
		$stmt = $this->conn->prepare("DELETE FROM users WHERE id = ?");
		if(!$stmt){
			error_log('User delete prepare failed: ' . $this->conn->error);
			return 3;
		}
		$stmt->bind_param('i', $id);
		$deleted = $stmt->execute();
		if(!$deleted){
			error_log('User delete failed: ' . $stmt->error);
		}
		$stmt->close();
		if(!$deleted){
			return 3;
		}
		$this->settings->set_flashdata('success', 'User Details successfully deleted.');
		if(is_file(base_app . 'uploads/avatars/' . $id . '.png')){
			unlink(base_app . 'uploads/avatars/' . $id . '.png');
		}
		return 1;
	}
}

$users = new Users();
$action = isset($_GET['f']) ? strtolower($_GET['f']) : '';
if($action === 'save'){
	echo $users->save_users();
}elseif($action === 'delete'){
	echo $users->delete_users();
}
