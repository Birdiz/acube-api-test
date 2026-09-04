# Architecture

## Why `202 Accepted`

A conversion cannot be the response to the request that asks for it: proxies cut
idle connections well before two minutes, a client that times out has no id to
retry *that* job with, and a web worker blocked on a conversion is a worker not
serving traffic. So the request and the work are separated:

```
POST /api/files                        201  -> file id
GET  /api/files/{id}                   200  -> what we made of the upload
POST /api/files/{id}/conversions       202  -> conversion id + status URL
GET  /api/conversions/{id}             200  -> status
GET  /api/conversions/{id}/result      200  -> the converted file
```

`202` is the exact semantic: valid, recorded, not finished. `Location` carries
the address to poll. Uploading is its own step so the bytes are validated while
the customer is still on the connection, and so one upload can be converted more
than once.

Three obligations follow:

- **Everything knowable is decided before the `202`.** An unsupported format is a
  `422` on this request, not a job that runs two minutes and ends `failed`.
- **The `Location` is real immediately.** The record is written in the same
  transaction that accepts the request; the job is queued after it. "Real"
  means answerable, not merely spellable: every address handed out has an
  operation behind it, and the suite follows each one rather than matching the
  string — API Platform will happily generate an IRI for a resource with no
  `Get`, and route it to `not_exposed`.
- **The status resource is honest while pending** — `no-store`, and the same keys
  in every state (`id`, `status`, `format`, `file_id`, `created_at`,
  `completed_at`, `error`), carrying `null` rather than going missing. A caller
  learns from the values, not from which keys turned up.

## Status codes

The interesting ones are where the obvious answer is wrong.

| Situation | Code | Why not the obvious alternative |
| --- | --- | --- |
| Conversion accepted | `202` | Not `201` — a record exists, the thing asked for does not yet. |
| Result requested too early | `409` | **Not `404`.** The conversion exists; we handed out its id, and `404` invites restarting the flow. The body carries `conversion_status` and `status_url`. |
| Result of a `failed` conversion | `409` | The same conflict, deliberately not a second code — `conversion_status` discriminates, so a client keeps one branch. The `detail` says waiting will not help. |
| Unknown conversion id | `404` | Nothing was ever handed out under it, and no state will make it work. |
| Unsupported couple | `422` | Answered by the `POST`, not discovered later. Body lists `supported_formats`. |
| Unknown `fileId` | `404` | It is in the path, so it names the resource being acted on. Checked before the body. |
| Unsupported file type | `415` | From magic bytes — a `.csv` name on a PDF is still a PDF. |
| File too large | `413` | Limit (`FILE_MAX_SIZE_BYTES`) stated in the error, and inclusive. |
| Empty file, no `file` part, no `format` | `422` | Well-formed request, unusable content. |
| Malformed JSON | `400` | Unparseable, so there is nothing to validate. |

`SourceFormat` and `TargetFormat` (`src/Conversion/`) are the single spelling of
what the API accepts and produces; both throw rather than return null, because
"unsupported" is information the caller needs, not an absent value. Those throws
are `ConversionProblem`s, each built by the named constructor for its case and
carrying everything its response needs — so rendering happens in one place, and
the code that detects a problem is the code that decides what the caller is told.

Everything comes back as `application/problem+json`, and **nothing a caller can
send may produce a 5xx**: a malformed, oversized or hostile request is always a
4xx that explains itself. A failure that is *not* a `ConversionProblem` is ours,
and `ApiAssert::noServerError()` runs on every response so a crash fails the test
where it happened rather than being masked by the next assertion.

## Validating the file

Both checks happen at upload, before anything is queued.

**Type** comes from `finfo` magic bytes; extensions and `Content-Type` are
claims, not facts. XLSX and ODS are both ZIP containers, so "it unzips" proves
nothing — they are told apart by their internal layout, and a bare ZIP is
refused.

**Size** is bounded by the application, and the number is not chosen on its own
merits — it mirrors PHP's, which is the real ceiling:

| Layer | Limit | On breach |
| --- | --- | --- |
| `post_max_size` | 8 MiB | Whole body discarded; `$_FILES` arrives empty |
| `upload_max_filesize` | 2 MiB | File arrives with `UPLOAD_ERR_INI_SIZE`, no usable contents |
| `FILE_MAX_SIZE_BYTES` | 2 MiB | Our own `413`, naming the limit |

An application limit *above* PHP's would be fiction: the upload dies a layer
below and the caller gets something other than the documented `413`. So PHP's
error codes are read before the contents are — `UPLOAD_ERR_INI_SIZE` → `413`
(the temporary file is empty, and reading it first is how this becomes a 500),
`UPLOAD_ERR_PARTIAL` → `422`. Past `post_max_size` there is nothing left to
answer for: PHP discards the body, so `$_FILES` is empty and the API says `422`
"no file was sent". Raising the limit means raising `upload_max_filesize` and
`post_max_size` first — they are compiled-in defaults, not pinned in
`docker/php/php.ini`.

## Execution

Jobs go to the `conversions` Messenger transport, consumed by a worker
(`php bin/console messenger:consume conversions`) running `RunConversionHandler`
→ `ConversionRunner`. The transport is `doctrine://` so enqueueing and
recording commit together: no `pending` conversion without a job, no job
for a rolled-back conversion.

A job moves `pending → processing → done | failed`. A redelivery of finished work
is acknowledged and dropped — the result has been handed out, and its bytes must
not change under a caller who fetched them. That is why `processing` is written
down: it is what tells a half-run job apart from a first delivery.

A job that cannot write its result records `failed` and rethrows, so the caller
polling the status gets an ending and the worker still logs what broke.
`max_retries` is `0` for that reason: recording a failure while another attempt
could still turn it around would tell a caller a job is over that is about to
succeed. The stored message names the class that broke and no paths.

The conversion itself is a stub — the exercise is the lifecycle, not the
contents. The worker never opens the source file; it writes what the job knows
about itself in the format that was asked for, so a JSON conversion is valid JSON
and an XML one well-formed XML. The bytes land at `var/results/{conversionId}`,
derived from the id for the same reason an upload's path is. `no-store` is on the
status alone; a finished result never changes.

### In tests

There is no worker inside a functional test, so the transport is `in-memory://`
under `when@test` and drained explicitly. That makes "not ready" and "ready"
ordered states rather than a race against a sleep:

```php
$this->api->getConversionResult($id);  // 409, still pending
$this->queue->drain();                 // the worker gets to it
$this->api->getConversionResult($id);  // 200, the file
```

One piece of wiring makes it work: a test client boots once per request and
`Kernel::handle()` arms a reset of every `kernel.reset` service, which would empty
the transport between the request that queues a job and the drain that runs it —
so `Kernel::build()` untags it, under `test` only.

`failed` is the one state the functional suite cannot reach, since a stub has
nothing to trip over; a unit test pointing the result store at an unwritable path
pins it instead. Everything else is black-box HTTP — routes, codes and payloads,
never the classes behind them.

## Trade-offs taken

- **Polling, not callbacks.** Works for every client with no setup; webhooks can
  layer onto the same resource later.
- **SQLite + Doctrine transport.** Zero infrastructure, transactional enqueueing
  for free. First thing to replace under real concurrency — SQLite serialises
  writers.
- **No worker service in Compose.** `docker compose up` starts the API and
  nothing that drains the queue, so out of the box a conversion stays `pending`
  until someone runs `messenger:consume` by hand. Deliberate for a reviewer's
  checkout — the worker is the thing under discussion, and one that restarts
  silently in the background is one you cannot watch fail — but it is the wrong
  default anywhere else, and it puts a README step between a reader and the
  feature the whole design exists for. A second service running
  `messenger:consume conversions --time-limit=3600` with `restart: unless-stopped`
  is the deployment shape; production also wants a supervisor and more than one
  of them, which is the point at which SQLite has to go too.
- **A hand-rolled state machine, not Symfony Workflow.** Four states, three
  `mark*` methods and one guard. Workflow fits the shape exactly and would declare
  the transitions in one place, but a bundle and a YAML definition for that is
  more to review than it saves. It flips as soon as the guards multiply.
- **Redelivery recovers a half-run job; it does not lock it.** `processing`
  re-enters `convert()`, so a killed worker's job is picked up rather than
  stranded — with no retries and no reaper, there is no other way back. But
  `redeliver_timeout` measures time, not liveness, and the row is read with a
  plain `find()`: two workers on one conversion would race on the status, where a
  `done` can be reopened or a `failed` overwritten. Milliseconds of stub work and
  a single worker keep that window theoretical; real work needs a lease read under
  a write lock.
- **The generated schema describes the resource, not the response.** Every
  operation declares an `input:` DTO, so what a caller sends is documented
  exactly; none declares an `output:`, so what a caller gets falls back to the
  entity — which exposes no property the serializer can see, leaving
  `Conversion.jsonld` and `File.jsonld` as empty objects. The `202` is published
  as `application/ld+json` and returns `{"id", "status"}` as `application/json`.
  The fix is `output:` DTOs, not groups alone: the controllers run under
  `serialize: false`, so nothing reaches the serializer. `OpenApiDocumentationTest`
  pins the paths and parameters, not the bodies.
- **No retention policy.** Uploads and results are kept forever, in `var/uploads/`
  and `var/results/`; a real deployment needs a TTL.
- **No authentication.** Ids are opaque rather than sequential, but that is
  obscurity, not authorisation.
