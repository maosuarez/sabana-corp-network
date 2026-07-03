<?php
require_once __DIR__ . '/auth.php';

require_login();

$file = $_GET['file'] ?? '';
$base = __DIR__ . '/uploads';

// VULN: LFI / path traversal (Reto 1, vulnerabilidad #4). No se valida ni normaliza $file: secuencias
// "../" permiten escapar del directorio de adjuntos y leer cualquier archivo legible por www-data,
// incluyendo flag_lfi.txt (colocado deliberadamente fuera del DocumentRoot, ver entrypoint.sh).
$path = $base . '/' . $file;

if (!is_readable($path) || is_dir($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

header('Content-Type: text/plain; charset=utf-8');
readfile($path);
