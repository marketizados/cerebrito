<?php
// Configuración de cabeceras CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// --- CREDENCIALES ---
$host = 'localhost';
$db_name = 'marketizados_cerebrito'; 
$username = 'marketizados_cerebrito';
$password = 'Barcelona4040+++';

// --- SMTP CONFIG ---
define('SMTP_HOST', 'smtp.acumbamail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'jose.sinfreu@gmail.com');
define('SMTP_PASS', 'doki789hbeedt4657mnopopafa87b1ea');
define('SMTP_FROM', 'jose.sinfreu@gmail.com');
define('SMTP_FROM_NAME', 'Cerebrito App');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // --- AUTO-MIGRACIÓN: Crear tablas si no existen ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(191) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id BIGINT PRIMARY KEY,
        user_id INT NOT NULL,
        content TEXT,
        description TEXT,
        projectId VARCHAR(50),
        priority INT,
        date VARCHAR(50),
        completed TINYINT(1),
        duration INT,
        assignedTo VARCHAR(50),
        INDEX(user_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS projects (
        id VARCHAR(50),
        user_id INT NOT NULL,
        name VARCHAR(100),
        color VARCHAR(50),
        clientId VARCHAR(50),
        INDEX(user_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS notes (
        id BIGINT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(255),
        content TEXT,
        date VARCHAR(50),
        INDEX(user_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS clients (
        id VARCHAR(50),
        user_id INT NOT NULL,
        name VARCHAR(255),
        email VARCHAR(100),
        phone VARCHAR(50),
        dni VARCHAR(50),
        address TEXT,
        notes TEXT,
        INDEX(user_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS subscriptions (
        id VARCHAR(50),
        user_id INT NOT NULL,
        name VARCHAR(255),
        description TEXT,
        price DECIMAL(10,2),
        cycle VARCHAR(20),
        clientId VARCHAR(50),
        nextPayment VARCHAR(50),
        status VARCHAR(20),
        alerted TINYINT(1),
        INDEX(user_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS collaborators (
        id VARCHAR(50),
        user_id INT NOT NULL,
        name VARCHAR(255),
        role VARCHAR(100),
        email VARCHAR(100),
        INDEX(user_id)
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        user_id INT NOT NULL,
        type VARCHAR(50),
        data_json TEXT,
        PRIMARY KEY (user_id, type)
    )");

} catch(PDOException $e) {
    echo json_encode(["error" => "Error DB: " . $e->getMessage()]);
    exit;
}

// Helpers
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? ($input['action'] ?? '');

// --- AUTH ---

if ($action === 'login' || $action === 'register') {
    $email = $input['email'] ?? '';
    $pass = $input['password'] ?? '';

    if (!$email || !$pass) { echo json_encode(["error" => "Faltan datos"]); exit; }

    if ($action === 'register') {
        // Check exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) { echo json_encode(["error" => "El usuario ya existe"]); exit; }

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("INSERT INTO users (email, password) VALUES (?, ?)");
        if ($stmt->execute([$email, $hash])) {
            echo json_encode(["success" => true, "userId" => $pdo->lastInsertId()]);
        } else {
            echo json_encode(["error" => "Error al registrar"]);
        }
    } else {
        // Login
        $stmt = $pdo->prepare("SELECT id, password FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($pass, $user['password'])) {
            echo json_encode(["success" => true, "userId" => $user['id']]);
        } else {
            echo json_encode(["error" => "Credenciales inválidas"]);
        }
    }
    exit;
}

// --- DATA ACTIONS (REQUIRE USER_ID) ---
$userId = $input['userId'] ?? $_GET['userId'] ?? null;
if (!$userId) { echo json_encode(["error" => "No autorizado"]); exit; }

if ($action === 'get_all_data') {
    $data = [];
    
    // Fetch Tables
    $tables = ['tasks', 'projects', 'notes', 'clients', 'subscriptions', 'collaborators'];
    foreach($tables as $t) {
        $stmt = $pdo->prepare("SELECT * FROM $t WHERE user_id = ?");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cast types
        if($t === 'tasks' || $t === 'subscriptions') {
            foreach($rows as &$r) { 
                if(isset($r['completed'])) $r['completed'] = (bool)$r['completed']; 
                if(isset($r['alerted'])) $r['alerted'] = (bool)$r['alerted']; 
                if(isset($r['priority'])) $r['priority'] = (int)$r['priority']; 
                if(isset($r['id']) && is_numeric($r['id'])) $r['id'] = (int)$r['id']; // tasks use int ID sometimes
            }
        }
        $data[$t] = $rows;
    }

    // Fetch Settings (JSON)
    $stmt = $pdo->prepare("SELECT type, data_json FROM settings WHERE user_id = ?");
    $stmt->execute([$userId]);
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    foreach($settings as $k => $v) {
        $data[$k] = json_decode($v, true);
    }

    echo json_encode($data);
    exit;
}

// GENERIC SAVE HANDLER
if (strpos($action, 'save_') === 0) {
    $type = str_replace('save_', '', $action);
    $items = $input['data'] ?? [];

    $pdo->beginTransaction();
    try {
        if (in_array($type, ['priorities', 'theme', 'timer', 'notifications'])) {
            // Save as JSON in settings
            $stmt = $pdo->prepare("REPLACE INTO settings (user_id, type, data_json) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $type, json_encode($items)]);
        } else {
            // Save as Rows (Full Sync Strategy per user)
            // 1. Delete user's data for this table
            $pdo->prepare("DELETE FROM $type WHERE user_id = ?")->execute([$userId]);
            
            // 2. Insert new
            if (!empty($items)) {
                $columns = array_keys($items[0]);
                // Filter out non-db keys if any (user_id is handled)
                $placeholders = array_map(function($k) { return ":$k"; }, $columns);
                $colNames = implode(", ", $columns);
                $phNames = implode(", ", $placeholders);
                
                $sql = "INSERT INTO $type (user_id, $colNames) VALUES (:uid, $phNames)";
                $stmt = $pdo->prepare($sql);

                foreach($items as $item) {
                    $params = [':uid' => $userId];
                    foreach($item as $k => $v) {
                        // Boolean fix
                        if(is_bool($v)) $v = $v ? 1 : 0;
                        $params[":$k"] = $v;
                    }
                    $stmt->execute($params);
                }
            }
        }
        $pdo->commit();
        echo json_encode(["success" => true]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(["error" => $e->getMessage()]);
    }
    exit;
}

// --- EMAILS (User ID not strictly required for sending but good for logging) ---
class SimpleSMTP {
    private $socket;
    public function send($to, $subject, $body) {
        $this->socket = fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 30);
        if (!$this->socket) return ["success" => false, "error" => $errstr];
        $this->read();
        $this->cmd("EHLO " . $_SERVER['SERVER_NAME']);
        $this->cmd("STARTTLS");
        stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $this->cmd("EHLO " . $_SERVER['SERVER_NAME']);
        $this->cmd("AUTH LOGIN");
        $this->cmd(base64_encode(SMTP_USER));
        $this->cmd(base64_encode(SMTP_PASS));
        $this->cmd("MAIL FROM: <" . SMTP_FROM . ">");
        $this->cmd("RCPT TO: <$to>");
        $this->cmd("DATA");
        $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\nTo: <$to>\r\nSubject: $subject\r\n";
        $this->cmd("$headers\r\n$body\r\n.\r\n", false);
        $this->cmd("QUIT");
        fclose($this->socket);
        return ["success" => true];
    }
    private function cmd($c, $ck=true) { fputs($this->socket, $c . "\r\n"); if($ck) return $this->read(); }
    private function read() { $r=""; while($s=fgets($this->socket,515)){$r.=$s; if(substr($s,3,1)==" ")break;} return $r; }
}

if ($action === 'send_email' || $action === 'send_subscription_email') {
    $data = $input['data'] ?? [];
    $to = $data['to'] ?? '';
    if (!$to) { echo json_encode(["error" => "No email"]); exit; }
    
    $subject = "Notificación Cerebrito";
    $body = "";

    if ($action === 'send_email') {
        $task = $data['task'] ?? [];
        $subject = "Nueva Tarea: " . ($task['content'] ?? '');
        $body = "<div style='font-family:sans-serif;padding:20px;border:1px solid #ddd;border-radius:8px;'>
            <h2 style='color:#ea580c'>🧠 Nueva Tarea Asignada</h2>
            <h3>{$task['content']}</h3>
            <p>" . ($task['description'] ?? '') . "</p>
            <p><strong>Fecha:</strong> {$task['date']}</p>
        </div>";
    } else {
        $sub = $data['subscription'] ?? [];
        $subject = "Renovación: " . ($sub['name'] ?? '');
        $body = "<div style='font-family:sans-serif;padding:20px;border:1px solid #ddd;border-radius:8px;'>
            <h2 style='color:#ea580c'>🔄 Recordatorio de Suscripción</h2>
            <h3>Renovar: {$sub['name']}</h3>
            <p>Precio: {$sub['price']}€</p>
            <p><strong>Fecha de cobro:</strong> " . date("d/m/Y H:i", strtotime($sub['nextPayment'])) . "</p>
        </div>";
    }

    $smtp = new SimpleSMTP();
    echo json_encode($smtp->send($to, $subject, $body));
    exit;
}
?>
