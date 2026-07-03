# Sabana Corp Network — CTF Lab

Laboratorio de ciberseguridad (CTF) para la Semana de Ingeniería — Universidad de La Sabana. Una cadena de explotación controlada y documentada que lleva a los participantes desde una aplicación web vulnerable hasta escalamiento de privilegios en Linux.

## Arranque rápido

```bash
# 1. Copiar plantilla de variables
cp .env.example .env

# 2. Completar valores reales (copiar desde notas internas, NUNCA commitear .env)
# Editar .env: flags SABANA{...}, contraseñas, secretos

# 3. Levantar laboratorio local
docker compose up --build
# Webapp: http://localhost:8080
# Base de Datos: publicada en localhost:3306 (el participante se conecta directo tras filtrar las
# credenciales en el Reto 1 — ver docs/context.md)
```

## Estructura del repositorio

```
├── CLAUDE.md               # Reglas permanentes de proceso y convención
├── docs/
│   └── context.md          # Fuente de verdad: narrativa, arquitectura, vulns
├── services/
│   ├── webapp/             # Reto 1: PHP + MariaDB, 6 vulnerabilidades encadenadas
│   ├── database/           # Reto 2: MariaDB con hashes débiles/sin salt
│   └── (linux-server, xss-bot: no comenzados aún)
├── docker-compose.yml      # Orquestación local
├── .env.example            # Plantilla con nombres de variables (valores ficticios)
└── .github/workflows/      # CI/CD: build automático a Docker Hub en push
```

## Estado actual (V1 — handoff a Lufe)

**Completado:**
- Diseño e arquitectura completa (CLAUDE.md, docs/context.md)
- Reto 1 — Web Application (login, SQLi, IDOR, LFI, JWT inseguro, Stored XSS)
- Reto 2 — Base de Datos (hashes MD5 sin salt, escalamiento de credenciales)
- Orquestación Docker Compose
- CI/CD básico (GitHub Actions matrix para webapp + database)

**Pendiente:**
- Reto 3 — Linux Server (escalamiento de privilegios local)
- Contenedor xss-bot (soporte para explotar Stored XSS)
- Diagrama de red (docs/infra.jpg)
- Migración a `macvlan` para networking final

## Documentación

- **`CLAUDE.md`**: Filosofía (la inseguridad es la funcionalidad), convenciones de código (marcas `VULN:`), reglas Docker/CI, reglas de flags
- **`docs/context.md`**: Narrativa de Sabana Corp, cadena de explotación paso a paso, decisiones de diseño registradas

## Notas de seguridad

- `.env` nunca se commitea; solo `.env.example` (con valores ficticios)
- Vulnerabilidades deliberadas están marcadas con `// VULN:` en el código — esto previene que sean "corregidas" accidentalmente
- Las flags (`SABANA{...}`) y secretos reales se inyectan solo en despliegue del evento, no en git

---

Para más detalles, consultar `CLAUDE.md` (reglas del proyecto) y `docs/context.md` (arquitectura y retos). Artifact visual: https://claude.ai/code/artifact/f256371c-9275-48b2-b5d3-9b3dd7b966f8
