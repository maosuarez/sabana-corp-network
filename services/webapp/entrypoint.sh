#!/bin/bash
set -euo pipefail

# La flag del LFI se escribe en runtime desde una variable de entorno para no commitear su valor real
# (ver CLAUDE.md, "Reglas para mantener consistencia entre flags"). Se coloca FUERA del DocumentRoot
# (/var/www/html) a propósito: si viviera dentro, seria descargable directamente por HTTP sin necesidad
# de explotar el path traversal de attachment.php. Solo es alcanzable subiendo con "../" desde
# /var/www/html/uploads.
# Nota: el valor por defecto se arma en una variable aparte (no inline en ${VAR:-...}) porque una
# llave "{" sin escapar dentro del propio default de un ${VAR:-default} descuadra el parseo de bash y
# duplica la "}" final en la salida — bug real de bash, no relacionado con las vulnerabilidades del reto.
_default_flag_lfi='SABANA{flag_no_configurada}'
echo "${FLAG_WEBAPP_LFI:-$_default_flag_lfi}" > /var/www/flag_lfi.txt
chown www-data:www-data /var/www/flag_lfi.txt
chmod 640 /var/www/flag_lfi.txt

# Reto 1.4 — palabra del objetivo_final, alcanzable via la misma LFI (?file=../../objetivo_final.txt)
echo "trabaja" > /var/www/objetivo_final.txt
chown www-data:www-data /var/www/objetivo_final.txt
chmod 640 /var/www/objetivo_final.txt

# VULN: LFI / path traversal (Reto 1, vulnerabilidad #4) — archivo señuelo para /attachment?file=../../etc/passwd.
# La base de adjuntos es /var/www/html/uploads, así que ../../ desde ahí resuelve a /var/www (dos niveles
# arriba), no a la raíz real del filesystem (para eso harían falta cuatro "../"). Se coloca un /etc/passwd
# de aspecto realista aquí para que la ruta que el participante intuitivamente prueba primero
# (?file=../../etc/passwd) responda con contenido real y creíble, en vez de un 404 que sugiera que la LFI
# no funciona. El /etc/passwd real del contenedor sigue siendo alcanzable con dos niveles más de traversal
# (?file=../../../../etc/passwd, 4 en total), sin necesidad de ningún archivo adicional.
mkdir -p /var/www/etc
cat > /var/www/etc/passwd << 'EOF'
root:x:0:0:root:/root:/bin/bash
daemon:x:1:1:daemon:/usr/sbin:/usr/sbin/nologin
bin:x:2:2:bin:/bin:/usr/sbin/nologin
sys:x:3:3:sys:/dev:/usr/sbin/nologin
sync:x:4:65534:sync:/bin:/bin/sync
games:x:5:60:games:/usr/games:/usr/sbin/nologin
man:x:6:12:man:/var/cache/man:/usr/sbin/nologin
mail:x:8:8:mail:/var/mail:/usr/sbin/nologin
backup:x:34:34:backup:/var/backups:/usr/sbin/nologin
www-data:x:33:33:www-data:/var/www:/usr/sbin/nologin
nobody:x:65534:65534:nobody:/nonexistent:/usr/sbin/nologin
_apt:x:100:65534::/nonexistent:/usr/sbin/nologin
mysql:x:101:101:MySQL Server,,,:/nonexistent:/bin/false
sabana:x:1000:1000:Sabana Corp Ops,,,:/home/sabana:/bin/bash
EOF
chown www-data:www-data /var/www/etc/passwd
chmod 644 /var/www/etc/passwd

exec "$@"
