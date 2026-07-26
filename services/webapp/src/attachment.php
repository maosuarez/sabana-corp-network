<?php
require_once __DIR__ . '/auth.php';

require_login();

$file = $_GET['file'] ?? '';
$base = __DIR__ . '/uploads';

// VULN: LFI / path traversal (Reto 1, vulnerabilidad #4). No se valida ni normaliza $file: secuencias
// "../" permiten escapar del directorio de adjuntos y leer cualquier archivo legible por www-data,
// incluyendo flag_lfi.txt (colocado deliberadamente fuera del DocumentRoot, ver entrypoint.sh).
$path = $base . '/' . $file;

// VULN: oráculo de listado de directorios sobre la misma LFI (Reto 1, vulnerabilidad #4). Sin esto, un
// participante que no conozca de antemano la ruta exacta de flag_lfi.txt no tiene forma realista de
// descubrirla solo con path traversal a ciegas. Si $file resuelve a un directorio (p. ej. "." , "./",
// ".." , "../", "../../"), en vez de fallar como un servidor de archivos real, se lista su contenido —
// permitiendo "navegar" el árbol subiendo con file=../ , file=../../ , etc. hasta encontrar el nombre del
// archivo de la flag. No es una función legítima del sistema de adjuntos, es una pista deliberada.
if (is_dir($path)) {
    $entries = @scandir($path);
    if ($entries === false) {
        http_response_code(404);
        exit('Archivo no encontrado.');
    }

    $folders = [];
    $files = [];
    $hasParent = false;
    foreach ($entries as $entry) {
        if ($entry === '.') {
            continue;
        }
        if ($entry === '..') {
            // Existe carpeta anterior mientras no estemos en la raíz del filesystem.
            $hasParent = realpath($path) !== '/';
            continue;
        }
        if (is_dir($path . '/' . $entry)) {
            $folders[] = $entry;
        } else {
            $files[] = $entry;
        }
    }
    sort($folders);
    sort($files);

    header('Content-Type: text/plain; charset=utf-8');
    echo "Listado de directorio para file={$file}\n";
    echo 'Ruta resuelta: ' . (realpath($path) ?: $path) . "\n\n";
    echo '¿Carpeta anterior disponible (..)? ' . ($hasParent ? 'si' : 'no') . "\n\n";
    echo "Carpetas:\n";
    foreach ($folders as $f) {
        echo "  [DIR]  {$f}\n";
    }
    if (!$folders) {
        echo "  (ninguna)\n";
    }
    echo "\nArchivos:\n";
    foreach ($files as $f) {
        echo "  [FILE] {$f}\n";
    }
    if (!$files) {
        echo "  (ninguno)\n";
    }
    exit;
}

if (!is_readable($path)) {
    http_response_code(404);
    exit('Archivo no encontrado.');
}

header('Content-Type: text/plain; charset=utf-8');
readfile($path);
