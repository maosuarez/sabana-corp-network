#!/bin/bash
set -euo pipefail

# Establece la contraseña de msilva en runtime desde PIVOT_SSH_PASSWORD (ver .env.example).
# Su valor real nunca se commitea — el participante lo calcula crackeando el hash MD5 en el Reto 2.
# VULN: password reuse (Reto 3, nivel 1) — misma contraseña que msilva tiene en la tabla `users` de BD.
echo "msilva:${PIVOT_SSH_PASSWORD}" | chpasswd

# Contraseñas estáticas para los usuarios intermedios (no forman parte de la cadena de explotación;
# el participante los alcanza mediante la cadena cron → SUID → sudo, no adivinando su contraseña).
echo "jrodriguez:JRodr1guez_lab_2024!" | chpasswd
echo "lcastillo:LCas7ill0_lab_2024!"  | chpasswd

# Escribe el archivo de secretos de root.
# FLAG_LINUXSERVER_ROOT es la flag de puntuación del Reto 3.
# El Fragmento B es el keyfile para el reto del Parqueadero (N4) — pendiente de definir su valor final.
mkdir -p /root
cat > /root/secrets.txt << EOF
${FLAG_LINUXSERVER_ROOT:-SABANA{flag_no_configurada}}
--- Keyfile Fragmento B (para reto Parqueadero N4) ---
SABANA_KEY_B=PENDIENTE_DE_CONFIGURAR
EOF
chown root:root /root/secrets.txt
chmod 600 /root/secrets.txt

# Reto 3.5 — palabra del objetivo_final, solo accesible como root.
printf 'pasion\n' > /root/objetivo_final.txt
chmod 600 /root/objetivo_final.txt

# Arranca el demonio de cron en segundo plano (necesario para la vulnerabilidad del nivel 1→2).
/usr/sbin/cron

# Reto 3.6: inicia el proceso 'flag' como nobody con la flag como argumento.
# VULN: la flag queda expuesta en la tabla de procesos y en /proc/<PID>/cmdline.
# El participante la descubre sin necesidad de escalar privilegios:
#   ps aux | grep flag
#   cat /proc/<PID>/cmdline | tr '\0' '\n'
# Para "completar" el reto: kill <PID>  (o pkill -f flag)
FLAG_PROC_ARG="${FLAG_LINUXSERVER_PROC:-SABANA{flag_no_configurada}}"
su -s /bin/bash nobody -c "/usr/local/bin/flag '$FLAG_PROC_ARG'" &

exec "$@"
