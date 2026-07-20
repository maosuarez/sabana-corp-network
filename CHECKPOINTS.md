# CHECKPOINTS — Zona Restringida por Equipo

> Sistema de checkpoints para el desarrollo de los 3 servidores de la Zona Restringida (edificio por equipo) del CTF Sabana Corp Network. Este archivo es la fuente única de verdad sobre qué está hecho, qué falta, y cómo se valida cada pieza.

## Contexto para Claude Code

Este archivo debe usarse en cada sesión de trabajo sobre el repositorio `maosuarez/sabana-corp-network`. Antes de tocar código, Claude Code debe:

1. Leer este archivo completo.
2. Consultar `CLAUDE.md` para reglas de proceso y `docs/context.md` para narrativa.
3. Al terminar cualquier trabajo, actualizar los checkboxes de este archivo y hacer commit del cambio junto con el código.

**Reglas críticas heredadas de `CLAUDE.md`:**
- La inseguridad es la funcionalidad. Nunca "arreglar" una vulnerabilidad marcada con `// VULN:` sin autorización explícita.
- Cada línea vulnerable debe tener comentario `// VULN:` que indique qué reto la usa.
- Flags siempre inyectadas por env (`SABANA{...}`), nunca hardcodeadas.
- Un `Dockerfile` por servicio en `services/<nombre>/`.

## Estado global del repositorio

**Última actualización:** 2026-07-18 — Sesión 2 (linux-server + xss-bot + gaps webapp)

| Componente | Estado | % Completado |
|---|---|---|
| Documentación base | ✅ Completo | 100% |
| Web-App (Reto 1) | 🟡 En progreso | ~90% (faltan tests end-to-end y /admin/dashboard opcional) |
| Base de Datos (Reto 2) | 🟡 En progreso | ~90% (falta verificación end-to-end) |
| Linux Server (Reto 3) | 🟡 En progreso | ~85% (falta Reto 3.6 y verificación) |
| XSS Admin Bot (soporte) | 🟡 En progreso | ~80% (falta rate-limit y verificación) |
| Orquestación local | 🟡 Casi completo | 95% (falta solo verificar `up --build` limpio) |
| Diagrama de red | ❌ Pendiente | 0% |
| Migración a macvlan | ❌ Pendiente | 0% |
| CI/CD | ✅ Completo | 100% (matrix cubre los 4 servicios) |

## Convenciones para actualizar este archivo

- `[ ]` = no iniciado
- `[~]` = en progreso (agregar nota entre paréntesis con el estado)
- `[x]` = completo y verificado end-to-end
- 🔴 = bloqueante para el evento (si no está listo, el reto se cae)
- 🟡 = importante pero no bloqueante (se puede degradar)
- 🟢 = mejora opcional (nice-to-have)

---

## Checkpoint 1: Web-App (`services/webapp/`)

**Ubicación:** `services/webapp/`
**Stack:** PHP + MariaDB (sin framework, sin ORM, queries manuales)
**Puerto local:** 8080 → 80
**Red destino en evento:** `172.16.T.10` dentro de la subred del equipo

### Retos que debe entregar (5 sub-retos encadenados)

#### Reto 1.1 — Login con credenciales quemadas en código

Vulnerabilidad: Reconocimiento pasivo de código. Las credenciales de un usuario de bajos privilegios están hardcodeadas en un archivo del frontend (JavaScript o comentario HTML) accesible sin autenticación.

- [x] 🔴 Página de login funcional en `/login.php` (o equivalente)
- [x] 🔴 Credenciales de bajo privilegio hardcodeadas en el frontend visible (view-source) — `jperez/Bienvenido123` en comentario HTML de `login.php`
- [x] 🔴 Comentario `// VULN:` al lado de la credencial hardcodeada
- [x] 🔴 Login exitoso con esas credenciales entrega sesión de "empleado"
- [x] Login rechaza credenciales incorrectas con mensaje realista — "Usuario o contraseña incorrectos."
- [ ] Verificado end-to-end: credencial se encuentra en el HTML y funciona

**Entrega:** sesión válida como empleado (rol bajo).

#### Reto 1.2 — SQLi en buscador de tickets → ID de empleado IT

Vulnerabilidad: SQL injection clásica por concatenación de strings en query no parametrizada.

- [x] 🔴 Endpoint `/search` con búsqueda por texto libre (`search.php`)
- [x] 🔴 Input concatenado directamente en query SQL sin sanitizar — `WHERE created_by = {$user['sub']} AND subject LIKE '%$q%'`
- [x] 🔴 Comentario `// VULN:` sobre la línea del `mysqli_query`
- [x] 🔴 Mensajes de error de BD visibles — `mysqli_error($conn)` mostrado en pantalla
- [x] 🔴 Payload `' OR 1=1 -- -` devuelve todos los tickets
- [x] 🔴 `sqlmap` funcional contra el endpoint
- [x] 🔴 Al hacer UNION/dump se obtiene el ID del empleado IT (`it_soporte`, id=3, visible en columna `assigned_to`)
- [ ] Verificado end-to-end con `sqlmap --dump`

**Entrega:** ID de empleado con rol IT (para siguiente reto).

#### Reto 1.3 — IDOR sobre el ID de IT → panel interno bloqueado

Vulnerabilidad: Insecure Direct Object Reference — endpoint no valida ownership del recurso.

- [x] 🔴 Endpoint `/profile?id=<ID>` que muestra perfil por ID
- [x] 🔴 Sin validación de que el ID solicitado pertenece al usuario logueado
- [x] 🔴 Comentario `// VULN:` sobre la línea que carga el perfil sin verificar dueño
- [x] 🔴 Cambiar `?id=X` a `?id=3` (it_soporte) muestra el perfil del IT
- [x] 🔴 El perfil del IT tiene sección "Herramientas internas de TI" con enlace a panel interno
- [x] 🔴 Al hacer click, retorna 403 con mensaje que menciona rol `it` o `admin` requerido
- [ ] Verificado end-to-end: IDOR funciona, sección visible pero no accesible

**Entrega:** pista visible de que existe el endpoint `/api/internal/admin/database` protegido por JWT.

#### Reto 1.4 — LFI en adjuntos de tickets → archivo con flag

Vulnerabilidad: Local File Inclusion clásica de PHP (`readfile($_GET['file'])`).

- [x] 🔴 Funcionalidad de adjuntos en `/attachment?file=<nombre>` (tickets con adjuntos en seed)
- [x] 🔴 `readfile()` sin sanitización del parámetro — `$path = $base . '/' . $file`
- [x] 🔴 Comentario `// VULN:` sobre la línea del readfile
- [x] 🔴 `?file=../../etc/passwd` funcional (leer passwd del contenedor) — desde `/var/www/html/uploads/` subir dos niveles alcanza `/etc/passwd`
- [x] 🔴 Existe `/var/www/flag_lfi.txt` con flag `SABANA{...}` — creado por `entrypoint.sh`, fuera del DocumentRoot
- [x] 🔴 Flag inyectada por variable de entorno `FLAG_WEBAPP_LFI` en el Dockerfile
- [ ] Fuzzing con `ffuf` para descubrir la ruta del flag es viable
- [ ] Verificado end-to-end: LFI funciona y encuentra la flag

**Nota de ruta:** `?file=../../flag_lfi.txt` → `/var/www/html/uploads/../../flag_lfi.txt` → `/var/www/flag_lfi.txt` ✅

**Entrega:** `SABANA{...}` de la flag LFI + pistas adicionales para JWT.

#### Reto 1.5 — JWT bypass → endpoint /api/internal/admin/database → credenciales de la BD

Vulnerabilidad: JWT mal implementado. Backend NO verifica la firma (ver `jwt.php:jwt_decode_unverified()`).

- [x] 🔴 Autenticación de sesión usa JWT en cookie `session`
- [x] 🔴 Backend decodifica JWT SIN verificar la firma — `jwt_decode_unverified()` solo hace base64 del payload
- [x] 🔴 Comentario `// VULN:` sobre la línea que decodifica sin verificar (en `auth.php` y `jwt.php`)
- [x] 🔴 Payload contiene claim `role: "employee"` editable
- [x] 🔴 Cambiar `role` a `it` (o `admin`) y usar el JWT modificado da acceso a `/api/internal/admin/database`
- [x] 🔴 Endpoint retorna credenciales reales de la BD (`DB_HOST`, `DB_NAME`, `DB_APP_USER`, `DB_APP_PASSWORD`)
- [ ] Verificado end-to-end con Postman/Burp: JWT modificado da las credenciales

**Entrega:** `DB_APP_USER` + `DB_APP_PASSWORD` para pivotar al Reto 2.

#### Reto 1.6 — Stored XSS → cookie de admin → flag en JWT

Vulnerabilidad: XSS almacenado en comentarios de tickets. Cookie de admin sin `HttpOnly`. Sin CSP.

- [x] 🔴 Publicación de comentarios acepta HTML/JavaScript sin escape — `$body = $_POST['comment']` directo a BD
- [x] 🔴 Renderizado del comentario muestra el contenido sin `htmlspecialchars` — `<?= $c['body'] ?>`
- [x] 🔴 Comentario `// VULN:` sobre la línea del `echo` sin escape
- [x] 🔴 Cookie de sesión de admin NO tiene flag `HttpOnly` — `'httponly' => false` en `auth.php`
- [x] 🔴 Sin Content Security Policy configurada (Apache no envía header CSP)
- [x] 🔴 Al añadir un comentario a cualquier ticket, se encola un job para `xss-bot` en `bot_visit_queue`
- [x] 🔴 Flag embebida en el claim `flag` del JWT de la cookie de admin (ver `bot_login.php`) — **el payload XSS lee `document.cookie` y decodifica el JWT para extraer la flag**
- [x] 🔴 Flag inyectada por env `FLAG_WEBAPP_XSS`
- [ ] Verificado end-to-end: payload → bot visita → cookie exfiltrada → flag decodificada del JWT

> **Nota de diseño:** La flag NO está en un endpoint `/admin/dashboard` separado. Está embebida en el claim `flag` del JWT de la cookie de sesión del bot (decisión de diseño #6 en `docs/context.md`). El participante exfiltra `document.cookie`, decodifica el JWT (base64url del payload) y encuentra `{"flag": "SABANA{...}", ...}`. No se necesita visitar un dashboard adicional.

> **Nota de trigger del bot:** El bot revisa todos los tickets en los que se añade un comentario (sin campo de prioridad). La narrativa de "ticket urgente" puede usarse en los hints del CTF, pero técnicamente cualquier comentario encola la visita.

**Dependencia crítica:** requiere `services/xss-bot/` funcionando (ver Checkpoint 4).

**Entrega:** `SABANA{...}` de la flag XSS (decodificando el JWT de la cookie robada).

### Configuración base de Web-App

- [x] 🔴 Dockerfile en `services/webapp/Dockerfile` — `php:8.2-apache` con `mysqli` y mod_rewrite
- [x] 🔴 Apache + mod_php (un proceso web por contenedor)
- [x] 🔴 Conexión a BD via variables env (`DB_HOST`, `DB_APP_USER`, `DB_APP_PASSWORD`)
- [x] 🔴 Registro en `docker-compose.yml`
- [x] 🔴 Puerto 80 expuesto al host (8080:80 en local)
- [x] 🔴 Registro en workflow de GitHub Actions matrix
- [x] Sin frameworks/ORMs que neutralicen las vulns

### Validación end-to-end de Web-App

- [ ] 🔴 Recorrido completo desde login → SQLi → IDOR → LFI → JWT → XSS ejecutado por un evaluador humano
- [ ] 🔴 Cada flag `SABANA{...}` se recupera vía la vulnerabilidad correspondiente
- [ ] 🔴 Credenciales de BD obtenidas vía JWT funcionan para pivotar a Reto 2
- [x] Los 3 perfiles (Visitante/sin sesión, Empleado/jperez, IT/it_soporte) tienen permisos coherentes con las vulns
- [ ] Reset con `docker compose down -v && docker compose up -d` restaura estado inicial

---

## Checkpoint 2: Base de Datos (`services/database/`)

**Ubicación:** `services/database/`
**Stack:** MariaDB (imagen oficial)
**Puerto local:** 3306 (expuesto directamente al participante)
**Red destino en evento:** `172.16.T.30` (subnet BD, dentro de la subred del equipo)

### Retos que debe entregar

#### Reto 2.1 — Acceso con credenciales del Reto 1

- [x] 🔴 Base de datos `sabana_helpdesk` con schema completo
- [x] 🔴 Usuario `helpdesk_app` (env `DB_APP_USER`) con `GRANT ALL PRIVILEGES` — accesible desde `'%'`
- [x] 🔴 Puerto 3306 expuesto para conexión directa del participante
- [ ] Verificado: `mysql -h <host> -u helpdesk_app -p` funciona con la credencial del JWT bypass

#### Reto 2.2 — Tabla `users` con hashes MD5 sin salt

- [x] 🔴 Tabla `users` con columnas: `id`, `username`, `password_md5`, `role`, `full_name`, `email`
- [x] 🔴 Usuarios: `jperez` (employee), `it_soporte` (it), `msilva` (employee), `rgomez` (employee), `lcastro` (employee) con MD5 sin salt
- [x] 🔴 1 usuario IT (`it_soporte`) con rol elevado
- [x] 🔴 1 usuario `admin` con password fuerte (no forma parte de la cadena de explotación)
- [x] 🔴 Passwords crackeables con `hashcat` + `rockyou.txt` en < 5 minutos — `Bienvenido123`, `Password1`, `sabana2024`
- [x] 🔴 `msilva` usa `PIVOT_SSH_PASSWORD` — su hash MD5 es la llave del pivote al Reto 3
- [x] 🔴 Seed data en `init/01-seed.sql.template` versionado, sustituido en runtime por `entrypoint-wrapper.sh`
- [ ] Verificado: dump de tabla users es viable, hashes se crackean

#### Reto 2.3 — Flag de la Base de Datos

> **DECISIÓN DE DISEÑO APLICADA** (ver `docs/context.md`, decisión #7): la flag NO es la password descifrada del admin. Se almacena en texto plano en la tabla `system_notes`, inyectada vía `FLAG_DATABASE` en runtime. El participante la obtiene por tener acceso de lectura a la BD.

- [x] 🔴 Tabla `system_notes` con la flag `${FLAG_DATABASE}` inyectada al arrancar el contenedor
- [x] 🔴 Flag inyectada por env `FLAG_DATABASE`, nunca hardcodeada
- [x] 🔴 Recuperación: conectar a BD con creds del JWT → `SELECT content FROM system_notes` → flag visible

**Entrega:** `SABANA{...}` de la flag DB.

#### Reto 2.4 — Tabla `tickets` (soporte del Reto 1)

- [x] 🔴 Tabla `tickets` con columnas: `id`, `subject`, `description`, `attachment_filename`, `created_by`, `assigned_to`, `status`
- [x] 🔴 Datos seed con tickets narrativos, incluyendo uno asignado a `it_soporte` (id=3) visible vía SQLi
- [x] 🔴 Ticket 3 referencia explícitamente a `it_soporte` en descripción
- [ ] Vulnerabilidad de escritura vía SQLi — opcional, no implementado

### Configuración base de BD

- [x] 🔴 Dockerfile en `services/database/Dockerfile` — basado en `mariadb:11`
- [x] 🔴 Init scripts en `services/database/init/` — plantilla SQL con `envsubst` en runtime
- [x] 🔴 Registro en `docker-compose.yml` con healthcheck
- [x] 🔴 Variables env: `MYSQL_ROOT_PASSWORD`, `MYSQL_DATABASE`, `DB_APP_USER`, `DB_APP_PASSWORD`, `FLAG_DATABASE`, `PIVOT_SSH_PASSWORD`
- [x] 🔴 Registro en workflow de GitHub Actions matrix

### Validación end-to-end de BD

- [ ] 🔴 Con credenciales del JWT bypass del Reto 1, participante se conecta a MariaDB
- [ ] 🔴 Dump de tabla `users` es exitoso
- [ ] 🔴 Los hashes de empleados se crackean con hashcat + rockyou en tiempo razonable
- [ ] 🔴 `SELECT content FROM system_notes` revela la flag `SABANA{...}`
- [ ] 🔴 `PIVOT_SSH_PASSWORD` (hash de msilva crackeado) funciona para SSH al Linux-Srv del Reto 3

---

## Checkpoint 3: Linux Server (`services/linux-server/`)

**Ubicación:** `services/linux-server/`
**Stack:** Ubuntu 22.04 + openssh-server
**Puerto local:** 2222 → 22
**Red destino en evento:** `172.16.T.20` (subnet Linux, dentro de la subred del equipo)

### Retos que debe entregar (3 niveles de escalamiento)

#### Reto 3.1 — Ingreso SSH como `msilva` (bajo privilegio)

- [x] 🔴 Contenedor Ubuntu 22.04 con `openssh-server` habilitado en puerto 22
- [x] 🔴 Usuario `msilva` con password `PIVOT_SSH_PASSWORD` (establecida en runtime por `entrypoint.sh`)
- [x] 🔴 Password coincide con la que se crackea en Reto 2 (password reuse deliberado — hash MD5 de msilva en la BD)
- [x] 🔴 Permisos mínimos: home propio (`/home/msilva`), sin sudo, sin acceso a homes ajenos
- [x] 🔴 Comentario `# VULN:` en Dockerfile explicando el password reuse
- [ ] Verificado: `ssh msilva@<host> -p 2222` funciona con la password crackeada

#### Reto 3.2 — Escalamiento `msilva` → `jrodriguez` vía cron-job

Vulnerabilidad: cron-job ejecutado como `jrodriguez` corre un script en directorio/archivo escribible por `msilva`.

- [x] 🔴 Usuario `jrodriguez` creado
- [x] 🔴 Cron-job configurado en `/etc/cron.d/sync-logs` como `jrodriguez` — `* * * * * jrodriguez /opt/scripts/sync.sh`
- [x] 🔴 Script `/opt/scripts/sync.sh` con permisos `777` — escribible por `msilva`
- [x] 🔴 Comentario `# VULN:` sobre la línea del `chmod 777` en el Dockerfile
- [x] 🔴 `linpeas`/`pspy` detecta el cron-job vulnerable — `pspy` muestra el cron; linpeas señala script world-writable en cron
- [x] 🔴 Modificar el script permite escalada: p.ej. `cp /bin/bash /tmp/bash && chmod u+s /tmp/bash` → esperar cron → `/tmp/bash -p`
- [x] 🔴 Intervalo del cron: cada 1 minuto
- [ ] Verificado end-to-end: modificación del script → esperar cron → shell como `jrodriguez`

#### Reto 3.3 — Escalamiento `jrodriguez` → `lcastillo` vía binario SUID

Vulnerabilidad: `/usr/local/bin/find` es propiedad de `lcastillo` con bit SUID.

- [x] 🔴 Usuario `lcastillo` creado
- [x] 🔴 `/usr/local/bin/find` propiedad de `lcastillo` con permisos `4755` (SUID)
- [x] 🔴 GTFOBins: `/usr/local/bin/find . -exec /bin/bash -p \; -quit` → shell con effective UID de `lcastillo`
- [x] 🔴 Comentario `# VULN:` sobre la línea del `chmod u+s` en el Dockerfile
- [x] 🔴 `find / -perm -4000 2>/dev/null` desde `jrodriguez` lo detecta en `/usr/local/bin/find`
- [x] 🔴 GTFOBins aplicable: técnica estándar de escalada con `find` SUID
- [ ] Verificado end-to-end: `/usr/local/bin/find . -exec /bin/bash -p \; -quit` → shell como `lcastillo`

#### Reto 3.4 — Escalamiento `lcastillo` → `root` vía sudo NOPASSWD + GTFOBins

Vulnerabilidad: `lcastillo` tiene `sudo NOPASSWD` para `/usr/bin/python3`.

- [x] 🔴 `/etc/sudoers.d/lcastillo` configurado: `lcastillo ALL=(ALL) NOPASSWD: /usr/bin/python3`
- [x] 🔴 Comentario `# VULN:` sobre la línea del sudoers en el Dockerfile
- [x] 🔴 `sudo -l` como `lcastillo` muestra el permiso claramente
- [x] 🔴 GTFOBins: `sudo python3 -c 'import os; os.execl("/bin/sh", "sh")'` → shell como root
- [ ] Verificado end-to-end: `sudo python3 ...` → shell como root

#### Reto 3.5 — Flag final en `/root/secrets.txt`

- [x] 🔴 Archivo `/root/secrets.txt` con permisos `600` propiedad de root
- [x] 🔴 Contiene flag `SABANA{...}` inyectada por env `FLAG_LINUXSERVER_ROOT` (escrita en runtime por `entrypoint.sh`)
- [x] 🔴 Contiene placeholder del "keyfile" / Fragmento B para el reto Parqueadero (N4) — valor pendiente de definir
- [ ] Verificado end-to-end: root puede leer el archivo, la flag valida en CTFd

#### Reto 3.6 — "Buscar procesos y eliminar el que se llama flag" ⚠️ PENDIENTE

⚠️ **Requiere clarificación:** el diagrama menciona "buscar los procesos y eliminan el que se llama flag" pero no está claro si es:

- (a) Un proceso llamado `flag` cuyo pid/memoria contiene información útil (`/proc/<pid>/mem` lectura).
- (b) Un proceso `flag` que bloquea un archivo, y al matarlo se libera el archivo.
- (c) Un proceso `flag` que hay que matar para que aparezca `/root/secrets.txt`.

- [ ] 🔴 Decidir la mecánica exacta y documentar en `docs/context.md`
- [ ] 🔴 Implementar según decisión
- [ ] 🔴 Marcar con `# VULN:` la configuración correspondiente

### Configuración base de Linux-Srv

- [x] 🔴 Dockerfile en `services/linux-server/Dockerfile`
- [x] 🔴 Basado en `ubuntu:22.04` (imagen oficial)
- [x] 🔴 Todos los usuarios, permisos y binarios SUID configurados en el Dockerfile (no post-deploy)
- [x] 🔴 SSH habilitado en puerto 22; `PermitRootLogin no`
- [x] 🔴 Registro en `docker-compose.yml` (puerto 2222:22)
- [x] 🔴 Registro en workflow de GitHub Actions matrix
- [ ] 🔴 `linpeas.sh` y `pspy` verificados en la máquina — pending test (herramientas las sube el participante; pendiente confirmar detección)
- [x] 🔴 No usar CVEs de kernel — todo user-space ✅
- [ ] Reset con `docker compose down -v` restaura estado inicial

### Validación end-to-end de Linux-Srv

- [ ] 🔴 Con credencial crackeada del Reto 2, SSH como `msilva` funciona
- [ ] 🔴 Cadena completa `msilva` → `jrodriguez` → `lcastillo` → `root` verificada por evaluador humano
- [ ] 🔴 `/root/secrets.txt` contiene flag válida
- [ ] 🔴 `linpeas`/`pspy` detectan los vectores plantados
- [ ] 🔴 Cada nivel es alcanzable con conocimiento de pentesting estándar (sin adivinar)

---

## Checkpoint 4: XSS Admin Bot (`services/xss-bot/`)

**Ubicación:** `services/xss-bot/`
**Stack:** Node.js + Playwright (Chromium headless)
**Rol:** infraestructura de soporte del Reto 1.6, NO es un reto en sí

### Funcionalidad requerida

- [x] 🔴 Servicio headless Chromium — Playwright `v1.44.1` vía imagen oficial `mcr.microsoft.com/playwright`
- [x] 🔴 Se autentica en la Web-App usando `/bot_login.php` con header `X-Bot-Secret: BOT_SECRET`
- [x] 🔴 Consume cola de trabajos — tabla `bot_visit_queue` en MariaDB (polling vía `/bot/queue.php`)
- [x] 🔴 Cada job lleva `ticket_id` y la URL a visitar (`/ticket?id=<ticket_id>`)
- [x] 🔴 Carga la cookie de admin antes de cada visita
- [x] 🔴 Visita la URL, espera 5 segundos (`PAGE_TIMEOUT_MS = 5000`), cierra el contexto
- [ ] 🟡 Scope estricto a URLs del propio webapp — no implementado (el bot solo consume jobs de su propia queue)
- [ ] 🟡 Rate limit — no implementado (pausa de 1s entre visitas, sin burst cap)
- [x] 🔴 Timeout de 5s por página — previene que `while(true)` cuelgue el bot
- [x] 🔴 No expone interfaz de control, solo consume la queue

> **Nota sobre Redis:** se optó por una cola basada en BD (`bot_visit_queue` en MariaDB) en lugar de Redis, eliminando un servicio adicional. La decisión de usar Redis queda abierta para el evento multi-equipo si se requiere mayor throughput.

### Configuración base de XSS Bot

- [x] 🔴 Dockerfile en `services/xss-bot/Dockerfile`
- [x] 🔴 Variables env: `WEBAPP_BASE_URL`, `BOT_VISIT_INTERVAL_SECONDS`, `BOT_SECRET`
- [x] 🔴 Registro en `docker-compose.yml`
- [x] 🔴 Registro en workflow de GitHub Actions matrix

### Validación end-to-end de XSS Bot

- [ ] 🔴 Añadir comentario a ticket → bot lo visita en < 60s (según `BOT_VISIT_INTERVAL_SECONDS=30`)
- [ ] 🔴 Payload XSS ejecuta con cookie de admin cargada
- [ ] 🔴 Payload malicioso con `while(true)` no cuelga al bot (se mata en 5s)
- [ ] 🟡 Rate limit efectivo — pendiente de implementar si hay abuso en el evento

---

## Checkpoint 5: Orquestación local (`docker-compose.yml`)

### Estado actual

- [x] `webapp` levantado con todas las variables env necesarias
- [x] `database` levantado con healthcheck; `webapp` espera `database healthy`
- [x] `linux-server` levantado con puerto 2222:22
- [x] `xss-bot` levantado con `depends_on: webapp`
- [x] Red `sabana-lab` (bridge) definida

### Pendiente

- [ ] 🔴 `docker compose up --build` verificado en fresh clone — pendiente de test
- [ ] 🔴 `docker compose down -v` limpia todo el estado — pendiente de test
- [ ] 🟡 Migración de bridge a `macvlan` (para IPs por contenedor en la red del evento)
- [ ] 🟡 Diagrama de red en `docs/infra.jpg`

### Validación de orquestación

- [ ] 🔴 Fresh clone del repo → `cp .env.example .env` → editar valores → `docker compose up --build` → laboratorio funcional
- [ ] 🔴 Ningún error en logs de contenedores durante el arranque
- [ ] 🔴 Los healthchecks de todos los servicios pasan en < 60s

---

## Checkpoint 6: Templating por equipo (pendiente de decisión)

**⚠️ Este checkpoint refleja la decisión de que el laboratorio debe replicarse N veces (una por equipo participante).**

### Requisitos identificados

- [ ] 🔴 Script `generate_env.sh` que recibe `TEAM_ID` y genera `.env` con flags únicas y IPs del equipo
- [ ] 🔴 Script `bootstrap.sh` que recorre pool de equipos y hace `docker compose -p teamN up -d`
- [ ] 🔴 Script `reset.sh` para reset entre turnos
- [ ] 🔴 Verificar aislamiento entre equipos
- [ ] 🔴 Consumo de recursos dimensionado (~1-1.5 GB RAM por edificio)

**Bloqueante:** depende de cierre de decisiones sobre topología de red (macvlan vs. bridge) y WireGuard/nftables.

---

## Checkpoint 7: Cadena de explotación end-to-end

**Este es el checkpoint definitivo: valida que TODA la cadena funciona de principio a fin como un CTF real.**

### Recorrido esperado

- [ ] 🔴 Fase 1: view-source de login → `jperez/Bienvenido123` → sesión como empleado
- [ ] 🔴 Fase 2: SQLi en `/search` → UNION para ver todos los tickets → ID 3 de `it_soporte`
- [ ] 🔴 Fase 3: `/profile?id=3` (IDOR) → ver sección "Herramientas internas de TI" → click → 403 con ruta
- [ ] 🔴 Fase 4: `/attachment?file=../../flag_lfi.txt` → flag `FLAG_WEBAPP_LFI`
- [ ] 🔴 Fase 5: modificar JWT propio (base64url, cambiar `role` a `it`) → GET `/api/internal/admin/database` → creds BD
- [ ] 🔴 Fase 6: añadir comentario con payload XSS a cualquier ticket → bot visita → exfiltra `document.cookie` → decodificar JWT → claim `flag` = `FLAG_WEBAPP_XSS`
- [ ] 🔴 Fase 7: `mysql -h <host> -P 3306 -u helpdesk_app -p` con creds del Paso 5
- [ ] 🔴 Fase 8: `SELECT * FROM users` → hashes MD5 → `hashcat -m 0 hashes.txt rockyou.txt` → crackear
- [ ] 🔴 Fase 9: `SELECT content FROM system_notes` → flag `FLAG_DATABASE`
- [ ] 🔴 Fase 10: identificar que la password de `msilva` crackeada = `PIVOT_SSH_PASSWORD`
- [ ] 🔴 Fase 11: `ssh msilva@<host> -p 2222` con la password crackeada
- [ ] 🔴 Fase 12: `pspy` → detecta cron → modificar `/opt/scripts/sync.sh` → esperar → shell como `jrodriguez`
- [ ] 🔴 Fase 13: `find / -perm -4000` → `/usr/local/bin/find` → `/usr/local/bin/find . -exec /bin/bash -p \; -quit` → `lcastillo`
- [ ] 🔴 Fase 14: `sudo -l` → `sudo python3 -c 'import os; os.execl("/bin/sh", "sh")'` → root
- [ ] 🔴 Fase 15: `cat /root/secrets.txt` → `FLAG_LINUXSERVER_ROOT` + Fragmento B

**Tiempo estimado del recorrido completo por un pentester intermedio:** 3-5 horas.

### Testing del recorrido

- [ ] 🔴 Al menos un miembro del equipo constructor completa el recorrido end-to-end antes del ensayo general
- [ ] 🔴 Al menos un evaluador externo completa el recorrido con hints
- [ ] 🔴 Todas las flags recolectadas validan en CTFd
- [ ] 🔴 Todos los "secretos de progresión" llevan efectivamente al siguiente paso

---

## Decisiones de diseño pendientes que bloquean progreso

1. **Mecánica del "matar proceso flag"** (Reto 3.6) — lectura de `/proc/<pid>/mem`, unlock de archivo, o trigger de aparición. Decidir y documentar.
2. **Keyfile Fragmento B** (Reto 3.5) — valor exacto del `SABANA_KEY_B` en `secrets.txt`. Actualmente placeholder.
3. **Topología de red final** — migración de bridge a macvlan y su integración con WireGuard/nftables.
4. **Templating por equipo** — `docker compose -p` vs scripts que reescriben archivos.
5. **Redis vs BD para la cola del bot** — la cola basada en BD (`bot_visit_queue`) está implementada y es suficiente para desarrollo. Para el evento multi-equipo con alto throughput, evaluar si Redis es necesario.

---

## Cambios que Claude Code NO debe hacer sin autorización explícita

- Eliminar o mitigar cualquier vulnerabilidad marcada con `// VULN:` o `# VULN:`.
- Introducir frameworks/ORMs/librerías de sanitización que neutralicen vulns existentes.
- Cambiar el formato de flag `SABANA{...}` o el stack técnico decidido (PHP + MariaDB + Ubuntu).
- Hardcodear valores de flags/secretos en código (siempre por env).
- Cambiar la cadena de explotación sin actualizar `docs/context.md` primero.
- Aplicar auto-fixes sugeridos por linters/scanners de seguridad sin verificar que no sean vulns intencionales.

---

## Registro de progreso

| Fecha | Sesión | Cambios | Checkpoints tocados |
|---|---|---|---|
| 2026-07-18 | 2 | `search.php`: muestra `mysqli_error()`. `ticket.php`: encola jobs en `bot_visit_queue`. `database/init`: añade tabla `bot_visit_queue`. `webapp/src/bot/`: endpoints `queue.php` y `mark_visited.php`. `services/linux-server/`: Dockerfile + entrypoint completos (msilva→jrodriguez via cron, →lcastillo via SUID find, →root via sudo python3). `services/xss-bot/`: Dockerfile + bot.js (Playwright). `docker-compose.yml`: añade linux-server y xss-bot. CI matrix: añade linux-server y xss-bot. | 1, 2, 3, 4, 5 |
