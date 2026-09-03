# Architecture

## The problem

A customer sends us a file and wants it back in another format. The conversion
takes more than two minutes. Neither the file's contents nor its size are known
up front.

Everything below follows from that one sentence.

## Why `202 Accepted`

A conversion cannot be the response to the request that asks for it.

The obvious design — `POST /api/conversions` returns the converted file — breaks
at every layer once the work takes minutes:

- **Proxies and browsers give up first.** Caddy, ALBs and browsers all cut idle
  connections well under two minutes. The work would finish; the customer would
  see a gateway timeout and no way to reach the result.
- **A retry re-runs the job.** With no id handed out before the work starts, a
  client that retries after a timeout has no way to say "I meant *that* one".
  Every retry is a fresh two-minute job.
- **Web workers are the wrong place to spend minutes.** A PHP-FPM or FrankenPHP
  worker blocked on a conversion is a worker not serving requests. Concurrency
  becomes bounded by the slowest job rather than by traffic.
- **A crash loses the work silently.** Nothing durable records that the job was
  ever asked for.

So the request that asks for the work and the work itself are separated:

```
POST /api/files                        201  -> file id
POST /api/files/{id}/conversions       202  -> conversion id + status URL
GET  /api/conversions/{id}             200  -> status
GET  /api/conversions/{id}/result      200  -> the converted file
```

`202 Accepted` is exactly the semantic on offer: *the request was valid, it has
been recorded, and processing has not completed.* The response body carries the
conversion id and `"status": "pending"`; the `Location` header carries the
address to poll. The customer gets an answer in milliseconds and a durable
handle to the work.

### What 202 obliges us to do

Returning 202 is a promise, and it costs something. Three consequences are
non-negotiable, and each is pinned by a test:

1. **Everything knowable must be decided before the 202.** If the target format
   is unsupported, that is a `422` on this request — not a job that runs for two
   minutes and ends `failed`. A 202 means "this will be attempted", so anything
   we can already rule out must be ruled out now.
   → `ConversionRequestTest::itDoesNotCreateAConversionForAnUnsupportedCouple`
2. **The `Location` must be real immediately.** A caller that follows it one
   millisecond later must get a conversion, not a 404. The record is written in
   the same transaction that accepts the request; the job is queued after it.
   → `ConversionStatusTest::itDescribesAPendingConversion`
3. **The status resource must be honest while pending.** It is polled, so it is
   `no-store`, and it exposes the same shape for every state.
   → `ConversionStatusTest::itIsNotCacheable`

### Upload as its own step

`POST /api/files` exists separately rather than accepting the file and the
target format in one multipart request. Splitting them means:

- the bytes are validated (size, type) while the customer is still on the
  connection, before anything is queued;
- one upload can be converted several times without re-sending it;
- the conversion request is a small JSON body, so it stays cheap to retry.

### Polling, not callbacks

Polling `GET /api/conversions/{id}` is the baseline because it works for every
client with no setup: no public endpoint, no shared secret, no delivery
retries. A webhook or an SSE stream can be added later as an *optimisation* on
top of the same resource — the state has to be queryable either way.

## Status codes

The interesting ones are where the obvious answer is wrong.

| Situation | Code | Why not the obvious alternative |
| --- | --- | --- |
| Conversion accepted | `202` | Not `201`: a conversion *record* is created, but the thing the customer asked for does not exist yet. Not `200`: nothing has been done. |
| Result requested too early | `409` | **Not `404`.** The conversion exists — we handed the caller its id in a 202. `404` says "no such thing", which invites the client to start the whole flow again. `409 Conflict` says the resource is real but its current state does not permit this, and waiting fixes it. The body carries `conversion_status` and `status_url` so "wait" is actionable. |
| Unsupported couple | `422` | Returned from the `POST`, not discovered later. The request is syntactically fine but semantically impossible, which is precisely `422`. The body lists `supported_formats` so the caller does not have to guess. |
| Unknown `fileId` | `404` | The id is in the path: it identifies the resource being acted on, and it is not there. Checked before the body is validated. |
| Unsupported file type | `415` | Determined from the file's magic bytes, never from its extension or the client-declared `Content-Type`. A `.csv` name on a PDF is still a PDF. |
| File too large | `413` | The limit (`FILE_MAX_SIZE_BYTES`, 20 MiB by default) is stated in the error so the caller knows what to aim for. Inclusive: a file of exactly the limit is accepted. |
| Empty file, missing `file` part, missing `format` | `422` | Well-formed request, unusable content. |
| Malformed JSON body | `400` | The request itself cannot be parsed, so there is nothing to validate. |

All errors are RFC 9457 (`application/problem+json`) documents with `type`,
`title`, `status` and `detail`, plus problem-specific extensions where they make
the error actionable. A bare status code is not an explicit error.

## Validating the file

Two checks, both at upload time, both before anything is queued.

**Type** is resolved from content, using `finfo` magic bytes. Extensions and the
client's `Content-Type` header are ignored — they are claims by the caller, not
facts. This matters more than usual for the supported set: XLSX and ODS are both
ZIP containers, so "it unzips" proves nothing. They are distinguished by their
internal layout (`[Content_Types].xml` for XLSX, a stored `mimetype` entry for
ODS), and a bare ZIP is refused. The fixtures in `api/tests/Api/Fixture/SampleFile.php`
build real containers for this reason: a fixture that cheated here would let a
broken type check pass.

**Size** is bounded by `FILE_MAX_SIZE_BYTES`. Because the size is not known up
front, the limit is enforced by the application rather than assumed — and it is
enforced before the file is stored, not after.

## Execution

Conversion jobs go to a Messenger transport (`conversions`), consumed by a
worker process:

```bash
php bin/console messenger:consume conversions
```

`doctrine://` is the transport in dev and prod: the job table lives in the same
SQLite database as the domain data, so enqueueing and recording the conversion
commit together — a conversion is never `pending` with no job behind it, and a
job never references a conversion that was rolled back. Swapping in Redis or
AMQP is a DSN change once that guarantee is worth trading for throughput.

Failures retry three times with an exponential backoff before landing on the
`failed` transport. The conversion's `status` is the customer-facing record of
this; the transport is the operational one.

The conversion itself is deliberately a stub for this exercise — it sleeps and
writes a plausible document. The point of the exercise is the job's lifecycle,
not its contents.

### In tests

There is no background worker inside a functional test, so the `conversions`
transport is `in-memory://` under `when@test` and drained explicitly by
`ApiTestCase::runConversionWorker()`. That is what makes "not ready" and "ready"
two deterministic, orderable states rather than a race against a sleep:

```php
$conversionId = $this->requestConversion($fileId, 'xml');

$this->getConversionResult($conversionId);   // 409, still pending
$this->runConversionWorker();                // the worker gets to it
$this->getConversionResult($conversionId);   // 200, the file
```

Everything else in the suite is black-box HTTP: the tests know routes, status
codes and payload shapes, never the classes behind them.

## Trade-offs taken

- **Polling over callbacks.** Simplest thing that works for every client;
  webhooks layer on later.
- **SQLite and a Doctrine transport.** Zero infrastructure for a take-home, and
  transactional enqueueing for free. It is also the first thing to replace under
  real concurrency: SQLite serialises writers.
- **Files kept after conversion.** No retention policy is implemented. A real
  deployment needs a TTL on both uploads and results.
- **No authentication.** Every file is readable by anyone holding its id, so ids
  are opaque (UUID/ULID) rather than sequential — but that is obscurity, not
  authorisation.
