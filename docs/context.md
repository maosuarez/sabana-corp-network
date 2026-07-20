# Contexto del laboratorio — Sabana Corp Network

Este documento es la **fuente de verdad** del dominio del proyecto: qué es el laboratorio, por qué existe, cómo se resuelve, y por qué cada vulnerabilidad está donde está. Todo cambio de diseño se refleja aquí. Las reglas de proceso (convenciones de código, Docker, CI/CD) viven en `CLAUDE.md`; este archivo se centra en la narrativa y la arquitectura del laboratorio en sí.

> Estado: implementación completa de los 3 retos y el xss-bot. Pendiente: verificación end-to-end, migración a macvlan, templating por equipo y valor final del Fragmento B.

## Historia y narrativa

**Sabana Corp** es una empresa ficticia con una mesa de ayuda interna (sistema de tickets) usada por empleados para reportar incidentes de TI. El participante entra al laboratorio como un atacante externo sin credenciales, con únicamente la URL pública de la aplicación de tickets.

La narrativa sigue la lógica de un compromiso real de infraestructura:

1. El atacante encuentra una debilidad en la aplicación web pública (Sabana Corp Helpdesk).
2. Usa esa debilidad para escalar privilegios dentro de la propia aplicación hasta llegar a funcionalidad interna reservada al personal de TI.
3. Esa funcionalidad interna filtra las credenciales de la base de datos que respalda la aplicación.
4. Dentro de la base de datos, el atacante recupera contraseñas de empleados almacenadas de forma insegura. Una de ellas es la credencial SSH de un usuario de bajo privilegio en el servidor Linux.
5. Desde ahí, una cadena clásica de escalamiento de privilegios en Linux (cron mal configurado → SUID → sudo sin contraseña vía GTFOBins) lleva al atacante hasta `root`, donde encuentra la evidencia final del compromiso (`secrets.txt`).

El laboratorio está diseñado para sentirse como **una sola intrusión continua**, no como tres retos sueltos. Cada paso justifica narrativamente el siguiente.

## Objetivos pedagógicos

- Practicar una cadena de explotación web completa: credenciales expuestas → SQLi → IDOR → LFI → JWT inseguro → XSS almacenado.
- Practicar cracking de hashes de contraseñas sin salt con algoritmo débil.
- Practicar enumeración y escalamiento de privilegios en Linux: cron jobs, SUID, sudo sin contraseña, GTFOBins.
- Reforzar que cada vulnerabilidad aislada puede parecer de bajo impacto, pero encadenada con otras compromete la infraestructura completa — la lección central de "defense in depth".

## Meta-reto: Objetivo Final

Distribuidos en 4 de los retos hay archivos llamados `objetivo_final.txt`, cada uno con una palabra. En el orden de descubrimiento natural de la cadena de explotación, las cuatro palabras forman la frase:

> **"trabaja duro con pasion"**

| Palabra | Reto | Ubicación | Cómo se obtiene |
|---|---|---|---|
| `trabaja` | 1.4 — LFI | `/var/www/objetivo_final.txt` | `GET /attachment?file=../../objetivo_final.txt` |
| `duro` | 1.5 — JWT bypass | Campo JSON en `/api/internal/admin/database` | `objetivo_final` en la respuesta del endpoint interno |
| `con` | 3.2 — Cron job | `/home/jrodriguez/objetivo_final.txt` | Accesible tras escalar a `jrodriguez` (permisos 640) |
| `pasion` | 3.5 — Root | `/root/objetivo_final.txt` | Accesible solo como root (permisos 600) |

Los archivos se crean en runtime desde los `entrypoint.sh` de cada servicio (no están commiteados con contenido fijo). Son independientes de las flags `SABANA{...}` — no son flags de puntuación, son parte de un meta-reto narrativo.

## Arquitectura general

Tres servicios independientes, cada uno en su propio contenedor Docker, conectados por la cadena de explotación (la conexión es de **información**, no de tráfico de red entre contenedores):

```
┌──────────────────────┐       ┌──────────────────────┐       ┌──────────────────────┐
│   Web Application     │       │    Base de Datos       │       │     Linux Server       │
│  (PHP + MariaDB)       │──────▶│      (MariaDB)          │──────▶│  (Ubuntu 22.04 + SSH) │
│  Sistema de tickets    │ creds │  Usuarios con MD5       │  SSH  │  Escalamiento local   │
│  Sabana Corp Helpdesk  │       │  sin salt               │ creds │  hasta root           │
└──────────────────────┘       └──────────────────────┘       └──────────────────────┘
      Reto 1 (6 vulns)               Reto 2 (hashes)               Reto 3 (privesc)
```

Existe además un cuarto contenedor de **soporte** (`services/xss-bot`) que no es un reto: simula a un administrador visitando tickets para que el Stored XSS del Reto 1 tenga un objetivo real. Ver decisión de diseño #6.

## Servicios y vulnerabilidades

### Reto 1 — Web Application (Sabana Corp Helpdesk)

Sistema de tickets con tres perfiles: **Visitante** (sin sesión), **Empleado** (`jperez`), **Personal de TI** (`it_soporte`). Stack: PHP 8.2 + Apache + MariaDB.

| # | Vulnerabilidad | Técnica | Entrega |
|---|---|---|---|
| 1.1 | Credenciales expuestas | Comentario HTML en view-source de `/login` | Sesión como `jperez` (empleado) |
| 1.2 | SQL Injection | Concatenación directa en `mysqli_query()` en `/search` | ID del usuario `it_soporte` (id=3) |
| 1.3 | IDOR | `/profile?id=3` sin validar ownership | Ruta del endpoint interno revelada en el 403 |
| 1.4 | LFI / path traversal | `readfile()` sin sanitizar en `/attachment` | `FLAG_WEBAPP_LFI` + palabra "trabaja" del meta-reto |
| 1.5 | JWT inseguro | Payload decodificado sin verificar HMAC en `/api/internal/admin/database` | Credenciales de la BD + palabra "duro" del meta-reto |
| 1.6 | Stored XSS | `echo $body` sin `htmlspecialchars()` en comentarios de tickets | `FLAG_WEBAPP_XSS` (en claim `flag` del JWT del bot) |

**Flujo de dependencias:** 1.1 → 1.2 → 1.3 → (1.4 y 1.5 paralelos) → 1.6 independiente.  
La vulnerabilidad que avanza la cadena al Reto 2 es **1.5** (JWT bypass → credenciales BD).  
La vulnerabilidad **1.4** (LFI) y **1.6** (XSS) son flags de puntuación adicionales dentro del Reto 1.

### Reto 2 — Base de Datos

MariaDB accesible directamente en el puerto 3306 con las credenciales obtenidas en 1.5. Contiene las contraseñas de los empleados almacenadas como MD5 sin salt.

- **`users`**: tabla con `password_md5`. Hashes crackeables con hashcat + rockyou en segundos.
- **`system_notes`**: tabla que contiene `FLAG_DATABASE` en texto claro (inyectada en runtime desde `FLAG_DATABASE`).
- **Progresión**: la contraseña de `msilva` crackeada = `PIVOT_SSH_PASSWORD` = credencial SSH del Reto 3.

### Reto 3 — Linux Server

Ubuntu 22.04 accesible por SSH en el puerto 2222. Cadena de escalamiento con 3 escalones:

```
msilva (SSH) ──→ jrodriguez (cron hijack) ──→ lcastillo (SUID find) ──→ root (sudo python3)
```

| Escalón | Vector | Herramienta de detección |
|---|---|---|
| msilva → jrodriguez | `/opt/scripts/sync.sh` con permisos `777` ejecutado por cron de `jrodriguez` cada minuto | `pspy`, `ls -la /opt/scripts/` |
| jrodriguez → lcastillo | `/usr/local/bin/find` con SUID de `lcastillo`. GTFOBins: `find . -exec /bin/bash -p \;` | `find / -perm -4000 2>/dev/null` |
| lcastillo → root | `sudo NOPASSWD: /usr/bin/python3`. GTFOBins: `sudo python3 -c 'import os; os.execl("/bin/sh","sh")'` | `sudo -l` |

**Reto paralelo 3.6:** proceso `/usr/local/bin/flag` corriendo como `nobody` con `FLAG_LINUXSERVER_PROC` como argumento de línea de comandos. Visible para todos los usuarios desde el primer nivel (msilva). Descubrimiento: `ps aux | grep flag`.

Archivos de flags en el Linux Server:
- `/root/secrets.txt` (600) — `FLAG_LINUXSERVER_ROOT` + placeholder Fragmento B
- `/root/objetivo_final.txt` (600) — palabra "pasion" del meta-reto

## Flujo esperado de resolución

1. `view-source:/login` → `jperez/Bienvenido123` → sesión de empleado
2. `/search?q=' OR 1=1 -- -` → UNION SQLi → ID 3 de `it_soporte`
3. `/profile?id=3` → IDOR → sección IT → click → 403 con ruta del endpoint
4. `/attachment?file=../../flag_lfi.txt` → `FLAG_WEBAPP_LFI`; `?file=../../objetivo_final.txt` → "trabaja"
5. Modificar JWT: cambiar `role` a `it`, sin verificar firma → GET `/api/internal/admin/database` → credenciales BD + "duro"
6. XSS en comentario de ticket → bot visita → exfiltra `document.cookie` → decodificar JWT → `FLAG_WEBAPP_XSS`
7. `mysql -h localhost -P 3306 -u helpdesk_app -p` → dump `users` + `SELECT content FROM system_notes` → `FLAG_DATABASE`
8. `hashcat -m 0 hashes.txt rockyou.txt` → crackear MD5 → identificar password de `msilva`
9. `ssh msilva@<host> -p 2222` → `ps aux | grep flag` → `FLAG_LINUXSERVER_PROC` (reto 3.6)
10. Cron hijack → `jrodriguez` → `/home/jrodriguez/objetivo_final.txt` → "con"
11. SUID find → `lcastillo`
12. `sudo python3` → root → `cat /root/secrets.txt` → `FLAG_LINUXSERVER_ROOT`; `cat /root/objetivo_final.txt` → "pasion"

## Esquema de flags y secretos

**Flags de puntuación** (formato `SABANA{...}`, inyectadas por env, nunca commiteadas):

| Variable | Cómo se obtiene |
|---|---|
| `FLAG_WEBAPP_LFI` | LFI: `GET /attachment?file=../../flag_lfi.txt` |
| `FLAG_WEBAPP_XSS` | Stored XSS: claim `flag` en el JWT de la cookie del bot |
| `FLAG_DATABASE` | BD: `SELECT content FROM system_notes` |
| `FLAG_LINUXSERVER_ROOT` | Linux root: `cat /root/secrets.txt` |
| `FLAG_LINUXSERVER_PROC` | Linux proceso: `ps aux \| grep flag` |

**Secretos de progresión** (no son flags, son necesarios para avanzar):

| Secreto | Cómo se obtiene | Para qué sirve |
|---|---|---|
| Credencial de `jperez` | View-source del login | Iniciar sesión en la webapp |
| ID de `it_soporte` (=3) | SQLi en `/search` | IDOR en `/profile?id=3` |
| Ruta `/api/internal/admin/database` | Error 403 tras IDOR | Objetivo del JWT bypass |
| `DB_APP_USER` + `DB_APP_PASSWORD` | JWT bypass del endpoint interno | Conectar a la BD (Reto 2) |
| `PIVOT_SSH_PASSWORD` | Crackear hash MD5 de `msilva` en la BD | SSH al servidor Linux (Reto 3) |

## Decisiones de diseño (registro)

Esta sección acumula decisiones arquitectónicas no triviales con alternativas consideradas.

**1. Alcance de servicios:** 3 retos (Web App, BD, Linux Server). Se descartó un cuarto "cPanel" en una versión anterior.

**2. Stack de la Web Application:** PHP + MariaDB sin framework ni ORM. Las vulnerabilidades pedidas ocurren de forma idiomática en PHP plano. Un ORM parametrizado eliminaría la SQLi; un framework moderno neutralizaría XSS y LFI.

**3. Algoritmo de hash en la Base de Datos:** MD5 sin salt. El ejemplo más reconocible de almacenamiento inseguro, crackeable en segundos con hashcat + rockyou, sin requerir hardware potente.

**4. Manejo de flags y secretos de progresión:** fuera de git, vía variables de entorno. `.env.example` solo con nombres y valores ficticios. Las contraseñas MD5 "de ruido" de empleados son la única excepción (son la vulnerabilidad, no un secreto).

**5. Mecanismo del JWT inseguro:** verificación de firma deshabilitada en el backend — `jwt_decode_unverified()` solo hace base64 del payload sin recalcular el HMAC. Elegido sobre `alg:none` porque no depende de que la librería cliente lo soporte, y es el error más común en implementaciones reales de JWT.

**6. Servicio de soporte xss-bot:** cuarto contenedor Node.js + Playwright (Chromium headless) que simula al admin revisando tickets. Se autentica con `BOT_SECRET` vía un endpoint interno (`/bot_login.php`), no con contraseña de usuario, para que crackear hashes en el Reto 2 no permita saltarse el XSS. La flag `FLAG_WEBAPP_XSS` va embebida en el JWT de la cookie del bot (claim `flag`), sin `HttpOnly`, para que un XSS la exfiltre con `document.cookie`.

**7. Persistencia de FLAG_DATABASE:** la flag se almacena en texto plano en la tabla `system_notes`, inyectada en runtime via `envsubst` en la plantilla SQL. Alternativa descartada: que la flag sea la password cifrada del admin descifrable con las 3 passwords de empleados combinadas — demasiado frágil (depende del orden exacto de filas) y desacoplado del resto de la cadena.

**8. Cola del xss-bot:** tabla `bot_visit_queue` en MariaDB, gestionada vía endpoints `/bot/queue.php` y `/bot/mark_visited.php` protegidos por `BOT_SECRET`. Cualquier comentario nuevo en un ticket encola una visita del bot. Alternativa considerada: Redis — descartada para simplificar la infraestructura. Puede revisarse si el evento multi-equipo requiere mayor throughput.

**9. Mecánica del Reto 3.6 ("matar proceso flag"):** se eligió la **Opción A** — proceso `/usr/local/bin/flag` corriendo como `nobody` con `FLAG_LINUXSERVER_PROC` como argumento de línea de comandos, visible en `ps aux` y `/proc/<PID>/cmdline` desde cualquier nivel de privilegio (incluso `msilva`). Es un reto paralelo, no un escalón. Alternativas descartadas: proceso que bloquea un archivo (Opción B, frágil con advisory locks en Docker) y proceso cuya muerte dispara un watchdog (Opción C, dependiente de timing).

**10. Meta-reto "Objetivo Final":** 4 archivos `objetivo_final.txt` distribuidos en retos de los 3 servicios. Cada archivo contiene una palabra; en orden de descubrimiento forman "trabaja duro con pasion". Retos elegidos: 1.4 (LFI), 1.5 (JWT bypass), 3.2 (cron→jrodriguez), 3.5 (root). La palabra del Reto 1.5 va embebida en el JSON del endpoint interno (campo `objetivo_final`), no en un archivo de disco, para que sea coherente con la naturaleza de ese reto. Los archivos en el Linux Server tienen permisos restrictivos que refuerzan el requisito de escalada: 640 para jrodriguez y 600 para root.

**11. Pendientes de decidir:**
- Contenido exacto de `SABANA_KEY_B` (Fragmento B en `/root/secrets.txt` para el reto del Parqueadero N4) — pendiente de que el equipo del Parqueadero lo defina.
- Topología de red final: cuándo y cómo migrar de bridge a `macvlan` e integración con WireGuard/nftables.
- Templating por equipo: `docker compose -p teamN` con `.env` generados por script, o scripts que reescriben archivos.
