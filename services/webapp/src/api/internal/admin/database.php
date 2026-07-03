<?php
require_once __DIR__ . '/../../../auth.php';
require_once __DIR__ . '/../../../config.php';

header('Content-Type: application/json');

$user = current_user();

// VULN: JWT inseguro (Reto 1, vulnerabilidad #5b). Este chequeo confía en el claim `role` de un JWT
// cuya firma nunca se verifica (ver auth.php:current_user() -> jwt.php:jwt_decode_unverified()).
// Modificar el payload del propio JWT (role=user -> role=it/admin) basta para pasar este control.
if (!$user || !in_array($user['role'] ?? '', ['it', 'admin'], true)) {
    http_response_code(403);
    // La ruta real del endpoint se revela aquí a propósito (Reto 1, vulnerabilidad #4 —
    // descubrimiento de endpoint tras el IDOR en /profile).
    echo json_encode([
        'error' => 'Acceso denegado a /api/internal/admin/database: se requiere rol it o admin.',
    ]);
    exit;
}

// Filtra las credenciales reales que la propia Web App usa para conectarse a MariaDB (ver db.php /
// config.php). Son las mismas credenciales con las que el participante se conecta directamente a la
// Base de Datos en el Reto 2 (docker-compose.yml publica el puerto 3306).
echo json_encode([
    'db_host' => DB_HOST,
    'db_name' => DB_NAME,
    'db_user' => DB_APP_USER,
    'db_password' => DB_APP_PASSWORD,
]);
