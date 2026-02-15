<?php
// Evitar que errores menores rompan el JSON
error_reporting(0); 
ini_set('display_errors', 0);

// Configuración de CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// --- TUS CREDENCIALES ---
$host = 'localhost';
$db   = 'marketizados_cerebrito';
$user = 'marketizados_cerebrito';
$pass = 'Barcelona4040+++';
$charset = 'utf8mb4';

// SMTP Acumbamail
define('SMTP_HOST', 'smtp.acumbamail.com');
define('SMTP_PORT', 587); 
define('SMTP_USER', 'jose.sinfreu@gmail.com');
define('SMTP_PASS', 'doki789hbeedt4657mnopopafa87b1ea'); 
define('SMTP_FROM', 'jose.sinfreu@gmail.com');
define('SMTP_FROM_NAME', 'Cerebrito App');

// --- CONEXIÓN DB ---
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()]);
    exit;
}

// --- FUNCIÓN DE INICIALIZACIÓN DE TABLAS ---
function initTables($pdo) {
    $sql1 = "CREATE TABLE IF NOT EXISTS auth_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        code VARCHAR(6) NOT NULL,
        expires_at DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql1);

    $sql2 = "CREATE TABLE IF NOT EXISTS app_storage (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(255) NOT NULL,
        data_key VARCHAR(50) NOT NULL,
        data_value LONGTEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_key (user_email, data_key)
    )";
    $pdo->exec($sql2);
}

// Inicializar tablas en cada carga (seguro y rápido)
initTables($pdo);

// --- CLASE SMTP ---
class SimpleSMTP {
    private $sock;
    public function send($to, $subject, $body) {
        try {
            $this->sock = @fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 15);
            if (!$this->sock) return false;

            $this->cmd(null, "220");
            $this->cmd("EHLO " . $_SERVER['HTTP_HOST'], "250");
            $this->cmd("STARTTLS", "220");
            stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->cmd("EHLO " . $_SERVER['HTTP_HOST'], "250");
            $this->cmd("AUTH LOGIN", "334");
            $this->cmd(base64_encode(SMTP_USER), "334");
            $this->cmd(base64_encode(SMTP_PASS), "235");
            $this->cmd("MAIL FROM: <" . SMTP_FROM . ">", "250");
            $this->cmd("RCPT TO: <$to>", "250");
            $this->cmd("DATA", "354");
            
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n";
            $headers .= "To: $to\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            
            fwrite($this->sock, "$headers\r\n$body\r\n.\r\n");
            $this->read("250");
            $this->cmd("QUIT", "221");
            fclose($this->sock);
            return true;
        } catch (Exception $e) { return false; }
    }
    private function cmd($c, $e) { if($c) fwrite($this->sock, "$c\r\n"); $this->read($e); }
    private function read($e) { while($s=fgets($this->sock,515)){ if(substr($s,3,1)==" ")break; } }
}

// --- ENDPOINTS ---
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

if ($method === 'POST' && $action === 'request_code') {
    $email = $input['email'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status'=>'error', 'message'=>'Email inválido']); exit;
    }
    $code = rand(100000, 999999);
    $stmt = $pdo->prepare("INSERT INTO auth_codes (email, code, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE))");
    $stmt->execute([$email, $code]);

    $smtp = new SimpleSMTP();
    $body = "<div style='font-family:sans-serif;padding:20px;background:#f3f4f6;text-align:center;'><h2>Cerebrito Login</h2><h1>$code</h1></div>";
    
    if ($smtp->send($email, "Codigo de acceso", $body)) echo json_encode(['status'=>'success']);
    else echo json_encode(['status'=>'error', 'message'=>'Error SMTP']);
    exit;
}

if ($method === 'POST' && $action === 'verify_code') {
    $stmt = $pdo->prepare("SELECT id FROM auth_codes WHERE email=? AND code=? AND expires_at > NOW()");
    $stmt->execute([$input['email'], $input['code']]);
    if ($stmt->fetch()) {
        $pdo->prepare("DELETE FROM auth_codes WHERE email=?")->execute([$input['email']]);
        echo json_encode(['status'=>'success', 'email'=>$input['email']]);
    } else echo json_encode(['status'=>'error', 'message'=>'Código inválido']);
    exit;
}

if ($method === 'GET' && $action === 'load') {
    $email = $_GET['email'] ?? '';
    if (!$email) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("SELECT data_key, data_value FROM app_storage WHERE user_email = ?");
    $stmt->execute([$email]);
    $res = [];
    foreach ($stmt->fetchAll() as $r) {
        $k = str_replace('tm_', '', $r['data_key']);
        $res[$k] = json_decode($r['data_value']);
    }
    echo json_encode($res);
    exit;
}

if ($method === 'POST' && $action === '') {
    if (isset($input['key'], $input['value'], $input['email'])) {
        $val = is_string($input['value']) ? $input['value'] : json_encode($input['value']);
        $stmt = $pdo->prepare("INSERT INTO app_storage (user_email, data_key, data_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data_value = ?");
        $stmt->execute([$input['email'], $input['key'], $val, $val]);
        echo json_encode(['status'=>'success']);
    }
    exit;
}
?>
