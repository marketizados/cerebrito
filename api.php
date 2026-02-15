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

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db_name;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // En producción, no mostrar el mensaje de error completo por seguridad, pero útil para depurar ahora
    echo json_encode(["error" => "Error de conexión con la base de datos."]);
    exit;
}

// Obtener parámetros
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);
if ($input && isset($input['action'])) {
    $action = $input['action'];
}

// --- RUTAS DE LA API ---

if ($action === 'get_all') {
    try {
        // Verificar si las tablas existen
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
        $pdo->exec("DELETE FROM tasks");
        if (count($tasks) > 0) {
            $stmt = $pdo->prepare("INSERT INTO tasks (id, content, description, projectId, priority, date, completed) VALUES (:id, :content, :description, :projectId, :priority, :date, :completed)");
            foreach ($tasks as $task) {
                $stmt->execute([
                    ':id' => $task['id'],
                    ':content' => $task['content'],
                    ':description' => $task['description'] ?? '',
                    ':projectId' => $task['projectId'],
                    ':priority' => $task['priority'],
                    ':date' => $task['date'],
                    ':completed' => $task['completed'] ? 1 : 0
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
