<?php
require_once '../config.php';
class Login extends DBConnection {
	private $settings;
	public function __construct(){
		global $_settings;
		$this->settings = $_settings;

		parent::__construct();
		ini_set('display_error', 1);
	}
	public function __destruct(){
		parent::__destruct();
	}
	public function index(){
		echo "<h1>Access Denied</h1> <a href='".base_url."'>Go Back.</a>";
	}
	public function login(){
		$username = isset($_POST['username']) ? trim((string) $_POST['username']) : '';
		$password = isset($_POST['password']) ? (string) $_POST['password'] : '';
		if($username === '' || $password === ''){
			return json_encode(array('status'=>'invalid'));
		}

		$stmt = $this->conn->prepare("SELECT * from users where username = ? LIMIT 1");
		if(!$stmt){
			return json_encode(array('status'=>'failed'));
		}
		$stmt->bind_param('s', $username);
		if(!$stmt->execute()){
			$stmt->close();
			return json_encode(array('status'=>'failed'));
		}
		$result = $stmt->get_result();
		$user = $result ? $result->fetch_assoc() : false;
		$stmt->close();
		$passwordValid = $user && password_verify($password, $user['password']);
		$isLegacyPassword = $user && hash_equals((string) $user['password'], md5($password));
		if($passwordValid || $isLegacyPassword){
			if($isLegacyPassword){
				$upgradedPassword = password_hash($password, PASSWORD_DEFAULT);
				if($upgradedPassword === false){
					return json_encode(array('status'=>'failed'));
				}
				$upgrade = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ?");
				if(!$upgrade){
					return json_encode(array('status'=>'failed'));
				}
				$upgrade->bind_param('si', $upgradedPassword, $user['id']);
				if(!$upgrade->execute()){
					$upgrade->close();
					return json_encode(array('status'=>'failed'));
				}
				$upgrade->close();
			}
			session_regenerate_id(true);
			foreach($user as $key => $value){
				if($key !== 'password'){
					$this->settings->set_userdata($key, $value);
				}
			}
			$this->settings->set_userdata('login_type', 1);
			return json_encode(array('status'=>'success'));
		}
		return json_encode(array('status'=>'incorrect'));
	}
	public function logout(){
		if($this->settings->sess_des()){
			redirect('admin/login.php');
		}
	}
	function login_agent(){
		extract($_POST);
		$stmt = $this->conn->prepare("SELECT * from agent_list where email = ? and `password` = ? and delete_flag = 0 ");
		$password = md5($password);
		$stmt->bind_param('ss',$email,$password);
		$stmt->execute();
		$result = $stmt->get_result();
		if($result->num_rows > 0){
			$res = $result->fetch_array();
			if($res['status'] == 1){
				foreach($res as $k => $v){
					$this->settings->set_userdata($k,$v);
				}
				$this->settings->set_userdata('login_type',2);
				$resp['status'] = 'success';
			}else{
				$resp['status'] = 'failed';
				$resp['msg'] = 'Your Account has been blocked.';
			}
		}else{
		$resp['status'] = 'failed';
		$resp['msg'] = 'Incorrect Email or Password';
		}
		if($this->conn->error){
			$resp['status'] = 'failed';
			$resp['_error'] = $this->conn->error;
		}
		return json_encode($resp);
	}
	public function logout_agent(){
		if($this->settings->sess_des()){
			redirect('agent');
		}
	}
}
$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);
$auth = new Login();
switch ($action) {
	case 'login':
		echo $auth->login();
		break;
	case 'logout':
		echo $auth->logout();
		break;
	case 'login_agent':
		echo $auth->login_agent();
		break;
	case 'logout_agent':
		echo $auth->logout_agent();
		break;
	default:
		echo $auth->index();
		break;
}
