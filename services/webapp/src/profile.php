<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$viewer = require_login();
$id = (int)($_GET['id'] ?? $viewer['sub']);

// VULN: IDOR (Reto 1, vulnerabilidad #3). No se verifica que $id pertenezca al usuario autenticado —
// cualquier usuario logueado puede ver el perfil de cualquier otro cambiando este parámetro. Así es
// como se llega al perfil del usuario de TI descubierto vía SQLi en /search.
$stmt = get_db()->prepare('SELECT id, username, full_name, email, role FROM users WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();

require __DIR__ . '/includes/header.php';
?>
<div class="card">
    <?php if (!$profile): ?>
        <p class="error">Usuario no encontrado.</p>
    <?php else: ?>
        <h1><?= htmlspecialchars($profile['full_name']) ?></h1>
        <p>Usuario: <?= htmlspecialchars($profile['username']) ?></p>
        <p>Correo: <?= htmlspecialchars($profile['email']) ?></p>
        <p>Rol: <?= htmlspecialchars($profile['role']) ?></p>

        <?php if (in_array($profile['role'], ['it', 'admin'], true)): ?>
        <div class="internal-tools">
            <h3>Herramientas internas de TI</h3>
            <p>Este perfil tiene acceso a paneles internos reservados a personal de TI.</p>
            <a class="btn" href="/api/internal/admin/database">Panel de administración de base de datos</a>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    <p><a href="/dashboard">Volver</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
