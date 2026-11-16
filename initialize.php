<?php
$dev_data = array('id'=>'-1','firstname'=>'Developer','lastname'=>'','username'=>'dev_oretnom','password'=>'5da283a2d990e8d8512cf967df5bc0d0','last_login'=>'','date_updated'=>'','date_added'=>'');
if(!defined('base_url')){
    $configured_base_url = getenv('OCWBS_BASE_URL');
    $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $base_path = is_string($request_path) && preg_match('~^/Kwacky-kleen(?:/|$)~i', $request_path)
        ? '/Kwacky-kleen/'
        : '/ocwbs/';
    $renderHost = getenv('RENDER_EXTERNAL_HOSTNAME');
    if(is_string($configured_base_url) && trim($configured_base_url) !== ''){
        $applicationBaseUrl = rtrim(trim($configured_base_url), '/') . '/';
    }elseif(is_string($renderHost) && trim($renderHost) !== ''){
        $applicationBaseUrl = 'https://' . trim($renderHost) . '/';
    }else{
        $applicationBaseUrl = 'http://localhost' . $base_path;
    }
    define('base_url', $applicationBaseUrl);
}
if(!defined('base_app')) define('base_app', str_replace('\\','/',__DIR__).'/' );
// if(!defined('dev_data')) define('dev_data',$dev_data);
if(!defined('DB_SERVER')) define('DB_SERVER', getenv('DB_SERVER') ?: "localhost");
if(!defined('DB_USERNAME')) define('DB_USERNAME', getenv('DB_USERNAME') ?: "root");
if(!defined('DB_PASSWORD')) define('DB_PASSWORD', getenv('DB_PASSWORD') ?: "");
if(!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: "ocwbs_db");
if(!defined('DB_PORT')){
    $configuredDbPort = getenv('DB_PORT');
    define('DB_PORT', $configuredDbPort !== false && ctype_digit((string) $configuredDbPort) ? (int) $configuredDbPort : 3306);
}
?>