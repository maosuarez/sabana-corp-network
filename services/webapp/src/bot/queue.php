<?php
// Endpoint interno consumido únicamente por services/xss-bot (ver docs/context.md, decisión #6).
// Devuelve los tickets pendientes de visita (visited=0) en la cola del bot.
// Protegido por BOT_SECRET: el mismo secreto que bot_login.php.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$provided = $_SERVER['HTTP_X_BOT_SECRET'] ?? '';
if (BOT_SECRET === '' || !hash_equals(BOT_SECRET, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$result = get_db()->query(
    'SELECT id, ticket_id FROM bot_visit_queue WHERE visited = 0 ORDER BY id ASC'
);
$rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

echo json_encode($rows);
