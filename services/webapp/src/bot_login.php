<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

// Endpoint interno usado únicamente por services/xss-bot (ver docs/context.md, decisión de diseño #6).
// Se autentica con un secreto compartido fuera de banda (BOT_SECRET) y NO con una contraseña de la
// tabla `users` a propósito: si usara una contraseña de esa tabla, crackearla en el Reto 2 permitiría
// saltarse por completo el Stored XSS del Reto 1.
$provided = $_SERVER['HTTP_X_BOT_SECRET'] ?? '';

if (BOT_SECRET === '' || !hash_equals(BOT_SECRET, $provided)) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

// El claim `flag` va embebido en el JWT del admin: cuando el Stored XSS exfiltra esta cookie, decodificar
// el JWT (sin necesidad de romper ninguna firma, ver jwt.php) revela FLAG_WEBAPP_XSS directamente.
issue_session_cookie([
    'sub' => 1,
    'username' => 'admin',
    'role' => 'admin',
    'flag' => FLAG_WEBAPP_XSS,
    'iat' => time(),
]);

echo json_encode(['ok' => true]);
