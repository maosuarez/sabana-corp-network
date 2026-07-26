<?php
require_once __DIR__ . '/../auth.php';
$__nav_user = current_user();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sabana Corp Helpdesk</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<nav class="topnav">
    <a href="/dashboard" class="brand">Sabana Corp Helpdesk</a>
    <?php if ($__nav_user): ?>
    <!-- Avatar de usuario visible tras iniciar sesión. Apunta al ID fijo de jperez (id=2 en el seed del
         Reto 2, ver services/database/init/01-seed.sql.template) a propósito: es la pista visual que
         invita al participante a fijarse en el parámetro ?id= de /profile y a manipularlo — la
         superficie del IDOR (Reto 1, vulnerabilidad #3, ver profile.php). No es en sí una línea
         vulnerable, solo un enlace; la lógica insegura vive únicamente en profile.php. -->
    <a href="/profile?id=2" class="nav-avatar" title="Ver perfil">👤 <?= htmlspecialchars($__nav_user['username'] ?? '') ?></a>
    <?php endif; ?>
</nav>
<main>
