<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$user = require_login();

$stmt = get_db()->prepare(
    'SELECT id, subject, status FROM tickets WHERE created_by = ? OR assigned_to = ? ORDER BY id DESC'
);
$stmt->bind_param('ii', $user['sub'], $user['sub']);
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

require __DIR__ . '/includes/header.php';
?>
<div class="card">
    <h1>Mis tickets</h1>
    <form method="get" action="/search" class="search-form">
        <input type="text" name="q" placeholder="Buscar tickets...">
        <button type="submit">Buscar</button>
    </form>
    <table>
        <tr><th>ID</th><th>Asunto</th><th>Estado</th></tr>
        <?php foreach ($tickets as $t): ?>
        <tr>
            <td><?= (int)$t['id'] ?></td>
            <td><a href="/ticket?id=<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['subject']) ?></a></td>
            <td><?= htmlspecialchars($t['status']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$tickets): ?>
        <tr><td colspan="3">No tienes tickets todavía.</td></tr>
        <?php endif; ?>
    </table>
    <p>
        <a href="/profile?id=<?= (int)$user['sub'] ?>">Mi perfil</a>
        · <a href="/logout">Cerrar sesión</a>
    </p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
