<?php
// Configuración de cabeceras para permitir peticiones JSON y CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json; charset=UTF-8");

// Manejo de pre-flight request (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// --- CONFIGURACIÓN DE LA BASE DE DATOS (CREDENTIALS) ---
$host = 'localhost';
$db_name = 'marketizados_cerebrito'; 
$username = 'marketizados_cerebrito';
$password = 'Barcelona8080+++';

// --- CONFIGURACIÓN SMTP ACUMBAMAIL ---
define('SMTP_HOST', 'smtp.acumbamail.com');
define('SMTP_PORT', 587); // Puerto estándar con STARTTLS
define('SMTP_USER', 'jose.sinfreu@gmail.com');
define('SMTP_PASS', 'doki789hbeedt4657mnopopafa87b1ea'); 
define('SMTP_FROM', 'jose.sinfreu@gmail.com'); // Remitente (debe coincidir con el usuario usualmente)
define('SMTP_FROM_NAME', 'Cerebrito App');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // En producción, no mostrar el mensaje de error completo por seguridad
    // echo json_encode(["error" => "Error de conexión con la base de datos."]);
    // exit;
    // Si falla la DB, permitimos continuar si la acción es solo enviar email (opcional)
}

// Obtener parámetros
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
if ($input && isset($input['action'])) {
    $action = $input['action'];
}

// --- CLASE SIMPLE PARA SMTP (SIN LIBRERÍAS EXTERNAS) ---
class SimpleSMTP {
    private $socket;

    public function send($to, $subject, $body) {
        // Conectar al socket
        $this->socket = fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 30);
        if (!$this->socket) return ["success" => false, "error" => "Connection failed: $errstr"];

        $this->read(); // Welcome msg
        
        $this->cmd("EHLO " . $_SERVER['SERVER_NAME']);
        $this->cmd("STARTTLS");
        stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $this->cmd("EHLO " . $_SERVER['SERVER_NAME']); // Resend EHLO after TLS

        // Auth
        $this->cmd("AUTH LOGIN");
        $this->cmd(base64_encode(SMTP_USER));
        $this->cmd(base64_encode(SMTP_PASS));

        // Mail Data
        $this->cmd("MAIL FROM: <" . SMTP_FROM . ">");
        $this->cmd("RCPT TO: <$to>");
        $this->cmd("DATA");

        // Headers & Content
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: " . SMTP_FROM_NAME . " <" . SMTP_FROM . ">\r\n";
        $headers .= "To: <$to>\r\n";
        $headers .= "Subject: $subject\r\n";

        $message = "$headers\r\n$body\r\n.\r\n";
        $this->cmd($message, false); // Send data, wait for OK

        $this->cmd("QUIT");
        fclose($this->socket);

        return ["success" => true];
    }

    private function cmd($cmd, $check = true) {
        fputs($this->socket, $cmd . "\r\n");
        if ($check) return $this->read();
    }

    private function read() {
        $response = "";
        while ($str = fgets($this->socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) == " ") break;
        }
        return $response;
    }
}

// --- RUTAS DE LA API ---

if ($action === 'send_email') {
    // Validar datos de entrada
    $data = $input['data'] ?? [];
    $to = $data['to'] ?? '';
    $task = $data['task'] ?? [];

    if (!$to || empty($task)) {
        echo json_encode(["success" => false, "error" => "Missing email or task data"]);
        exit;
    }

    $subject = "Nueva Tarea: " . ($task['content'] ?? 'Sin título');
    
    // Plantilla HTML básica
    $body = "
    <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; border-radius: 10px; max-width: 600px;'>
        <h2 style='color: #ea580c;'>🧠 Cerebrito - Nueva Tarea</h2>
        <p>Has registrado una nueva actividad:</p>
        <hr style='border: 0; border-top: 1px solid #eee;'>
        <h3 style='margin-bottom: 5px;'>{$task['content']}</h3>
        <p style='color: #666; font-style: italic;'>" . ($task['description'] ?? 'Sin descripción') . "</p>
        <br>
        <p><strong>📅 Fecha:</strong> " . ($task['date'] ?? 'No especificada') . "</p>
        <p><strong>⏱️ Duración estimada:</strong> " . ($task['duration'] ? $task['duration'] . ' min' : 'No especificada') . "</p>
        <br>
        <p style='font-size: 12px; color: #999;'>Mensaje enviado automáticamente desde tu Gestor de Tareas.</p>
    </div>
    ";

    $smtp = new SimpleSMTP();
    $result = $smtp->send($to, $subject, $body);
    echo json_encode($result);
    exit;
}

elseif ($action === 'send_subscription_email') {
    // Validar datos de entrada
    $data = $input['data'] ?? [];
    $to = $data['to'] ?? '';
    $sub = $data['subscription'] ?? [];

    if (!$to || empty($sub)) {
        echo json_encode(["success" => false, "error" => "Missing email or subscription data"]);
        exit;
    }

    $subject = "🔔 Recordatorio de Renovación: " . ($sub['name'] ?? 'Servicio');
    
    // Plantilla HTML para Suscripción
    $body = "
    <div style='font-family: Arial, sans-serif; padding: 20px; border: 1px solid #ddd; border-radius: 10px; max-width: 600px;'>
        <h2 style='color: #ea580c;'>🧠 Recordatorio de Suscripción</h2>
        <p>Hola,</p>
        <p>Te recordamos que se acerca la fecha de renovación para el siguiente servicio:</p>
        <hr style='border: 0; border-top: 1px solid #eee;'>
        <h3 style='margin-bottom: 5px;'>{$sub['name']}</h3>
        <p style='color: #666; font-style: italic;'>" . ($sub['desc'] ?? '') . "</p>
        <br>
        <p><strong>💰 Precio:</strong> {$sub['price']} €</p>
        <p><strong>🔄 Ciclo:</strong> " . ($sub['cycle'] === 'monthly' ? 'Mensual' : 'Anual') . "</p>
        <p><strong>📅 Fecha de Renovación:</strong> " . date("d M Y H:i", strtotime($sub['nextPayment'])) . "</p>
        <br>
        <p style='font-size: 12px; color: #999;'>Mensaje enviado automáticamente porque esta suscripción está marcada como ACTIVA.</p>
    </div>
    ";

    $smtp = new SimpleSMTP();
    $result = $smtp->send($to, $subject, $body);
    echo json_encode($result);
    exit;
}

elseif ($action === 'get_all') {
    try {
        // Verificar si las tablas existen (simple check)
        $tables = $pdo->query("SHOW TABLES LIKE 'tasks'")->fetchAll();
        if (count($tables) == 0) {
            echo json_encode(["tasks" => [], "projects" => [], "notes" => []]);
            exit;
        }

        $tasks = $pdo->query("SELECT * FROM tasks")->fetchAll(PDO::FETCH_ASSOC);
        foreach($tasks as &$t) { 
            $t['completed'] = (bool)$t['completed']; 
            $t['id'] = (int)$t['id'];
            $t['priority'] = (int)$t['priority'];
            // Aseguramos que la duración sea un número o null
            $t['duration'] = isset($t['duration']) ? (int)$t['duration'] : null;
        }

        $projects = $pdo->query("SELECT * FROM projects")->fetchAll(PDO::FETCH_ASSOC);
        $notes = $pdo->query("SELECT * FROM notes")->fetchAll(PDO::FETCH_ASSOC);
        foreach($notes as &$n) { $n['id'] = (int)$n['id']; }

        echo json_encode([
            "tasks" => $tasks,
            "projects" => $projects,
            "notes" => $notes
        ]);
    } catch(PDOException $e) {
        echo json_encode(["error" => $e->getMessage()]);
    }
} 
elseif ($action === 'save_tasks') {
    $tasks = $input['data'];
    try {
        $pdo->beginTransaction();
        // Borramos todo e insertamos de nuevo (estrategia simple de sincronización)
        $pdo->exec("DELETE FROM tasks");
        
        if (count($tasks) > 0) {
            // UPDATED: Añadido campo 'duration' a la consulta SQL
            $stmt = $pdo->prepare("INSERT INTO tasks (id, content, description, projectId, priority, date, completed, duration) VALUES (:id, :content, :description, :projectId, :priority, :date, :completed, :duration)");
            
            foreach ($tasks as $task) {
                $stmt->execute([
                    ':id' => $task['id'],
                    ':content' => $task['content'],
                    ':description' => $task['description'] ?? '',
                    ':projectId' => $task['projectId'],
                    ':priority' => $task['priority'],
                    ':date' => $task['date'],
                    ':completed' => $task['completed'] ? 1 : 0,
                    ':duration' => $task['duration'] ?? null // UPDATED: Guardar duración
                ]);
            }
        }
        $pdo->commit();
        echo json_encode(["success" => true]);
    } catch(Exception $e) {
        $pdo->rollBack();
        echo json_encode(["error" => $e->getMessage()]);
    }
}
elseif ($action === 'save_projects') {
    $projects = $input['data'];
    try {
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM projects");
        if (count($projects) > 0) {
            $stmt = $pdo->prepare("INSERT INTO projects (id, name, color) VALUES (:id, :name, :color)");
            foreach ($projects as $proj) {
                $stmt->execute([':id' => $proj['id'], ':name' => $proj['name'], ':color' => $proj['color']]);
            }
        }
        $pdo->commit();
        echo json_encode(["success" => true]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(["error" => $e->getMessage()]); }
}
elseif ($action === 'save_notes') {
    $notes = $input['data'];
    try {
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM notes");
        if (count($notes) > 0) {
            $stmt = $pdo->prepare("INSERT INTO notes (id, title, content, date) VALUES (:id, :title, :content, :date)");
            foreach ($notes as $note) {
                $stmt->execute([':id' => $note['id'], ':title' => $note['title'], ':content' => $note['content'], ':date' => $note['date']]);
            }
        }
        $pdo->commit();
        echo json_encode(["success" => true]);
    } catch(Exception $e) { $pdo->rollBack(); echo json_encode(["error" => $e->getMessage()]); }
}
?>
