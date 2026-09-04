# acube-api-test

A Symfony 8.1 / API Platform 4.3 API running on FrankenPHP, orchestrated with
Docker Compose and driven through a small Makefile.

It converts uploaded files (CSV, JSON, XLSX, ODS) into JSON or XML. The
conversion takes longer than a request can wait, so it runs as a background job:
`POST` returns `202 Accepted` with a URL to poll.

See [docs/architecture.md](docs/architecture.md) for why the API is shaped that
way — the `202` decision, the status codes it forces (notably `409` rather than
`404` for a result that is not ready yet), and how the file type and size are
validated.

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

The database is SQLite and lives at `api/var/data_dev.db`. The schema ships as
migrations, and the Messenger queue is one of the tables they create, so apply
them once after `composer install`:

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
| `make analyse` | Run PHPStan at level 10 over `src/` and `tests/` |
| `make refactor` | Show what Rector would change, or apply it with `APPLY=1` |
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
| `APPLY` | empty | Set to `1` to let `make refactor` write its changes |

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

### Running the tests

```bash
make exec CMD="php bin/phpunit"
```

The suite is written against the HTTP contract only — routes, status codes and
payload shapes — so it describes the API without depending on how it is built.
The tests came first: the contract was written before any endpoint existed, and
the implementation was driven until it satisfied it. The suite is green.

| Test class | What it pins down |
| --- | --- |
| `FileUploadTest` | `POST /api/files`: accepted types, type detection from content, the size limit |
| `ConversionRequestTest` | `POST /api/files/{id}/conversions`: the `202`, and rejecting the impossible up front |
| `ConversionStatusTest` | `GET /api/conversions/{id}`: the resource the `202` points at |
| `ConversionResultTest` | `GET /api/conversions/{id}/result`: the file, and `409` when it is not ready |
| `ConversionWorkflowTest` | The four steps end to end, as a client actually walks them |
| `SourceFormatTest`, `TargetFormatTest` | The format vocabulary in `src/Conversion/` |
| `FailedConversionListenerTest` | `failed` — the one state a functional test cannot reach, since it has no worker |

Conversion jobs are queued on the `conversions` Messenger transport. A worker
consumes it — in dev as much as in production. Without one running, a conversion
stays `pending` and its result keeps answering `409`:

```bash
make exec CMD="php bin/console messenger:consume conversions"
```

In tests the transport is in-memory and drained explicitly, which is what makes
"not ready" and "ready" two ordered states instead of a race. See
[docs/architecture.md](docs/architecture.md#in-tests).

### Static analysis

```bash
make analyse
```

PHPStan runs at **level 10**, its maximum, over `src/` and `tests/` with the
Symfony, Doctrine and PHPUnit extensions, and the run is clean. The target warms
the test container first because the Symfony extension reads it to resolve
service ids; three errors in `ConversionQueue` are ignored in
`api/phpstan.dist.neon`, where the reason is written down — the harness fetches
services the test container exposes on purpose and the compiled container says
are private.

Analysing the tests as well as the source is the part that paid: the source held
a dead `catch`, an unused method and an IRI that could be `null`, and the
harness was passing `mixed` into assertions that expect strings.

```bash
make refactor
```

Rector is configured in `api/rector.php` for a codebase with no legacy to
upgrade — dead code, code quality, type declarations and early returns — and
currently proposes nothing. Three rules are skipped, each because it would
rewrite a decision this codebase already made rather than an oversight; the
reasons are in the config next to the skip.

### Resetting the environment

```bash
make purge
```

This removes the container, the Caddy volumes and the locally built image. The
source tree in `api/` is untouched, so a following `make up` gives you a clean
environment.
