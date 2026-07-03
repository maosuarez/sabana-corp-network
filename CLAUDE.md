# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Propósito del proyecto

Este repositorio implementa un **laboratorio de ciberseguridad (CTF/Lab)** para la Semana de Ingeniería
de la Universidad de La Sabana. El objetivo **no** es construir software seguro: es construir un entorno
de entrenamiento con vulnerabilidades **deliberadas, controladas y documentadas** que los participantes
deben encadenar para avanzar de un servicio a otro.

Estado actual: proyecto en fase de diseño. El repositorio aún no contiene código de los retos; este
archivo y `docs/context.md` son la base sobre la que se implementará todo lo demás.

## Filosofía del laboratorio

- **La inseguridad es la funcionalidad.** Cada vulnerabilidad existe a propósito y forma parte del
  diseño pedagógico. Nunca se "arregla" una vulnerabilidad del laboratorio como si fuera un bug real.
- **Cadena de explotación, no retos aislados.** Cada servicio debe alimentar al siguiente con una
  credencial, secreto o pista. Si un cambio rompe ese hilo, rompe el laboratorio completo.
- **Realismo por encima de artificialidad.** Se prefiere una vulnerabilidad que ocurra de forma
  natural en el stack elegido (p. ej. `include($_GET['file'])` para LFI en PHP) sobre un mecanismo
  forzado que no se parezca a un error real de desarrollo.
- **Reproducibilidad total.** Todo el laboratorio se levanta con Docker. Un evaluador o instructor debe
  poder desplegar el entorno completo sin pasos manuales fuera de Docker Compose / CI.
- **Extensibilidad.** El repositorio debe permitir añadir nuevos retos o servicios sin reescribir los
  existentes.

## Arquitectura general

El laboratorio está compuesto por **tres servicios independientes**, cada uno en su propio contenedor,
conectados mediante una cadena lógica de ataque:

```
Web Application  --(SQLi + IDOR + JWT)-->  credenciales de Base de Datos
Base de Datos    --(hashes sin salt)-->     credencial SSH de Linux Server
Linux Server     --(cron + sudo/GTFOBins)--> root + secrets.txt (flag final)
```

> Nota: una versión anterior de este documento contemplaba un cuarto servicio ("cPanel"). Se descartó:
> el laboratorio tiene **3 retos**: Web Application, Base de Datos y Linux Server. Si en el futuro se
> reintroduce un servicio de panel de control, debe documentarse aquí su rol exacto en la cadena antes
> de implementarlo — no debe existir un servicio sin una vulnerabilidad y una conexión definidas.
>
> Existe además un **cuarto contenedor que no es un reto**: `services/xss-bot`, infraestructura de
> soporte que hace explotable el Stored XSS del Reto 1 (ver `docs/context.md`, decisión de diseño #6).
> No lo cuentes como un "servicio del laboratorio" al hablar de la cadena de explotación con
> participantes — es transparente para ellos.

Ver `docs/context.md` para el detalle narrativo, la explicación de cada vulnerabilidad y el flujo de
resolución completo.

### Stack técnico (decisión registrada)

- **Web Application**: PHP + MySQL/MariaDB, sin framework, sin ORM, consultas SQL construidas a mano.
  - Motivo: es el stack donde las vulnerabilidades pedidas ocurren de forma más idiomática — `include()`
    para LFI, `mysqli_query` con concatenación de strings para SQLi, `echo` sin `htmlspecialchars` para
    XSS almacenado, y una librería JWT mal configurada (verificación de firma deshabilitada o algoritmo
    `none` aceptado) para el reto de JWT. Evita tener que "inventar" vulnerabilidades forzadas en un
    stack que por defecto es más seguro (p. ej. Node/Express con un ORM parametrizado).
  - Servido con Apache + `mod_php` o PHP-FPM detrás de Apache/Nginx dentro del mismo contenedor;
    mantener esto simple (un solo proceso web por contenedor) salvo que surja una razón concreta para
    separar PHP-FPM y el servidor web.
- **Base de Datos**: MySQL o MariaDB (usar MariaDB por defecto, imagen oficial más ligera).
- **Linux Server**: Ubuntu Server (imagen oficial `ubuntu:22.04` o similar) con `openssh-server`,
  usuarios y permisos configurados vía el `Dockerfile` del servicio, no vía scripts manuales post-deploy.

## Estructura del repositorio

```
/
├── CLAUDE.md                 # este archivo — memoria permanente del proyecto
├── README.md                 # presentación breve para humanos
├── docs/
│   ├── context.md            # fuente de verdad del dominio (narrativa, arquitectura, vulnerabilidades)
│   └── infra.jpg             # diagrama de red (pendiente de añadir)
├── services/
│   ├── webapp/                # reto 1 — PHP + MySQL client
│   │   ├── Dockerfile
│   │   └── src/
│   ├── database/               # reto 2 — MariaDB con seed de usuarios/hashes
│   │   ├── Dockerfile
│   │   └── init/               # scripts .sql de inicialización
│   ├── linux-server/            # reto 3 — Ubuntu SSH con cadena de escalamiento
│   │   └── Dockerfile
│   └── xss-bot/                  # soporte (no es un reto) — dispara el Stored XSS del reto 1
│       └── Dockerfile
├── docker-compose.yml          # orquestación local de los 3 servicios
├── .env.example                 # plantilla de variables (flags, credenciales) SIN valores reales
└── .github/workflows/            # CI/CD de build y publicación de imágenes
```

Cuando se cree un nuevo servicio, debe seguir este mismo patrón: carpeta propia en `services/`,
`Dockerfile` propio, y una entrada correspondiente en `docker-compose.yml`.

## Convenciones de código

- Código de los retos deliberadamente vulnerable **debe** llevar un comentario `// VULN:` o `# VULN:`
  inmediatamente encima de la línea vulnerable, explicando en una frase qué vulnerabilidad es y qué
  reto la usa. Esto es lo único que se comenta de forma obligatoria en este repo — es lo que impide que
  una futura sesión de Claude "arregle" el bug sin darse cuenta.
  ```php
  // VULN: SQLi intencional (Reto Web #2) — input concatenado sin sanitizar
  $result = mysqli_query($conn, "SELECT * FROM tickets WHERE subject LIKE '%$search%'");
  ```
- Fuera de las líneas vulnerables, el código de soporte (routing, layout, seed data) debe ser simple y
  legible — no hace falta que sea "buena práctica" de producción, pero sí debe ser fácil de seguir para
  un instructor que revise el reto.
- No introducir frameworks, ORMs o librerías de sanitización que neutralicen accidentalmente una
  vulnerabilidad planeada (p. ej. un ORM parametrizado eliminaría la SQLi del reto).
- Nombres de archivos, rutas y endpoints deben ser descriptivos y realistas (p. ej. `/api/internal/admin/database`,
  no `/api/x1`), porque parte del reto es que el participante deduzca la existencia de rutas a partir de
  pistas parciales.

## Reglas de documentación

- `docs/context.md` es la **fuente de verdad** del dominio: narrativa, arquitectura, progresión y
  explicación de cada vulnerabilidad. Cualquier cambio de diseño (nueva vulnerabilidad, reordenar la
  cadena, cambiar un stack) se documenta ahí antes o junto con el cambio de código.
- Este archivo (`CLAUDE.md`) documenta reglas de **proceso y convención**, no detalles narrativos del
  laboratorio — esos van en `docs/context.md`.
- Toda decisión arquitectónica no trivial (elección de stack, esquema de flags, orden de la cadena,
  mecanismo de una vulnerabilidad) se registra en `docs/context.md` bajo una sección de "Decisiones de
  diseño", incluyendo alternativas consideradas y por qué se descartaron.
- Si detectas una inconsistencia entre lo documentado y lo implementado, o una mejor cadena de
  explotación posible, **propón el cambio explícitamente antes de aplicarlo** — no lo implementes en
  silencio.

## Convenciones para Docker

- Un `Dockerfile` por servicio, ubicado en `services/<nombre>/Dockerfile`. Nada de un `Dockerfile`
  monolítico para todo el laboratorio.
- `docker-compose.yml` en la raíz orquesta los servicios para desarrollo local. Debe permitir levantar
  todo el laboratorio con `docker compose up --build`.
- Variables sensibles (flags, contraseñas semilla, secretos de JWT) se inyectan vía variables de entorno
  definidas en `.env` (no versionado). `.env.example` sí se versiona, con los nombres de variable y
  valores de ejemplo obviamente falsos (p. ej. `FLAG_WEBAPP=SABANA{ejemplo_no_es_la_flag_real}`).
- Red: el diseño final usará `macvlan` para que cada contenedor tenga IP propia en la red física del
  edificio (ver `docs/infra.jpg`, pendiente). Mientras ese diagrama no esté disponible, el
  `docker-compose.yml` de desarrollo puede usar una red bridge estándar de Compose; no bloquear el
  desarrollo de los retos por la topología de red final, pero dejar la migración a `macvlan` como tarea
  explícita antes de considerar el laboratorio listo para el evento.
- Cada imagen debe poder reconstruirse desde cero sin pasos manuales (todo seed data, usuarios,
  permisos y configuración van en el `Dockerfile` o en scripts de inicialización versionados).

## Convenciones para CI/CD

- GitHub Actions construye y publica las imágenes en Docker Hub tras cada push a `main`.
- Un workflow por servicio (o un workflow con matrix sobre los servicios en `services/*`), para que un
  cambio en un solo reto no obligue a reconstruir los demás innecesariamente.
- Convención de tags de imagen: `<usuario-dockerhub>/sabana-lab-<servicio>:latest` y
  `<usuario-dockerhub>/sabana-lab-<servicio>:<sha-corto>`. El tag `latest` siempre apunta al último
  build exitoso de `main`; el tag por commit permite reproducir una versión exacta del laboratorio para
  una edición pasada del evento.
- Los workflows nunca deben requerir o exponer los valores reales de las flags — el build de imagen usa
  `.env.example` o build args de ejemplo; los valores reales de producción se inyectan solo en el
  despliegue real del evento, fuera de CI.

## Convenciones para escribir retos

- Cada reto nuevo debe responder, antes de escribirse una sola línea de código:
  1. ¿Qué vulnerabilidad de OWASP/clase de ataque representa?
  2. ¿Qué información entrega al participante (credencial, ruta, ID, archivo)?
  3. ¿De qué reto anterior depende esa información, y a qué reto siguiente alimenta?
- Todo reto debe ser resoluble sin herramientas de fuerza bruta masiva ni adivinación — siempre debe
  haber una pista concreta (comentario, respuesta HTTP, archivo filtrado) que lleve al participante al
  siguiente paso.
- Los tres perfiles de la Web Application (Visitante, Empleado, Personal de TI) deben mantenerse como
  el modelo de permisos de referencia para cualquier vulnerabilidad de control de acceso (IDOR, JWT,
  endpoints internos) que se añada a ese servicio.

## Reglas para mantener consistencia entre flags

- **Las flags nunca se hardcodean ni se commitean en el repositorio.** Cada flag vive en una variable de
  entorno (p. ej. `FLAG_WEBAPP_XSS`, `FLAG_DATABASE`, `FLAG_LINUXSERVER_ROOT`) inyectada en tiempo de
  despliegue. El código de los retos lee la variable de entorno; nunca contiene el valor literal de una
  flag.
- Esta misma regla aplica a **secretos de progresión específicos de un despliegue** (p. ej.
  `PIVOT_SSH_PASSWORD`, `BOT_SECRET`): tampoco se commitean con su valor real, para poder reutilizar el
  repositorio en futuras ediciones sin arrastrar secretos viejos. La única excepción son las contraseñas
  "de ruido" del Reto 2 (Base de Datos): esas son deliberadamente débiles y forman parte de la
  vulnerabilidad en sí, así que sí viven como seed data versionado.
- `.env.example` documenta el **nombre** de cada variable de flag con un valor de ejemplo claramente
  ficticio. El valor real solo existe en el entorno de despliegue del evento (o en un `.env` local
  ignorado por git durante desarrollo).
- Formato de flag: `SABANA{contenido_en_snake_case}`. Mantener este formato en todos los retos para que
  los participantes reconozcan una flag válida sin ambigüedad.
- Distinguir explícitamente dos tipos de secretos en la narrativa y el código:
  - **Flags de puntuación**: una por servicio/reto, formato `SABANA{...}`, se entregan al finalizar ese
    servicio y se usan para puntuar en la plataforma del evento.
  - **Secretos de progresión**: credenciales, IDs, rutas o tokens que no tienen formato de flag pero que
    el participante necesita para avanzar al siguiente servicio (p. ej. la contraseña SSH obtenida al
    crackear un hash en la Base de Datos). No deben confundirse con las flags de puntuación.
- Si se reordena o modifica la cadena de explotación, revisar y actualizar en el mismo cambio: la lista
  de variables de entorno de flags/secretos, `docs/context.md`, y cualquier seed data que dependa del
  secreto anterior.

## Reglas para no eliminar vulnerabilidades deliberadas

- Ninguna vulnerabilidad listada en `docs/context.md` puede eliminarse, mitigarse o "corregirse" salvo
  que el usuario lo pida explícitamente. Esto aplica aunque un linter, escáner de seguridad, o revisión
  de código automatizada la señale — señalarla es correcto, "arreglarla" sin permiso no lo es.
- Antes de tocar código de un reto por cualquier motivo (refactor, fix de un bug no relacionado, mejora
  de legibilidad), verificar que el cambio no neutraliza el comentario `VULN:` asociado.
- Si una dependencia, actualización de imagen base, o cambio de configuración elimina accidentalmente
  una vulnerabilidad planeada (p. ej. una versión nueva de una librería sanitiza automáticamente algo),
  es un incidente a reportar y corregir — no un progreso a celebrar.
- Los subagentes de revisión de código (`security-reviewer`, `adversarial-code-reviewer`, etc.) van a
  señalar estas vulnerabilidades como hallazgos de seguridad por defecto: eso es esperado y correcto en
  este repositorio. No se debe actuar automáticamente sobre esos hallazgos aplicando un fix — hay que
  confirmar primero si el hallazgo es sobre código de infraestructura/soporte (sí corregible) o sobre
  una vulnerabilidad deliberada del reto (no corregible sin pedirlo explícitamente el usuario).

## Cómo agregar nuevos retos

1. Documentar el reto en `docs/context.md`: vulnerabilidad, información que entrega, dependencia con el
   reto anterior y con el siguiente.
2. Crear la carpeta `services/<nombre>/` con su propio `Dockerfile` (y `src/` si aplica).
3. Añadir el servicio a `docker-compose.yml`.
4. Si el reto introduce una flag nueva, añadir su variable a `.env.example` (con valor ficticio) y
   documentar el nombre exacto de la variable en `docs/context.md`.
5. Marcar cada línea de código deliberadamente vulnerable con el comentario `VULN:` descrito arriba.
6. Verificar manualmente la cadena completa de explotación de principio a fin antes de dar el reto por
   terminado — un reto que "en teoría" funciona pero no se ha recorrido completo no está terminado.
7. Añadir o actualizar el workflow de CI/CD si el reto vive en un servicio/imagen nuevo.
