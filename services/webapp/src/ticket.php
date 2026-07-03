<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

$user = require_login();
$id = (int)($_GET['id'] ?? 0);
$conn = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment'])) {
    $body = $_POST['comment'];

    // VULN: Stored XSS (Reto 1, vulnerabilidad #6). El comentario se guarda tal cual, sin sanitizar. Se
    // renderiza sin escapar más abajo, permitiendo inyectar HTML/JS que se ejecuta en el navegador de
    // cualquiera que vea el ticket — incluido el bot que simula al administrador (services/xss-bot),
    // cuya cookie de sesión contiene la flag de este reto.
    $stmt = $conn->prepare('INSERT INTO comments (ticket_id, author, body) VALUES (?, ?, ?)');
    $stmt->bind_param('iss', $id, $user['username'], $body);
    $stmt->execute();

    header('Location: /ticket?id=' . $id);
    exit;
}

$stmt = $conn->prepare('SELECT * FROM tickets WHERE id = ?');
$stmt->bind_param('i', $id);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();

$comments = [];
if ($ticket) {
    $stmt = $conn->prepare('SELECT author, body, created_at FROM comments WHERE ticket_id = ? ORDER BY id ASC');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

require __DIR__ . '/includes/header.php';
?>
<div class="card">
    <?php if (!$ticket): ?>
        <p class="error">Ticket no encontrado.</p>
    <?php else: ?>
        <h1><?= htmlspecialchars($ticket['subject']) ?></h1>
        <p><?= htmlspecialchars($ticket['description']) ?></p>

        <?php if ($ticket['attachment_filename']): ?>
            <p><a href="/attachment?file=<?= urlencode($ticket['attachment_filename']) ?>">Ver adjunto</a></p>
        <?php endif; ?>

        <h3>Comentarios</h3>
        <?php foreach ($comments as $c): ?>
            <div class="comment">
                <strong><?= htmlspecialchars($c['author']) ?>:</strong>
                <?php /* VULN: impresión sin htmlspecialchars() a propósito — ver comentario arriba. */ ?>
                <?= $c['body'] ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$comments): ?>
            <p>Sin comentarios todavía.</p>
        <?php endif; ?>

        <form method="post" action="/ticket?id=<?= (int)$id ?>">
            <textarea name="comment" rows="3" required placeholder="Escribe un comentario..."></textarea>
            <button type="submit">Comentar</button>
        </form>
    <?php endif; ?>
    <p><a href="/dashboard">Volver</a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
