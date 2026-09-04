# acube-api-test

## Personal thoughts

So I must admit: I went to far with the test! 
But I felt real pleasure doing it.

Second point to admit: I used AI at every step of the task, 
but I did it intentionally, here is how I used it:
1. I took the test and thought of a plan. 
I identified that the test is asking for an async action.
The 2 min are not from anywhere, most of the most we face a timeout at 30s and even the browser apply a 1min timeout.
Finally, not knowing the size and type of file means high validation at the very beginning, early return before executing anything.
I wrote that to my agent, asking to challenge every piece of it. My goal was to identify more edge cases.
I decided to use more precise HTTP codes instead of the basic Symfony Validation, Param Converter and API Platform native error messages:
I wanted to go RESTful, RCF friendly and HTTP CLient friendly.
Both ODS and XLSX are ZIP files: on step further would be to take security to another level and check for malicious code.
2. Scaffolding the project (dockerization) is done by the agent after I listed what I needed: 
a simple PHP server using Franken,
a SQLLite database for the FactStoring pattern
No XDebug (I don't like it I must admit)
No Logger, CI & production stage, as it a simple test
3. I decided the packages and component.
API Platform, Messenger, Twig for the API doc, Code quality tool, PHPUnit even if I prefer Behat. 
And I like to work TDD. When all of this was cleared out with the agent: it did the dirty work.
The robot beats the human on that part, tests writing is often biaised because you think your code is bugless.
The robot has no such feelings.
4. Agent is coding each part of the logic based on the tests.
Then starts a tennis table work: it creates a PR, I review it carefully and send back my comments until I am satisfied.
I think this is one of the best way to work: Human controls what the robots is producing and I am still owning and fully understanding 
what is created. If I don't understand a piece of code, it means that the agent went not in my direction.
5. Global review. Once I am satisfied, I ask the agent for a deep review.
6. Documentation is donc by the agent and reworked by me.
7. I finally conducted manual testing, as a dev + QA would do. That led me to notice 2 errors:
- the location after uploading a file was wrong
- the path param on POST conversions was not correctly configured on the ApiResource attribute

If you check the close PR, you'll see I tried to scope the work to have small/medium sized PR and if you check 
the commits of a PR, you'll see the corrections I asked to the agent.
I hope it gaves a good overview of what can be achieved in ~7h of work (a day).

## Context

A Symfony 8.1 / API Platform 4.3 API running on FrankenPHP, orchestrated with
Docker Compose and driven through a small Makefile.

It converts uploaded files (CSV, JSON, XLSX, ODS) into JSON or XML. The
conversion takes longer than a request can wait, so it runs as a background job.

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
| `FileDetailsTest` | `GET /api/files/{id}`: the address the upload's `Location` names, and what it reports |
| `ConversionRequestTest` | `POST /api/files/{id}/conversions`: the `202`, and rejecting the impossible up front |
| `ConversionStatusTest` | `GET /api/conversions/{id}`: the resource the `202` points at |
| `ConversionResultTest` | `GET /api/conversions/{id}/result`: the file, and `409` when it is not ready |
| `ConversionWorkflowTest` | The four steps end to end, as a client actually walks them |
| `SourceFormatTest`, `TargetFormatTest` | The format vocabulary in `src/Conversion/` |
| `ConversionRunnerTest` | `failed` — the one state a functional test cannot reach, since a stub conversion cannot trip |

Conversion jobs are queued on the `conversions` Messenger transport. A worker
consumes it. Without one running, a conversion
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
Symfony, Doctrine and PHPUnit extensions. The run is clean with **no baseline
and no ignored errors** — a baseline would make the level a claim about the code
that was true once. The target warms the test container first because the
Symfony extension reads it to resolve the service ids the harness fetches.

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
