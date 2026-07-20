#!/bin/bash
set -euo pipefail

# La flag del LFI se escribe en runtime desde una variable de entorno para no commitear su valor real
# (ver CLAUDE.md, "Reglas para mantener consistencia entre flags"). Se coloca FUERA del DocumentRoot
# (/var/www/html) a propósito: si viviera dentro, seria descargable directamente por HTTP sin necesidad
# de explotar el path traversal de attachment.php. Solo es alcanzable subiendo con "../" desde
# /var/www/html/uploads.
echo "${FLAG_WEBAPP_LFI:-SABANA{flag_no_configurada}}" > /var/www/flag_lfi.txt
chown www-data:www-data /var/www/flag_lfi.txt
chmod 640 /var/www/flag_lfi.txt

# Reto 1.4 — palabra del objetivo_final, alcanzable via la misma LFI (?file=../../objetivo_final.txt)
echo "trabaja" > /var/www/objetivo_final.txt
chown www-data:www-data /var/www/objetivo_final.txt
chmod 640 /var/www/objetivo_final.txt

exec "$@"
