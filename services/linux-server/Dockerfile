FROM ubuntu:22.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
        openssh-server \
        cron \
        sudo \
        vim \
        python3 \
        findutils \
    && rm -rf /var/lib/apt/lists/*

# ── Usuarios ──────────────────────────────────────────────────────────────────
# usuario1 = msilva: punto de entrada SSH de bajo privilegio (credencial obtenida en el Reto 2).
# # VULN: password reuse (Reto 3, nivel 1) — PIVOT_SSH_PASSWORD es la misma contraseña que msilva
# usa en la tabla `users` de la Base de Datos. El participante la crackea en el Reto 2 y la reutiliza aquí.
RUN useradd -m -s /bin/bash msilva

# usuario2 = jrodriguez: propietario del cron-job vulnerable (escalamiento nivel 1→2).
RUN useradd -m -s /bin/bash jrodriguez \
    && printf 'con\n' > /home/jrodriguez/objetivo_final.txt \
    && chown jrodriguez:jrodriguez /home/jrodriguez/objetivo_final.txt \
    && chmod 640 /home/jrodriguez/objetivo_final.txt

# usuario3 = lcastillo: propietario del binario SUID (escalamiento nivel 2→3) y beneficiario del sudo.
RUN useradd -m -s /bin/bash lcastillo

# ── Vulnerabilidad de cron-job (Reto 3, nivel 1→2) ───────────────────────────
# El script /opt/scripts/sync.sh es ejecutado por jrodriguez vía cron pero es escribible por cualquier
# usuario (chmod 777). msilva puede modificarlo para ejecutar código como jrodriguez al próximo minuto.
# VULN: script de cron escribible por usuario sin privilegios (Reto 3, vulnerabilidad #2 — cron job)
RUN mkdir -p /opt/scripts \
    && printf '#!/bin/bash\n# Sincronizacion de logs de sistema\ncp /var/log/syslog /tmp/sys_backup.log 2>/dev/null || true\n' \
        > /opt/scripts/sync.sh \
    && chmod 777 /opt/scripts/sync.sh

RUN printf '* * * * * jrodriguez /opt/scripts/sync.sh\n' > /etc/cron.d/sync-logs \
    && chmod 644 /etc/cron.d/sync-logs

# ── Vulnerabilidad de binario SUID (Reto 3, nivel 2→3) ───────────────────────
# Copia de `find` propiedad de lcastillo con SUID. Cuando jrodriguez la ejecuta, el proceso corre con
# effective UID = lcastillo. GTFOBins: /usr/local/bin/find . -exec /bin/bash -p \; -quit
# VULN: binario find con SUID de lcastillo, ejecutable por cualquier usuario (Reto 3, vulnerabilidad #3)
RUN cp /usr/bin/find /usr/local/bin/find \
    && chown lcastillo:lcastillo /usr/local/bin/find \
    && chmod u+s /usr/local/bin/find

# ── Vulnerabilidad de sudo NOPASSWD (Reto 3, nivel 3→root) ───────────────────
# lcastillo puede ejecutar python3 como root sin contraseña.
# GTFOBins: sudo python3 -c 'import os; os.execl("/bin/sh", "sh")'
# VULN: sudo NOPASSWD para python3 (Reto 3, vulnerabilidad #4 — sudo + GTFOBins)
RUN echo 'lcastillo ALL=(ALL) NOPASSWD: /usr/bin/python3' > /etc/sudoers.d/lcastillo \
    && chmod 440 /etc/sudoers.d/lcastillo

# ── Proceso "flag" (Reto 3.6) ────────────────────────────────────────────────
# Script en /usr/local/bin/flag que el entrypoint arranca como nobody con FLAG_LINUXSERVER_PROC
# como argumento. Su valor queda expuesto en la lista de procesos y en /proc/<pid>/cmdline.
# VULN: credencial interna expuesta en argumentos del proceso (Reto 3, vulnerabilidad #3.6).
# Descubrimiento: ps aux | grep flag      Extracción: cat /proc/<PID>/cmdline | tr '\0' '\n'
RUN printf '#!/bin/bash\n# Proceso de verificacion de integridad del sistema.\nwhile true; do sleep 3600; done\n' \
        > /usr/local/bin/flag \
    && chmod 755 /usr/local/bin/flag

# ── SSH ───────────────────────────────────────────────────────────────────────
RUN mkdir -p /var/run/sshd \
    && sed -i 's/#PermitRootLogin prohibit-password/PermitRootLogin no/' /etc/ssh/sshd_config \
    && sed -i 's/#PasswordAuthentication yes/PasswordAuthentication yes/' /etc/ssh/sshd_config

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 22
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/sbin/sshd", "-D"]
