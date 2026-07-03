# Contexto del laboratorio — Sabana Corp Network

Este documento es la **fuente de verdad** del dominio del proyecto: qué es el laboratorio, por qué
existe, cómo se resuelve, y por qué cada vulnerabilidad está donde está. Todo cambio de diseño se
refleja aquí. Las reglas de proceso (convenciones de código, Docker, CI/CD) viven en `CLAUDE.md`; este
archivo se centra en la narrativa y la arquitectura del laboratorio en sí.

> Estado: diseño inicial, sin código implementado todavía. Este documento describe el laboratorio tal
> como se va a construir; a medida que se implemente, debe mantenerse sincronizado con la realidad del
> código.

## Historia y narrativa

**Sabana Corp** es una empresa ficticia con una mesa de ayuda interna (sistema de tickets) usada por
empleados para reportar incidentes de TI. El participante entra al laboratorio como un atacante externo
sin credenciales, con únicamente la URL pública de la aplicación de tickets.

La narrativa sigue la lógica de un compromiso real de infraestructura:

1. El atacante encuentra una debilidad en la aplicación web pública (Sabana Corp Helpdesk).
2. Usa esa debilidad para escalar privilegios dentro de la propia aplicación hasta llegar a
   funcionalidad interna reservada al personal de TI.
3. Esa funcionalidad interna filtra las credenciales de la base de datos que respalda la aplicación.
4. Dentro de la base de datos, el atacante recupera contraseñas de empleados almacenadas de forma
   insegura. Una de ellas es la credencial SSH de un usuario de bajo privilegio en el servidor Linux
   que aloja la infraestructura.
5. Desde ahí, una cadena clásica de escalamiento de privilegios en Linux (cron mal configurado → sudo
   sin contraseña vía GTFOBins) lleva al atacante hasta `root`, donde encuentra la evidencia final del
   compromiso (`secrets.txt`).

El laboratorio está diseñado para sentirse como **una sola intrusión continua**, no como tres retos
sueltos. Cada paso debe justificar narrativamente el siguiente.

## Objetivos pedagógicos

- Practicar una cadena de explotación web completa: credenciales expuestas → SQLi → IDOR → LFI → JWT
  inseguro → XSS almacenado.
- Practicar cracking de hashes de contraseñas sin salt/con algoritmo débil.
- Practicar enumeración y escalamiento de privilegios en Linux: cron jobs, binarios con permisos
  especiales, sudo sin contraseña, GTFOBins.
- Reforzar que cada vulnerabilidad, aislada, puede parecer de bajo impacto, pero encadenada con otras
  compromete la infraestructura completa — la lección central de "defense in depth".

## Arquitectura general

Tres servicios independientes, cada uno en su propio contenedor Docker, conectados por la cadena de
explotación descrita arriba (no por llamadas de red directas entre ellos — la conexión es de
**información**, no de tráfico):

```
┌─────────────────────┐        ┌─────────────────────┐        ┌─────────────────────┐
│   Web Application    │        │     Base de Datos     │        │     Linux Server      │
│  (PHP + MariaDB)      │──────▶│      (MariaDB)          │──────▶│   (Ubuntu + SSH)       │
│  Sistema de tickets   │ creds │  Usuarios/contraseñas   │  SSH  │  Escalamiento local   │
│  Sabana Corp Helpdesk │       │  con hash débil/sin salt │ creds │  hasta root            │
└─────────────────────┘        └─────────────────────┘        └─────────────────────┘
     Reto 1 (6 vulns              Reto 2 (cracking de              Reto 3 (enumeración +
     encadenadas)                  hashes)                          escalamiento de privilegios)
```

- La Web Application se conecta en runtime a la Base de Datos (es su backend real), pero el
  **participante nunca alcanza la base de datos por la red directamente** — llega a sus credenciales a
  través de un endpoint interno filtrado por la propia aplicación web. Una vez tiene las credenciales,
  se conecta él mismo a la base de datos expuesta (puerto publicado) para extraer y crackear los hashes.
- El Linux Server no tiene relación de red con los otros dos servicios; es la máquina donde el
  participante usa la credencial SSH obtenida en el Reto 2. Puede modelarse como el host que alojaría
  en la vida real tanto la Web Application como la Base de Datos, aunque en el laboratorio cada uno
  corre en su propio contenedor por simplicidad de despliegue y aislamiento entre participantes.
- Red: la propuesta final usa `macvlan` para dar IP propia a cada contenedor dentro de la red física del
  edificio de la Semana de Ingeniería (diagrama en `docs/infra.jpg`, **pendiente de añadir**). Hasta que
  ese diagrama exista, se documenta aquí la intención pero no se bloquea el desarrollo de los retos por
  la topología final de red (ver convención en `CLAUDE.md`).
- Además de los 3 retos, existe un cuarto contenedor de **soporte** (`services/xss-bot`) que no es un
  reto ni se puntúa: simula a un usuario administrador visitando tickets en un navegador real para que
  el Stored XSS del Reto 1 tenga un objetivo real que exfiltrar. Ver decisión de diseño #6 más abajo.

## Servicios y vulnerabilidades

### Reto 1 — Web Application (Sabana Corp Helpdesk)

Sistema de tickets con tres perfiles: **Visitante**, **Empleado**, **Personal de TI**. Stack: PHP +
MariaDB (ver decisión de stack en `CLAUDE.md`).

La cadena dentro de este único reto tiene 6 pasos, cada uno desbloqueando el siguiente:

| # | Vulnerabilidad | Qué hace el participante | Qué obtiene |
|---|---|---|---|
| 1 | Credenciales expuestas | Inspecciona el código fuente HTML/JS del login | Usuario y contraseña de prueba (nivel Empleado) |
| 2 | SQL Injection | Explota el buscador de tickets con input no sanitizado | Acceso a tickets asignados a personal de TI, incluido el **ID de un usuario privilegiado** |
| 3 | IDOR | Cambia el parámetro `id` en `/profile?id=12` | Perfil del usuario de TI; dentro aparecen enlaces internos protegidos |
| 4 | Descubrimiento de endpoint | Intenta acceder a un enlace interno sin privilegio suficiente | El error revela la ruta real: `/api/internal/admin/database` |
| 5a | LFI | Explota el parámetro de archivo adjunto de un ticket | Lectura de archivos del servidor (flags/secretos/pistas) |
| 5b | JWT inseguro | Modifica el claim `role` del JWT (`user` → `it`/`admin`) sin que el backend valide la firma correctamente | Acceso al endpoint `/api/internal/admin/database` descubierto en el paso 4 → **credenciales de la Base de Datos** |
| 6 | Stored XSS | Inyecta un payload en un comentario de ticket | Exfiltra la cookie de sesión de otro usuario (contiene una flag de este reto) |

Notas de diseño:
- Los pasos 2→3→4 son estrictamente secuenciales (cada uno depende del anterior). Los pasos 5a (LFI) y
  5b (JWT) son en gran medida independientes entre sí — ambos parten del contexto ya alcanzado en el
  paso 4 — pero **5b es el que entrega el secreto que hace avanzar al Reto 2** (credenciales de BD). El
  LFI (5a) es una vía adicional de obtención de pistas/flags dentro del mismo reto, no un requisito
  estricto para llegar al Reto 2.
- El Stored XSS (paso 6) es la flag de puntuación del Reto 1; no es un requisito para avanzar al Reto 2
  (que depende del paso 5b). Esto evita que un participante quede bloqueado en el reto siguiente por no
  haber resuelto el XSS.
- **Decisión pendiente de implementación concreta**: el mecanismo exacto de "JWT inseguro" (alg `none`
  aceptado, verificación de firma deshabilitada, o secreto débil/hardcodeado) debe fijarse al escribir
  el código y documentarse aquí como decisión de diseño (ver sección más abajo).

### Reto 2 — Base de Datos

MariaDB con las credenciales obtenidas en el paso 5b del Reto 1. Contiene una tabla de usuarios cuyas
contraseñas están almacenadas con un algoritmo de hash inseguro.

- **Decisión de diseño**: usar **MD5 sin salt**. Es el ejemplo más reconocible de "almacenamiento de
  contraseñas inseguro" en material didáctico, es crackeable en segundos con `hashcat`/rainbow tables o
  incluso búsquedas directas en bases de datos públicas de hashes, y no requiere que el participante
  tenga hardware potente — mantiene el reto accesible en el contexto de un evento universitario de
  duración limitada.
  - Alternativa considerada: SHA-1 sin salt. Descartada por no aportar ninguna diferencia pedagógica
    frente a MD5 y ser marginalmente más lenta de crackear sin beneficio adicional.
  - Alternativa considerada: cifrado reversible (no hash). Descartada por ser menos realista — en
    incidentes reales el error casi siempre es "hash débil", no "contraseña en texto plano o cifrada
    reversiblemente".
- **Decisión de diseño**: una de las contraseñas crackeadas es la credencial SSH de un usuario de bajo
  privilegio del Reto 3 (Linux Server). Esta es la conexión que hace avanzar la cadena.
  - El resto de contraseñas de la tabla pueden ser "ruido" realista (otros empleados ficticios) o
    combinarse para formar una flag de puntuación de este reto (p. ej. concatenar 3 contraseñas
    específicas, identificables por un campo o rol en la tabla). Se recomienda la opción de "ruido +
    una flag de puntuación separada" antes que "concatenar contraseñas para formar la flag", porque
    depende del orden exacto de filas y es frágil ante cualquier cambio futuro en el seed de datos.

### Reto 3 — Linux Server

Ubuntu Server en Docker, accesible por SSH con la credencial obtenida en el Reto 2. Progresión de
escalamiento de privilegios:

1. Enumeración inicial del sistema (usuarios, procesos, permisos del usuario actual).
2. Descubrir que el usuario puede crear o modificar un cron job con permisos limitados.
3. Usar ese cron para ejecutar código como un segundo usuario y obtener sus privilegios.
4. Enumerar de nuevo desde la posición del segundo usuario.
5. Encontrar binarios con permisos especiales (SUID) o configuración interesante.
6. Detectar una entrada de `sudo` sin contraseña (`NOPASSWD`) para un binario específico.
7. Usar GTFOBins para ese binario y escalar hasta `root`.
8. Encontrar `secrets.txt` en un directorio de `root` con la **flag final del laboratorio**.

Este reto es intencionalmente el más "clásico" de escalamiento Linux (equivalente a una máquina
fácil/media de plataformas tipo HackTheBox), porque su función pedagógica es consolidar, no introducir
conceptos nuevos — el laboratorio ya gastó su presupuesto de "novedad" en el Reto 1.

## Flujo esperado de resolución (resumen para el participante)

1. Abrir la Web Application → ver el código fuente del login → obtener credencial de Empleado.
2. Iniciar sesión como Empleado → usar el buscador de tickets con SQLi → encontrar el ID de un usuario
   de TI.
3. Visitar `/profile?id=<ID de TI>` (IDOR) → ver enlaces internos → intentar acceder → la respuesta de
   error revela `/api/internal/admin/database`.
4. Modificar el JWT propio para tener `role=it` (o `admin`) → acceder al endpoint interno → obtener
   credenciales de la Base de Datos.
5. (Opcional, en paralelo) Explotar el LFI de los adjuntos de tickets para encontrar pistas/flags
   adicionales del servidor web.
6. (Opcional, flag de puntuación del Reto 1) Explotar el Stored XSS en comentarios para robar una
   cookie de sesión con una flag.
7. Conectarse a la Base de Datos con las credenciales del paso 4 → volcar la tabla de usuarios →
   crackear los hashes MD5 → identificar la credencial SSH del Reto 3.
8. Conectarse por SSH al Linux Server → enumerar → escalar vía cron a un segundo usuario → enumerar de
   nuevo → encontrar sudo `NOPASSWD` sobre un binario → usar GTFOBins → obtener `root` → leer
   `secrets.txt` → **flag final**.

## Esquema de flags y secretos

Ver también la sección correspondiente en `CLAUDE.md` (reglas de proceso). Resumen del esquema:

- **Flags de puntuación** (formato `SABANA{...}`, una por reto, entregadas vía variable de entorno,
  nunca commiteadas):
  - `FLAG_WEBAPP_XSS` — obtenida robando una cookie de sesión vía el Stored XSS del Reto 1.
  - `FLAG_WEBAPP_LFI` (opcional) — obtenida leyendo un archivo específico vía el LFI del Reto 1.
  - `FLAG_DATABASE` — obtenida al crackear un subconjunto específico de hashes en el Reto 2.
  - `FLAG_LINUXSERVER_ROOT` — la flag final, en `secrets.txt`, tras escalar a `root` en el Reto 3.
- **Secretos de progresión** (no tienen formato de flag, son necesarios para avanzar, no se puntúan por
  sí solos):
  - Credencial de Empleado (del comentario HTML/JS del login).
  - ID del usuario de TI (de la SQLi).
  - Ruta del endpoint interno (`/api/internal/admin/database`, revelada por el error de IDOR).
  - Credenciales de la Base de Datos (del endpoint interno tras el bypass de JWT).
  - Credencial SSH del Reto 3 (de un hash crackeado en el Reto 2).

## Decisiones de diseño (registro)

Esta sección acumula decisiones arquitectónicas no triviales, con alternativas consideradas. Añadir una
entrada nueva cada vez que se tome una decisión de este tipo — no sobreescribir entradas anteriores.

1. **Alcance de servicios**: el laboratorio tiene 3 servicios/retos (Web App, Base de Datos, Linux
   Server). Se descartó un cuarto servicio de tipo "cPanel" que estaba en una versión anterior del
   brief — decisión del propietario del proyecto, sin alternativas técnicas evaluadas.
2. **Stack de la Web Application**: PHP + MariaDB (ver justificación en `CLAUDE.md`, sección "Stack
   técnico").
3. **Algoritmo de hash en la Base de Datos**: MD5 sin salt (ver justificación en la sección del Reto 2
   arriba).
4. **Manejo de flags y secretos de progresión**: fuera de git, vía variables de entorno / secretos de
   despliegue; `.env.example` versionado solo con nombres de variable y valores ficticios. Esta regla se
   extiende también a secretos de progresión que sean específicos de un despliegue concreto (p. ej.
   `PIVOT_SSH_PASSWORD`, `BOT_SECRET`) — no solo a las flags `SABANA{...}` — para que el mismo repositorio
   pueda reutilizarse en futuras ediciones del evento sin arrastrar los secretos de la edición anterior.
   Las contraseñas MD5 "de ruido" de empleados en el Reto 2 son la única excepción: son intencionalmente
   débiles y crackeables por diseño, así que sí viven como seed data en el repositorio (no son secretos,
   son la vulnerabilidad en sí misma).
5. **Mecanismo del JWT inseguro**: verificación de firma deshabilitada en el backend (el servidor decodifica
   el payload del JWT pero nunca recalcula/compara el HMAC contra la firma recibida). Se eligió sobre
   `alg: none` porque no depende de que la librería cliente permita construir un token con ese algoritmo,
   y es el caso más común y realista de "JWT inseguro" en aplicaciones reales.
6. **Servicio de soporte "xss-bot"**: se añade un cuarto contenedor, `services/xss-bot`, que **no es un
   reto nuevo** sino infraestructura necesaria para que el Stored XSS del Reto 1 sea explotable de forma
   realista. Es un script Node + Puppeteer (Chromium real) que se autentica contra un endpoint interno
   (`bot_login.php`, protegido por el secreto `BOT_SECRET`, no por contraseña de usuario) y visita
   periódicamente las páginas de tickets, ejecutando en un navegador real cualquier payload almacenado.
   La cookie de sesión que obtiene el bot lleva un claim `flag` con `FLAG_WEBAPP_XSS` dentro del JWT, y la
   cookie se emite sin `HttpOnly` (vulnerabilidad deliberada) para que un XSS pueda leerla con
   `document.cookie` y exfiltrarla.
   - Alternativa considerada: simular la visita del admin sin navegador real (p. ej. con `curl`).
     Descartada porque no ejecuta JavaScript y el Stored XSS quedaría sin payoff real.
   - Alternativa considerada: dejar que el participante inicie sesión como "admin" con una contraseña
     crackeada de la tabla `users` del Reto 2. Descartada porque permitiría saltarse el Reto 1 (XSS) por
     completo si el participante llega primero a la Base de Datos — el bot usa una credencial separada
     (`BOT_SECRET`) que nunca aparece en la tabla `users`.
7. **Persistencia de FLAG_DATABASE**: en vez de que la flag de puntuación del Reto 2 sea ella misma un
   hash a crackear (inviable: un flag largo y aleatorio no es crackeable por diccionario), se guarda en
   texto plano en una tabla adicional `system_notes`, poblada al arrancar el contenedor de base de datos
   sustituyendo la variable de entorno `FLAG_DATABASE` en una plantilla SQL. El participante la obtiene
   simplemente por haber alcanzado acceso de lectura a la base de datos con las credenciales filtradas.
8. **Pendiente de decidir**:
   - Contenido exacto de `docs/infra.jpg` (diagrama de red `macvlan`) — pendiente de que el propietario
     del proyecto lo aporte o lo describa con más detalle. La topología `macvlan` no se ha aplicado
     todavía al `docker-compose.yml` de desarrollo (que usa una red bridge estándar); es una tarea
     explícita antes de dar el laboratorio por listo para el evento.
