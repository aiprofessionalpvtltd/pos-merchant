# Files & Media

Product images, customer signatures and the shop logo.

Uploads are decoupled from the record that references them. A product or a
held order is saved with a `file_id`, and the upload can be retried on its
own — which matters when a 2 MB photo is going up a link that drops.

> **Status: implemented.** All three endpoints are live and tested, and every
> response below is captured from the running API. `POST /products`,
> `PATCH /products/{id}`, `PATCH /merchant` and `POST /cart/hold` now read
> their `image_file_id` / `logo_file_id` / `signature_file_id` fields for real.

| Method | Path | Auth |
| --- | --- | --- |
| POST | `/files` | Bearer |
| GET | `/files/{id}` | Bearer |
| DELETE | `/files/{id}` | Bearer |

---

## Complete endpoint list

Full URL = `{BASE_URL}/api/v1` + path.

**Headers**

| Header | Sent on | Value |
| --- | --- | --- |
| `Accept` | Every request | `application/json` |
| `Authorization` | Every request | `Bearer <token>` from [`POST /auth/pin/login`](auth.md#post-authpinlogin) |
| `Content-Type` | `POST /files` | `multipart/form-data` |

| # | Method | Full path | Purpose | Body / query | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | POST | `/api/v1/files` | Upload a file | `purpose`, `file`, `client_uuid` | `201` new / `200` replay | `413 file.too_large`, `415 file.type_unsupported`, `422 file.corrupt`, `422 validation.failed` |
| 2 | GET | `/api/v1/files/{id}` | A fresh one-hour link to the file | `variant` | `200` | `404 file.not_found` |
| 3 | DELETE | `/api/v1/files/{id}` | Delete an unattached file | — | `200` | `404 file.not_found`, `409 file.in_use` |

**Status codes shared by every endpoint**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.token_invalid` | Missing, revoked or expired token: clear the session |
| `422` | `validation.failed` | Per-field messages in `error.details` |
| `429` | `rate_limited` | Slow down; honour `Retry-After` |

**Response envelope.** Every response carries `success`, `message`, `data` (or
`error`) and `meta.request_id` / `meta.server_time`. Branch on `error.code`,
never on `message`. See [errors.md](errors.md).

**Every file belongs to one shop.** Another shop's file, or an unknown id, is
always `404 file.not_found`.

---

## Concepts

### Why this is separate

The legacy app did two different things, both awkward:

- **Product images** were multipart fields on `POST /api/products`, so a
  failed upload lost the whole product record.
- **Signatures** were base64 data URLs inside the JSON body of
  `POST /api/cart/placePendingOrder`, inflating the request by a third and
  losing the order if it failed.

Both were read back from `${baseUrl}/public/<path>` — a public, guessable
path assembled by the client from a base-URL constant, so every asset broke
whenever the host changed, and any merchant's photo could be read by anyone
who guessed the path.

### Purposes

`purpose` decides the size limit, the accepted types and what the server does
to the image.

| Purpose | Max size | Accepted | Server processing | Thumbnail |
| --- | --- | --- | --- | --- |
| `product_image` | 5 MB | `image/jpeg`, `image/png`, `image/webp` | Resized to fit 1200 px, re-encoded to WebP | 200 px, WebP |
| `signature` | 512 KB | `image/png` | Flattened onto a white background (so transparency prints cleanly), kept as PNG | none |
| `merchant_logo` | 2 MB | `image/jpeg`, `image/png` | Resized to fit 512 px, kept in its original format | none |

A file uploaded for one purpose cannot be attached where another purpose is
expected — a logo cannot be used as a product photo (`422 validation.failed`,
`error.field: image_file_id` or `signature_file_id`).

### Attaching a file

A file is only linked to a record when its id is sent to that record's own
endpoint:

| Field | Sent to |
| --- | --- |
| `image_file_id` | [`POST /products`](inventory.md#4-post-apiv1products--add-a-product), [`PATCH /products/{id}`](inventory.md#5-patch-apiv1productsid--edit-a-product) |
| `logo_file_id` | [`PATCH /merchant`](merchant.md#2-patch-apiv1merchant--edit-the-shop-profile) |
| `signature_file_id` | [`POST /cart/hold`](cart.md#8-post-apiv1carthold--hold-the-ticket-as-a-pending-order) |

Once attached, `attached: true` is set on the file and it can no longer be
deleted directly (`409 file.in_use`) — clear it by attaching a different file,
or setting the parent's field to `null` (for the logo).

### Unattached files are swept

An upload with no record referencing it is temporary. `files:prune` (hourly)
deletes any file whose `attached_at` is still empty 24 hours after it was
uploaded. Attach it before then, or it is gone and must be re-uploaded.

### Deduplicated retries

`client_uuid` is a device-generated id sent once per upload attempt. Sending
it again — after a lost response, for example — returns the **existing**
file with `200 Upload complete` instead of creating a second one. Queue
uploads and retry them like any other offline write, attaching the `file_id`
to the product or order once it lands.

### Fetching a file

The client normally just uses the `url` from the upload response or from the
parent record (product, merchant, order). `GET /files/{id}` exists for
re-resolving an id into a fresh link: it returns JSON with a **one-hour signed
URL** to the bytes, not the bytes themselves. Assets are not publicly
enumerable — the old `/public/<path>` scheme let anyone who guessed a path
read another merchant's photos; a signed URL only works for one hour and
cannot be guessed.

---

## 1. POST `/api/v1/files` — Upload a file

**Purpose:** Uploads a file. Deduplicated by `client_uuid` if sent.

**Request** — `multipart/form-data`

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `file` | binary | yes | The bytes |
| `purpose` | enum | yes | `product_image` \| `signature` \| `merchant_logo` |
| `client_uuid` | string | no | Up to 64 characters. Sending the same value again returns the existing file instead of creating a new one. |

```
POST /api/v1/files
Content-Type: multipart/form-data; boundary=----X

------X
Content-Disposition: form-data; name="purpose"

product_image
------X
Content-Disposition: form-data; name="file"; filename="rice.jpg"
Content-Type: image/jpeg

<binary>
------X--
```

**Response `201`**

```json
{
  "success": true,
  "message": "Upload complete",
  "data": {
    "file_id": "file_01M36RSVGQ193RTA7NFMYKJFYD",
    "purpose": "product_image",
    "url": "https://your-host/storage/files/3734/3dac502c-c3ac-4318-b5e4-3f0a0241f406.webp",
    "thumb_url": "https://your-host/storage/files/3734/246a8879-8089-4633-b2ba-c7225156a356_thumb.webp",
    "bytes": 2006,
    "width": 1200,
    "height": 900,
    "content_type": "image/webp",
    "attached": false
  }
}
```

A 1600×1200 photo came back resized to 1200×900 (the long edge capped at
1200 px) and re-encoded to WebP, with a 200 px `thumb_url` alongside it.
`attached` is `false` until a product, the merchant or an order references
this `file_id`.

**Response `200` — replay of an existing `client_uuid`**

Same shape as above, with `"message": "Upload complete"` and the **original**
file's `file_id`, `url` and dimensions — the newly posted bytes are discarded.

**Response `201` — signature**

```json
{
  "success": true,
  "message": "Upload complete",
  "data": {
    "file_id": "file_01M36RSVM5NWY44FZ3KWRS9WN9",
    "purpose": "signature",
    "url": "https://your-host/storage/files/3734/cb6cd314-26b5-488c-8343-3f4969d6ea15.png",
    "thumb_url": null,
    "bytes": 188,
    "width": 300,
    "height": 100,
    "content_type": "image/png",
    "attached": false
  }
}
```

**Response `201` — merchant logo**

```json
{
  "success": true,
  "message": "Upload complete",
  "data": {
    "file_id": "file_01M36RSVQKK2WE8DPAFAR9SEQ9",
    "purpose": "merchant_logo",
    "url": "https://your-host/storage/files/3734/adf832be-b345-4100-902c-3c23101b55d8.jpg",
    "thumb_url": null,
    "bytes": 4784,
    "width": 512,
    "height": 512,
    "content_type": "image/jpeg",
    "attached": false
  }
}
```

A 1000×1000 JPEG logo came back resized to 512×512 and kept as JPEG — only
`product_image` is forced to WebP; a logo keeps whatever format it was sent
in.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `413` | `file.too_large` | Over the limit for that purpose (`error.details.max_bytes`) |
| `415` | `file.type_unsupported` | Not one of the accepted types for that purpose (`error.details.accepted`) |
| `422` | `file.corrupt` | Passed the size and type checks but is not a decodable image |
| `422` | `validation.failed` | `file` or `purpose` missing, or `purpose` not one of the three |

```json
{
  "success": false,
  "message": "That file is too large",
  "error": { "code": "file.too_large", "details": { "max_bytes": 5242880 } }
}
```

```json
{
  "success": false,
  "message": "That file type is not accepted",
  "error": { "code": "file.type_unsupported", "details": { "accepted": ["image/png"] } }
}
```

A JPEG sent with `purpose: signature` is rejected this way — only PNG is
accepted, so the flatten-and-print step is not started on the wrong format.

### Client guidance

Compress before uploading. A phone camera photo is several megabytes; the
catalogue thumbnail is 200 px. Resizing to roughly 1200 px on the long edge
and encoding at quality 80 before the request is the difference between an
upload that completes on a weak link and one that does not — the server
resizes down but never up, so nothing is gained by sending more than the
purpose's limit.

## 2. GET `/api/v1/files/{id}` — Get a fresh link to a file

**Purpose:** Re-resolves a `file_id` into a link, for when the original `url`
was not kept.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `variant` | enum | `original` | `original` \| `thumb`. `thumb` falls back to the original if the file has none. |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "file_id": "file_01M36RSVGQ193RTA7NFMYKJFYD",
    "url": "https://your-host/api/v1/files/file_01M36RSVGQ193RTA7NFMYKJFYD/raw?expires=1790158560&path=files%2F3734%2F3dac502c-c3ac-4318-b5e4-3f0a0241f406.webp&signature=6d95c0fa3856255b9175229c3ea5d5a5c8ef61fb72ed5f1e670e84420b864f01",
    "expires_in": 3600
  }
}
```

`url` is valid for `expires_in` seconds (one hour) from this call; request a
new one once it expires. It is not the same `url` returned by the upload —
that one is served straight from storage, while this one is a signed
redirect, which is what makes it safe to hand to a client that should not be
able to guess other merchants' paths.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `file.not_found` | No such file for this shop |

```json
{ "success": false, "message": "We could not find that file", "error": { "code": "file.not_found" } }
```

## 3. DELETE `/api/v1/files/{id}` — Delete a file

**Purpose:** Deletes a file that is not attached to anything. An attached
file is removed by clearing the reference on its parent record instead
(`logo_file_id: null` on the merchant, a new `image_file_id` on the product).

**Response `200`**

```json
{ "success": true, "message": "File deleted", "data": { "file_id": "file_01M36RSVM5NWY44FZ3KWRS9WN9", "deleted": true } }
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `file.not_found` | No such file for this shop |
| `409` | `file.in_use` | Attached to a record; `error.details.attached_to` names it |

```json
{
  "success": false,
  "message": "This file is attached to a record and cannot be deleted directly",
  "error": {
    "code": "file.in_use",
    "details": { "attached_to": { "type": "product", "id": 1591 } }
  }
}
```

`attached_to.type` is `product`, `order` or `merchant`.

---

## Step by step: adding a product photo

```
POST /files     { purpose: "product_image", file, client_uuid }  → file_id
POST /products  { ..., image_file_id: file_id }                   → product with image.url / image.thumb_url
```

If the upload succeeds but the product create is lost (offline, crash), the
device retries `POST /products` with the same `file_id` it already has —
nothing is re-uploaded.

## Step by step: signing a held order

```
POST /files     { purpose: "signature", file }                    → file_id
POST /cart/hold { customer, signature_file_id: file_id, ... }     → order with signature.url
```

| State | Client does |
| --- | --- |
| `413 file.too_large` | Resize/compress on the device and retry |
| `415 file.type_unsupported` | Show the accepted types for that purpose |
| `422 file.corrupt` | Ask the user to retake the photo |
| `409 file.in_use` | Detach it from `error.details.attached_to` first, or just upload a new one |
| No network | Queue the upload with its `client_uuid`; the parent record is saved once it lands |

---

## Postman / curl quick start

```bash
BASE=https://your-host/api/v1

curl -X POST $BASE/files -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN" \
  -F 'purpose=product_image' -F 'file=@rice.jpg'

curl $BASE/files/file_01M36RSVGQ193RTA7NFMYKJFYD -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X DELETE $BASE/files/file_01M36RSVGQ193RTA7NFMYKJFYD -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

---

## Implementation notes

- **Storage.** A new `files` table (`public_id`, `merchant_id`, `purpose`,
  `disk`, `path`, `thumb_path`, `content_type`, `bytes`, `width`, `height`,
  `client_uuid`, `attached_at`). Bytes live on the `public` disk under
  `files/{merchant_id}/`. Processing uses GD (resize, WebP/PNG/JPEG encode,
  alpha-flatten for signatures) — no new Composer dependency.
- **Attachment columns.** `merchants.logo_file_id`, `products.image_file_id`
  and `orders.signature_file_id` were added alongside the legacy `image` and
  `signature` columns, which are left untouched so nothing already relying on
  them breaks. A product's `image` block prefers `image_file_id` when set and
  falls back to the legacy path otherwise.
- **Deduplication uses `client_uuid`**, not the `Idempotency-Key` header the
  first draft of this spec showed — that matches how every other create
  endpoint in v1 dedupes (for example `POST /products`), rather than
  introducing a second mechanism for one endpoint.
- **Orphan sweep** is the `files:prune` scheduled command (hourly), deleting
  unattached files older than `exelo.files.orphan_ttl_hours` (24h).
- **Signed URLs** use Laravel's own signed routes (`GET /files/{id}/raw`, not
  part of the JSON API) rather than a CDN, since none is configured yet;
  swapping in a CDN later only changes `File::url()`.
- **New error codes** are in [errors.md](errors.md): `file.too_large`,
  `file.type_unsupported`, `file.corrupt`, `file.not_found`, `file.in_use`.
- **Not built:** CDN delivery, image cropping/rotation, video or document
  uploads. Only images are accepted.
