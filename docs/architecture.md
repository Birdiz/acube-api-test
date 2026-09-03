# Architecture

## The problem

A customer sends a file and wants it back in another format. The conversion
takes more than two minutes. The file's contents and size are unknown up front.

Everything below follows from that.

## Why `202 Accepted`

A conversion cannot be the response to the request that asks for it: proxies and
browsers cut idle connections well before two minutes, a client that times out
has no id to retry *that* job with, and a web worker blocked on a conversion is
a worker not serving traffic.

So the request and the work are separated:

```
POST /api/files                        201  -> file id
POST /api/files/{id}/conversions       202  -> conversion id + status URL
GET  /api/conversions/{id}             200  -> status
GET  /api/conversions/{id}/result      200  -> the converted file
```

`202` is the exact semantic: the request was valid, it has been recorded, and
processing has not completed. The body carries the conversion id and
`"status": "pending"`; `Location` carries the address to poll.

Uploading is its own step so the bytes are validated while the customer is still
on the connection, and so one upload can be converted more than once.

### What 202 obliges us to do

Each of these is pinned by a test:

- **Everything knowable is decided before the 202.** An unsupported format is a
  `422` on this request, not a job that runs for two minutes and ends `failed`.
- **The `Location` is real immediately.** The record is written in the same
  transaction that accepts the request; the job is queued after it.
- **The status resource is honest while pending** — `no-store`, and the same
  shape in every state: `id`, `status`, `format`, `file_id`, `created_at`,
  `completed_at` and `error` on every response, carrying `null` rather than
  going missing. A caller learns from the values, not from which keys turned up.

## Status codes

The interesting ones are where the obvious answer is wrong.

| Situation | Code | Why not the obvious alternative |
| --- | --- | --- |
| Conversion accepted | `202` | Not `201` — a record exists, but the thing asked for does not yet. |
| Result requested too early | `409` | **Not `404`.** The conversion exists; we handed the caller its id. `404` invites restarting the flow. `409` says the resource is real but its state does not permit this, and waiting fixes it — so the body carries `conversion_status` and `status_url`. |
| Result of a `failed` conversion | `409` | The same conflict, and deliberately not a second code: the resource is real and its state forbids the request. `conversion_status` is what discriminates, so a client keeps one branch, and the `detail` says waiting will not help rather than inviting a poll that can never succeed. |
| Unknown conversion id | `404` | Nothing was ever handed out under it. Unlike the above, no state will make it work. |
| Unsupported couple | `422` | Returned from the `POST`, not discovered later. Syntactically fine, semantically impossible. Body lists `supported_formats`. |
| Unknown `fileId` | `404` | It is in the path, so it identifies the resource being acted on. Checked before the body. |
| Unsupported file type | `415` | From magic bytes — a `.csv` name on a PDF is still a PDF. |
| File too large | `413` | Limit (`FILE_MAX_SIZE_BYTES`) stated in the error, and inclusive. |
| Empty file, no `file` part, no `format` | `422` | Well-formed request, unusable content. |
| Malformed JSON | `400` | Unparseable, so there is nothing to validate. |

### Where these live

`SourceFormat` and `TargetFormat` (`src/Conversion/`) are the single spelling of
what the API accepts and produces. `TargetFormat::fromRequest()` normalises what
a caller sent and `SourceFormat::fromMimeType()` resolves what they actually
uploaded; both throw rather than returning null, because "unsupported" is
information the caller needs, not an absent value.

Those throws are `ConversionProblem` subclasses, and each one carries everything
its error response needs — status, type, title, and any extra field that makes
it actionable, such as the `supported_formats` on a rejected format. Rendering
is therefore one place, and the code that detects a problem is the code that
decides what the caller is told. A failure that is *not* a `ConversionProblem`
is ours, and is the only thing allowed to become a 5xx.

All errors come back as `application/problem+json` — a JSON body carrying a
`type`, `title`, `status` and `detail` — and **nothing a caller can send may
produce a 5xx**: a malformed, oversized or hostile request is always a 4xx that
explains itself. `ApiAssert::noServerError()` runs on every response as it
arrives, so a crash fails the test where it happened rather than being masked
by whatever assertion would have failed next.

A 5xx is reserved for faults that are genuinely ours (`UPLOAD_ERR_CANT_WRITE`, a
full disk) — cases where the caller changing their request would not help.

## Validating the file

Both checks happen at upload, before anything is queued.

**Type** comes from `finfo` magic bytes; extensions and the client's
`Content-Type` are claims, not facts. XLSX and ODS are both ZIP containers, so
"it unzips" proves nothing — they are told apart by their internal layout, and a
bare ZIP is refused.

**Size** is bounded by the application rather than assumed, since it is unknown
up front, and enforced before the file is stored.

### The size limit is pinned to PHP's

`FILE_MAX_SIZE_BYTES` is **2 MiB**, and that number is not chosen on its own
merits — it mirrors PHP's `upload_max_filesize`, which is the real ceiling:

| Layer | Limit | On breach |
| --- | --- | --- |
| `post_max_size` | 8 MiB | Whole body discarded; `$_POST` and `$_FILES` arrive empty |
| `upload_max_filesize` | 2 MiB | File arrives with `UPLOAD_ERR_INI_SIZE` and no usable contents |
| `FILE_MAX_SIZE_BYTES` | 2 MiB | Our own `413`, naming the limit |

An application limit *above* PHP's would be fiction: the upload dies a layer
below, and the caller gets something other than the documented `413`. So the app
limit tracks the smallest ceiling above it, and the error codes PHP sets on the
way are read before the contents are:

- `UPLOAD_ERR_INI_SIZE` → `413`. The temporary file is empty or partial, so the
  error code has to be checked *before* the contents are read; reading first is
  how this turns into a 500.
- `UPLOAD_ERR_PARTIAL` → `422`. The connection dropped mid-upload.

**Past `post_max_size` the request is not handled specially.** PHP discards the
whole body, so `$_FILES` is empty and the API answers `422` "no file was sent".
Reading `Content-Length` and calling it `413` instead is a better answer, and an
earlier version did exactly that — but it costs a parser for PHP's ini shorthand
and a branch that no functional test can honestly reach, to improve the wording
of a case a caller hits by sending a body twice the size of the stated limit.
That is a poor trade in a codebase meant to be read, so the guess is gone. The
first row of the table above is the layer we do not answer for.

Two caveats worth knowing. These PHP values are **compiled-in defaults**, not
pinned in `docker/php/php.ini`, so a base-image change could move them silently;
pinning them next to the app limit would remove that risk. And the functional
tests construct `UploadedFile` objects directly, which never invokes PHP's real
upload machinery — the tests above reproduce the *error codes* PHP would set,
but only an HTTP request against a running container exercises the limits
themselves.

Raising the limit therefore means raising `upload_max_filesize` and
`post_max_size` first, then `FILE_MAX_SIZE_BYTES`.

## Execution

Jobs go to the `conversions` Messenger transport, consumed by a worker
(`php bin/console messenger:consume conversions`) that runs
`RunConversionHandler` → `ConversionRunner`. The transport is `doctrine://`
so enqueueing and recording the conversion commit together: no `pending`
conversion without a job, no job for a rolled-back conversion.

A job moves `pending → processing → done`. A redelivery of one already `done`
or `failed` is acknowledged and dropped rather than redone: the result has been
handed out, and its bytes must not change under a caller who fetched them.
That is also why `processing` is written down — it is what tells a redelivery
of a half-run job apart from a first delivery. `redeliver_timeout` bounds how
long a worker killed mid-job holds the message.

Failures retry three times before landing on `failed`, which is written once,
by a listener on `WorkerMessageFailedEvent` when the retries are spent — not on
each attempt, so a caller polling through the backoff is never told a job is
over that is about to succeed. The stored message is a sentence meant for the
caller; the throwable itself goes to the log and the `failed` transport.

The conversion itself is a stub — the exercise is the lifecycle, not the
contents. The worker never opens the source file: it writes what the job knows
about itself, encoded in the format that was asked for, so a JSON conversion is
valid JSON and an XML one is well-formed XML. The bytes land at
`var/results/{conversionId}`, derived from the id for the same reason an
upload's path is — one rule for locating bytes, not two. The result is read
whole rather than streamed (it is bounded by the upload limit it came from) and
its media type is fixed by the conversion rather than negotiated: the format was
chosen when the job was requested. `no-store` is on the status alone; a finished
result never changes.

### In tests

There is no worker inside a functional test, so the transport is `in-memory://`
under `when@test` and drained by `ConversionQueue::drain()`. That makes
"not ready" and "ready" ordered states rather than a race against a sleep:

```php
$this->api->getConversionResult($id);  // 409, still pending
$this->queue->drain();                 // the worker gets to it
$this->api->getConversionResult($id);  // 200, the file
```

Making that work takes one piece of wiring. `Kernel::handle()` arms a reset of
every `kernel.reset` service for the next boot, and a test client boots once per
request — so the in-memory transport would be emptied between the request that
queues a job and the drain that runs it, and a queued job would never survive
the poll that is supposed to observe it pending. `Kernel::build()` untags it,
under `test` only; every other environment uses `doctrine://`, which is not in
memory to be reset.

There is no worker either, so `WorkerMessageFailedEvent` never fires here:
`failed` is the one state the functional suite cannot reach, and it is pinned by
a unit test on the listener instead.

Everything else is black-box HTTP: routes, status codes and payloads, never the
classes behind them. The harness splits three ways — `ApiClient` makes the
requests and knows the routes, `ApiAssert` holds the rules that hold across
endpoints, and `ConversionQueue` stands in for the worker — so `ApiTestCase`
only composes them.

## Trade-offs taken

- **Polling, not callbacks.** Works for every client with no setup; webhooks can
  layer onto the same resource later.
- **SQLite + Doctrine transport.** Zero infrastructure, transactional enqueueing
  for free. First thing to replace under real concurrency — SQLite serialises
  writers.
- **A hand-rolled state machine, not Symfony Workflow.** `pending → processing
  → done | failed` is a backed enum and three `mark*` methods on the entity,
  and its only guard is the `match` in `ConversionRunner` that drops a
  redelivery of finished work. Workflow fits the shape exactly: it would declare
  the transitions in one place, guard them there rather than in the runner, and
  give transition events for free. It was still refused — a bundle, a YAML
  state-machine definition and a layer of indirection to follow, for four states
  and one guard, is more to review than it saves. That trade flips as soon as
  the states or their guards multiply.
- **No retention policy.** Uploads and results are kept forever, in
  `var/uploads/` and `var/results/`; a real deployment needs a TTL.
- **No authentication.** Ids are opaque rather than sequential, but that is
  obscurity, not authorisation.
