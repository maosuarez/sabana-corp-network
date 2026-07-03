<?php
require_once __DIR__ . '/auth.php';

header('Location: ' . (current_user() ? '/dashboard' : '/login'));
exit;
