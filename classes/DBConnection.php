<?php
if(!defined('DB_SERVER')){
    require_once("../initialize.php");
}
class DBConnection{

    private $host = DB_SERVER;
    private $username = DB_USERNAME;
    private $password = DB_PASSWORD;
    private $database = DB_NAME;
    
    public $conn;
    
    public function __construct(){

        if (!isset($this->conn)) {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            try{
                $this->conn = mysqli_init();
                $useSsl = strtolower((string) getenv('DB_SSL')) === 'true';
                if($useSsl){
                    $caFile = getenv('DB_SSL_CA');
                    if(!is_string($caFile) || trim($caFile) === ''){
                        $caFile = '/etc/ssl/certs/ca-certificates.crt';
                    }
                    if(!is_file($caFile) || !$this->conn->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, true) ||
                        !$this->conn->ssl_set(null, null, $caFile, null, null)){
                        throw new RuntimeException('The configured database TLS certificate could not be loaded.');
                    }
                }
                $this->conn->real_connect(
                    $this->host,
                    $this->username,
                    $this->password,
                    $this->database,
                    DB_PORT,
                    null,
                    $useSsl ? MYSQLI_CLIENT_SSL : 0
                );
                $this->conn->set_charset('utf8mb4');
                $this->conn->query("SET time_zone = '+08:00'");
            }catch(mysqli_sql_exception $error){
                error_log('Database connection failed: ' . $error->getMessage());
                throw new RuntimeException('Cannot connect to the database server.', 0, $error);
            }
        }    
        
    }
    public function __destruct(){
        $this->conn->close();
    }
}
?>