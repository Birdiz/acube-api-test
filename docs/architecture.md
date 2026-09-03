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
- **The status resource is honest while pending** — `no-store`, same shape in
  every state.

## Status codes

The interesting ones are where the obvious answer is wrong.

| Situation | Code | Why not the obvious alternative |
| --- | --- | --- |
| Conversion accepted | `202` | Not `201` — a record exists, but the thing asked for does not yet. |
| Result requested too early | `409` | **Not `404`.** The conversion exists; we handed the caller its id. `404` invites restarting the flow. `409` says the resource is real but its state does not permit this, and waiting fixes it — so the body carries `conversion_status` and `status_url`. |
| Unsupported couple | `422` | Returned from the `POST`, not discovered later. Syntactically fine, semantically impossible. Body lists `supported_formats`. |
| Unknown `fileId` | `404` | It is in the path, so it identifies the resource being acted on. Checked before the body. |
| Unsupported file type | `415` | From magic bytes — a `.csv` name on a PDF is still a PDF. |
| File too large | `413` | Limit (`FILE_MAX_SIZE_BYTES`, 20 MiB) stated in the error, and inclusive. |
| Empty file, no `file` part, no `format` | `422` | Well-formed request, unusable content. |
| Malformed JSON | `400` | Unparseable, so there is nothing to validate. |

All errors are RFC 9457 problem documents. A bare status code is not an explicit
error.

## Validating the file

Both checks happen at upload, before anything is queued.

**Type** comes from `finfo` magic bytes; extensions and the client's
`Content-Type` are claims, not facts. XLSX and ODS are both ZIP containers, so
"it unzips" proves nothing — they are told apart by their internal layout, and a
bare ZIP is refused.

**Size** is bounded by the application rather than assumed, since it is unknown
up front, and enforced before the file is stored.

## Execution

Jobs go to the `conversions` Messenger transport, consumed by a worker
(`php bin/console messenger:consume conversions`). The transport is `doctrine://`
so enqueueing and recording the conversion commit together: no `pending`
conversion without a job, no job for a rolled-back conversion. Failures retry
three times before landing on `failed`.

The conversion itself is a stub — the exercise is the lifecycle, not the
contents.

### In tests

There is no worker inside a functional test, so the transport is `in-memory://`
under `when@test` and drained by `ApiTestCase::runConversionWorker()`. That makes
"not ready" and "ready" ordered states rather than a race against a sleep:

```php
$this->getConversionResult($id);   // 409, still pending
$this->runConversionWorker();      // the worker gets to it
$this->getConversionResult($id);   // 200, the file
```

Everything else is black-box HTTP: routes, status codes and payloads, never the
classes behind them.

## Trade-offs taken

- **Polling, not callbacks.** Works for every client with no setup; webhooks can
  layer onto the same resource later.
- **SQLite + Doctrine transport.** Zero infrastructure, transactional enqueueing
  for free. First thing to replace under real concurrency — SQLite serialises
  writers.
- **No retention policy.** Uploads and results are kept forever; a real
  deployment needs a TTL.
- **No authentication.** Ids are opaque rather than sequential, but that is
  obscurity, not authorisation.
