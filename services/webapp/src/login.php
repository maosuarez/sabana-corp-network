<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

if (current_user()) {
    header('Location: /dashboard');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    // Login "legítimo": usa prepared statement a propósito. La SQLi del laboratorio vive únicamente en
    // search.php (Reto 1, vulnerabilidad #2) — no se duplica aquí para no introducir una vulnerabilidad
    // no planeada.
    $stmt = get_db()->prepare('SELECT id, username, role, password_md5 FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if ($row && hash_equals($row['password_md5'], md5($password))) {
        issue_session_cookie([
            'sub' => (int)$row['id'],
            'username' => $row['username'],
            'role' => $row['role'],
            'iat' => time(),
        ]);
        header('Location: /dashboard');
        exit;
    }

    $error = 'Usuario o contraseña incorrectos.';
}

require __DIR__ . '/includes/header.php';
?>
<div class="card">
    <h1>Sabana Corp Helpdesk</h1>
    <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post" action="/login">
        <label>Usuario
            <input type="text" name="username" required autofocus>
        </label>
        <label>Contraseña
            <input type="password" name="password" required>
        </label>
        <button type="submit">Iniciar sesión</button>
    </form>
</div>

<!--
    TODO(dev): quitar antes de pasar a producción — credenciales de prueba para QA
    usuario: jperez
    password: Bienvenido123
-->
<?php
// VULN: credenciales expuestas (Reto 1, vulnerabilidad #1). El comentario HTML de arriba, visible al
// inspeccionar el código fuente de esta página, contiene un usuario y contraseña de Empleado válidos.
require __DIR__ . '/includes/footer.php';
