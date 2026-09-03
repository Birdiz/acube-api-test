# acube-api-test

A Symfony 8.1 / API Platform 4.3 API running on FrankenPHP, orchestrated with
Docker Compose and driven through a small Makefile.

## Installation

### Prerequisites

Everything the application needs (PHP 8.4, Composer, the `apcu`, `intl`,
`opcache`, `pdo_sqlite` and `zip` extensions) ships inside the Docker image, so
you only need the following on your host:

| Requirement | Version | Notes |
| --- | --- | --- |
| Docker Engine | 20.10+ | Docker Desktop works out of the box |
| Docker Compose | v2 | The `docker compose` plugin, not the legacy `docker-compose` binary |
| GNU Make | 3.81+ | Preinstalled on macOS and most Linux distributions |

Check your setup with:

```bash
docker compose version && make --version
```

### Setup

```bash
git clone <repository-url> acube-api-test
cd acube-api-test
make up
make exec CMD="composer install"
```

`make up` builds the image if needed, starts the container and waits until its
healthcheck passes. `composer install` is a separate step because the
application in `api/` is bind-mounted into the container, and `vendor/` is not
committed.

The API is then available at <http://localhost:8000>, with the API Platform
documentation at <http://localhost:8000/api>.

The database is SQLite and lives at `api/var/data_dev.db`; it is created
automatically on first use. If you add entities and migrations, apply them with:

```bash
make exec CMD="php bin/console doctrine:migrations:migrate --no-interaction"
```

### Using the Makefile

Run `make` (or `make help`) to list the available targets:

| Target | Description |
| --- | --- |
| `make help` | Show the available targets (default target) |
| `make up` | Build if needed, start the container and wait until it is healthy |
| `make exec` | Open a shell in the container, or run `CMD="…"` |
| `make stop` | Stop the container without deleting it |
| `make purge` | Remove the container, its volumes and the image built for it |

Every target accepts these variables as overrides:

| Variable | Default | Purpose |
| --- | --- | --- |
| `HTTP_PORT` | `8000` | Host port the application is published on |
| `PHP_VERSION` | `8.4` | PHP version baked into the image at build time |
| `SERVICE` | `app` | Compose service targeted by `make exec` |
| `CMD` | `sh` | Command run by `make exec` |
| `COMPOSE` | `docker compose` | Compose binary to invoke |

For example:

```bash
make up HTTP_PORT=8080
```

```bash
make exec CMD="php bin/console debug:router"
```

```bash
make exec
```

The last one drops you into a shell inside the container, where `composer` and
`bin/console` are on the `PATH`.

### Resetting the environment

```bash
make purge
```

This removes the container, the Caddy volumes and the locally built image. The
source tree in `api/` is untouched, so a following `make up` gives you a clean
environment.
