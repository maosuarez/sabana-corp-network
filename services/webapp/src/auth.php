<?php
require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/config.php';

function issue_session_cookie(array $payload): void
{
    $token = jwt_encode($payload, JWT_SIGNING_SECRET);

    // VULN: cookie de sesión sin HttpOnly (Reto 1, vulnerabilidad #6 - Stored XSS). Se hace a propósito
    // para que un payload XSS almacenado pueda leer esta cookie vía document.cookie y exfiltrarla.
    setcookie('session', $token, [
        'expires' => time() + 3600,
        'path' => '/',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

function current_user(): ?array
{
    if (!isset($_COOKIE['session'])) {
        return null;
    }

    // VULN: JWT inseguro (Reto 1, vulnerabilidad #5b) — se confía en el payload sin verificar la firma.
    // Ver jwt.php:jwt_decode_unverified().
    return jwt_decode_unverified($_COOKIE['session']);
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: /login');
        exit;
    }
    return $user;
}
