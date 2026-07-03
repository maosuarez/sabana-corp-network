<?php
// Configuración leída de variables de entorno inyectadas por docker-compose (ver .env.example).
// Nunca hardcodear aquí valores reales de credenciales o flags.

define('DB_HOST', getenv('DB_HOST') ?: 'database');
define('DB_NAME', getenv('DB_NAME') ?: 'sabana_helpdesk');
define('DB_APP_USER', getenv('DB_APP_USER') ?: 'helpdesk_app');
define('DB_APP_PASSWORD', getenv('DB_APP_PASSWORD') ?: '');

define('JWT_SIGNING_SECRET', getenv('JWT_SIGNING_SECRET') ?: 'dev-secret-do-not-use-in-prod');

// Secreto interno compartido con services/xss-bot (ver bot_login.php).
define('BOT_SECRET', getenv('BOT_SECRET') ?: '');

define('FLAG_WEBAPP_XSS', getenv('FLAG_WEBAPP_XSS') ?: 'SABANA{flag_no_configurada}');
define('FLAG_WEBAPP_LFI', getenv('FLAG_WEBAPP_LFI') ?: 'SABANA{flag_no_configurada}');
