# Sabana Corp Network — CTF Lab

Laboratorio de ciberseguridad para la **Semana de Ingeniería — Universidad de La Sabana**. Los participantes atacan una infraestructura corporativa ficticia encadenando vulnerabilidades reales: desde una aplicación web con credenciales expuestas hasta obtener acceso root en un servidor Linux, pasando por una base de datos con contraseñas débiles.

La cadena de explotación simula un compromiso real de infraestructura. Cada reto entrega información necesaria para el siguiente: no hay retos aislados, todo está conectado.

---

## Objetivo Final — Meta-reto

A lo largo del laboratorio, **4 de los retos** contienen un archivo llamado `objetivo_final.txt`. Cada archivo tiene una sola palabra. Al encontrar los cuatro y unirlos en orden de descubrimiento, los participantes forman la frase:

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

## Guía de resolución

Para entender los retos en detalle y ver la cadena de explotación completa, ver **[`docs/goal-path.md`](docs/goal-path.md)**, que contiene:

- **"Los Retos":** descripción detallada de cada vulnerabilidad (1.1–1.6 para Reto 1, 2.1–2.3 para Reto 2, 3.1–3.6 para Reto 3), cómo funcionan y qué entregan.
- **"Soluciones (guía completa para organizadores)":** walthrough paso-a-paso del recorrido completo con comandos exactos, payloads y outputs esperados (Fases 1–9).

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
├── README.md                         # Este archivo (overview del laboratorio)
├── docs/
│   ├── context.md                    # Fuente de verdad: narrativa, arquitectura, decisiones de diseño
│   └── goal-path.md                  # Guía completa de resolución: retos + soluciones detalladas
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
