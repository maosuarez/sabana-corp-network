<?php
// Endpoint interno usado por services/xss-bot para marcar como visitado un item de la cola.
// Recibe ?id=<bot_visit_queue.id> via GET. Protegido por BOT_SECRET.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json');

$provided = $_SERVER['HTTP_X_BOT_SECRET'] ?? '';
if (BOT_SECRET === '' || !hash_equals(BOT_SECRET, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid id']);
    exit;
}

$stmt = get_db()->prepare('UPDATE bot_visit_queue SET visited = 1 WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();

echo json_encode(['ok' => true]);
