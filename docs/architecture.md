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
| File too large | `413` | Limit (`FILE_MAX_SIZE_BYTES`) stated in the error, and inclusive. Also covers a body PHP discarded — see below. |
| Empty file, no `file` part, no `format` | `422` | Well-formed request, unusable content. |
| Malformed JSON | `400` | Unparseable, so there is nothing to validate. |

### Where these live

`SourceFormat` and `TargetFormat` (`src/Conversion/`) are the single spelling of
what the API accepts and produces. `TargetFormat::fromRequest()` normalises what
a caller sent and `SourceFormat::fromMimeType()` resolves what they actually
uploaded; both throw rather than returning null, because "unsupported" is
information the caller needs, not an absent value.

Those throws are `ConversionProblem` subclasses, and each one carries everything
its problem document needs — status, type, title, and any extension that makes
it actionable, such as the `supported_formats` on a rejected format. Rendering
is therefore one place, and the code that detects a problem is the code that
decides what the caller is told. A failure that is *not* a `ConversionProblem`
is ours, and is the only thing allowed to become a 5xx.

All errors are RFC 9457 problem documents, and **nothing a caller can send may
produce a 5xx**: a malformed, oversized or hostile request is always a 4xx that
explains itself. `ApiAssert::noServerError()` runs on every response as it arrives, so a
crash fails the test where it happened rather than being masked by whatever
assertion would have failed next.

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
limit tracks the smallest ceiling above it, and both of the lower layers are
handled rather than assumed unreachable:

- `UPLOAD_ERR_INI_SIZE` → `413`. The temporary file is empty or partial, so the
  error code has to be checked *before* the contents are read; reading first is
  how this turns into a 500.
- `UPLOAD_ERR_PARTIAL` → `422`. The connection dropped mid-upload.
- Body discarded past `post_max_size` → `413`, not `422`. `$_FILES` is empty so
  the naive reading is "no file was sent", but `Content-Length` says otherwise,
  and `413` is the answer that tells the caller something useful instead of
  sending them to look for a bug in their multipart encoding.

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
(`php bin/console messenger:consume conversions`). The transport is `doctrine://`
so enqueueing and recording the conversion commit together: no `pending`
conversion without a job, no job for a rolled-back conversion. Failures retry
three times before landing on `failed`.

The conversion itself is a stub — the exercise is the lifecycle, not the
contents.

### In tests

There is no worker inside a functional test, so the transport is `in-memory://`
under `when@test` and drained by `ConversionQueue::drain()`. That makes
"not ready" and "ready" ordered states rather than a race against a sleep:

```php
$this->api->getConversionResult($id);  // 409, still pending
$this->queue->drain();                 // the worker gets to it
$this->api->getConversionResult($id);  // 200, the file
```

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
- **No retention policy.** Uploads and results are kept forever; a real
  deployment needs a TTL.
- **No authentication.** Ids are opaque rather than sequential, but that is
  obscurity, not authorisation.
