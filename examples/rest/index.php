<?php
/**
 * Ejemplo de uso de RESTAdapter con DB::grid() y toGridFormat()
 * 
 * Este ejemplo demuestra cómo:
 * 1. DB::grid() ejecuta consultas con PDO::FETCH_NUM (máximo rendimiento)
 * 2. QueryResponse.toGridFormat() mantiene el formato numérico
 * 3. RESTAdapter transforma a asociativo SOLO cuando se proporcionan nombres de columnas
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use RapidBase\Core\DB;
use RapidBase\Infrastructure\Ui\Adapter\RESTAdapter;

// Configurar conexión (ejemplo con SQLite en memoria)
DB::setup('sqlite::memory:', '', '', 'main');

// Crear tabla de ejemplo y datos
DB::exec("CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    created_at TEXT NOT NULL
)");

// Insertar datos de ejemplo
$users = [
    [1, 'Alice Johnson', 'alice@example.com', '2024-01-15'],
    [2, 'Bob Smith', 'bob@example.com', '2024-02-20'],
    [3, 'Charlie Brown', 'charlie@example.com', '2024-03-10'],
    [4, 'Diana Prince', 'diana@example.com', '2024-04-05'],
    [5, 'Eve Adams', 'eve@example.com', '2024-05-12'],
];

foreach ($users as $user) {
    DB::exec("INSERT INTO users (id, name, email, created_at) VALUES (?, ?, ?, ?)", $user);
}

// Obtener respuesta usando RESTAdapter con transformación a asociativo
$columnNames = ['id', 'name', 'email', 'created_at'];
$response = DB::grid('users', [], 1, []);
$adapter = new RESTAdapter($response, [], $columnNames);
$apiResponse = $adapter->handle();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RapidBase REST Adapter - FETCH_NUM Demo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; color: #333; line-height: 1.6; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        h1 { margin-bottom: 20px; color: #2c3e50; }
        .info-box { background: #e8f4fd; padding: 20px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #3498db; }
        .info-box h3 { color: #2980b9; margin-bottom: 10px; }
        .info-box code { background: #fff; padding: 2px 6px; border-radius: 3px; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .panel { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .panel h2 { margin-bottom: 15px; font-size: 18px; color: #2c3e50; border-bottom: 2px solid #ecf0f1; padding-bottom: 10px; }
        textarea { width: 100%; height: 400px; font-family: 'Courier New', monospace; font-size: 13px; padding: 15px; border: 1px solid #ddd; border-radius: 4px; resize: vertical; background: #2d2d2d; color: #f8f8f2; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 14px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; font-weight: 600; color: #495057; }
        tr:hover { background: #f8f9fa; }
        .flow-diagram { background: #fff; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .flow-step { display: flex; align-items: center; margin: 10px 0; padding: 10px; background: #f8f9fa; border-radius: 4px; }
        .flow-step .step-num { background: #3498db; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-right: 15px; font-weight: bold; }
        .flow-step .step-content { flex: 1; }
        .flow-step .step-code { background: #2d2d2d; color: #f8f8f2; padding: 5px 10px; border-radius: 3px; font-family: monospace; font-size: 12px; }
        .arrow { color: #3498db; font-size: 20px; margin: 5px 0; text-align: center; }
        .highlight { color: #e74c3c; font-weight: bold; }
        .success { color: #27ae60; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <h1>REST Adapter - Demostración FETCH_NUM</h1>
        
        <div class="info-box">
            <h3>📌 Importante: Uso de FETCH_NUM para Máximo Rendimiento</h3>
            <p>Este ejemplo demuestra que <code>DB::grid()</code> utiliza <span class="highlight">PDO::FETCH_NUM</span> (índices numéricos) en lugar de <code>FETCH_ASSOC</code>, lo que reduce significativamente el uso de memoria.</p>
            <p style="margin-top: 10px;"><strong>Flujo:</strong> <code>DB::grid()</code> → <code>QueryResponse</code> → <code>toGridFormat()</code> → <code>RESTAdapter</code> → JSON Asociativo (solo al final)</p>
        </div>

        <div class="flow-diagram">
            <h3 style="margin-bottom: 15px;">Flujo de Datos</h3>
            <div class="flow-step">
                <div class="step-num">1</div>
                <div class="step-content">
                    <strong>DB::grid()</strong> ejecuta con <span class="highlight">PDO::FETCH_NUM</span>
                    <br><span class="step-code">[[1, "Alice", "alice@example.com", "2024-01-15"], [2, "Bob", ...]]</span>
                </div>
            </div>
            <div class="arrow">↓</div>
            <div class="flow-step">
                <div class="step-num">2</div>
                <div class="step-content">
                    <strong>QueryResponse->data</strong> almacena formato numérico
                    <br><span class="step-code">$response->data = [[1, "Alice", ...], ...]</span>
                </div>
            </div>
            <div class="arrow">↓</div>
            <div class="flow-step">
                <div class="step-num">3</div>
                <div class="step-content">
                    <strong>toGridFormat()</strong> mantiene formato numérico
                    <br><span class="step-code">["data" => [[1, "Alice", ...], ...]]</span>
                </div>
            </div>
            <div class="arrow">↓</div>
            <div class="flow-step">
                <div class="step-num">4</div>
                <div class="step-content">
                    <strong>RESTAdapter</strong> transforma a asociativo <span class="success">SOLO si se indican columnas</span>
                    <br><span class="step-code">new RESTAdapter($response, [], ["id", "name", "email", "created_at"])</span>
                </div>
            </div>
            <div class="arrow">↓</div>
            <div class="flow-step">
                <div class="step-num">5</div>
                <div class="step-content">
                    <strong>Salida JSON</strong> para API REST
                    <br><span class="step-code">{"data": [{"id": 1, "name": "Alice", ...}], "meta": {...}}</span>
                </div>
            </div>
        </div>

        <div class="grid">
            <div class="panel">
                <h2>📊 Vista de Datos (Tabla)</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($apiResponse['data'] as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['id']) ?></td>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= htmlspecialchars($row['email']) ?></td>
                            <td><?= htmlspecialchars($row['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="panel">
                <h2>📄 Salida JSON de API REST</h2>
                <textarea readonly><?php echo json_encode($apiResponse, JSON_PRETTY_PRINT); ?></textarea>
            </div>
        </div>
    </div>
</body>
</html>
