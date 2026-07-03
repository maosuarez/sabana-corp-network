<?php
setcookie('session', '', ['expires' => time() - 3600, 'path' => '/']);
header('Location: /login');
exit;
