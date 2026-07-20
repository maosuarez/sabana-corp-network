<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$user = require_login();
$q = $_GET['q'] ?? '';
$conn = get_db();

// VULN: SQL Injection (Reto 1, vulnerabilidad #2). $q se concatena directamente en la consulta sin
// prepared statement ni sanitización. Permite UNION-based SQLi para leer datos fuera del alcance del
// usuario autenticado — por ejemplo, tickets asignados a personal de TI (y su ID de usuario), que es la
// pista que hace avanzar al siguiente paso de la cadena (IDOR en /profile).
$sql = "SELECT id, subject, status, assigned_to FROM tickets "
     . "WHERE created_by = {$user['sub']} AND subject LIKE '%$q%'";
$result = mysqli_query($conn, $sql);

$rows = [];
if ($result instanceof mysqli_result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $rows[] = $row;
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="card">
    <h1>Buscar tickets</h1>
    <form method="get" action="/search" class="search-form">
        <input type="text" name="q" value="<?= htmlspecialchars($q) ?>">
        <button type="submit">Buscar</button>
    </form>

    <?php if (!$result): ?>
        <p class="error">Error en la consulta: <?= mysqli_error($conn) ?></p>
    <?php endif; ?>

    <table>
        <tr><th>ID</th><th>Asunto</th><th>Estado</th><th>Asignado a (ID)</th></tr>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td><?= htmlspecialchars((string)$r['id']) ?></td>
            <td><?= htmlspecialchars((string)$r['subject']) ?></td>
            <td><?= htmlspecialchars((string)$r['status']) ?></td>
            <td><?= htmlspecialchars((string)($r['assigned_to'] ?? '')) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <p><a href="/dashboard">Volver</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
