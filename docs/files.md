# Files & Media

Product images and customer signatures.

Uploads are decoupled from the records that reference them. A product or an
order is saved with a `file_id`, and the upload can be retried independently —
which matters when a 2 MB photo is going up a link that drops.

| Method | Path | Auth |
| --- | --- | --- |
| POST | [`/files`](#post-files) | Bearer |
| GET | [`/files/{id}`](#get-filesid) | Bearer or signed |
| DELETE | [`/files/{id}`](#delete-filesid) | Bearer |

---

## Why this is separate

The legacy app did two different things, both awkward:

- **Product images** were multipart fields on `POST /api/products`, so a failed
  upload lost the whole product record.
- **Signatures** were base64 data URLs inside the JSON body of
  `POST /api/cart/placePendingOrder`, inflating the request by a third and
  losing the order if it failed.

Both were read back from `${baseUrl}/public/<path>` — a public, guessable path
assembled by the client from a base-URL constant, so every asset broke whenever
the host changed.

---

## POST /files

Uploads a file.

**Request** — `multipart/form-data`

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `file` | binary | yes | The bytes |
| `purpose` | enum | yes | `product_image` \| `signature` \| `merchant_logo` |
| `client_uuid` | string | no | Deduplicates retries of the same upload |

```
POST /api/v1/files
Content-Type: multipart/form-data; boundary=----X
Idempotency-Key: 4f81d2a9-...

------X
Content-Disposition: form-data; name="purpose"

product_image
------X
Content-Disposition: form-data; name="file"; filename="rice.jpg"
Content-Type: image/jpeg

<binary>
------X--
```

**Constraints**

| Purpose | Max size | Accepted | Server processing |
| --- | --- | --- | --- |
| `product_image` | 5 MB | `image/jpeg`, `image/png`, `image/webp` | Re-encoded to WebP, `thumb` variant generated |
| `signature` | 512 KB | `image/png` | Trimmed and flattened |
| `merchant_logo` | 2 MB | `image/jpeg`, `image/png` | Resized |

**Response `201`**

```json
{
  "success": true,
  "message": "Upload complete",
  "data": {
    "file_id": "file_01JBXX1M4P",
    "purpose": "product_image",
    "url": "https://cdn.exelo.co/p/tmp/01JBXX1M4P.webp",
    "thumb_url": "https://cdn.exelo.co/p/tmp/01JBXX1M4P_thumb.webp",
    "bytes": 184203,
    "width": 1200,
    "height": 900,
    "content_type": "image/webp",
    "expires_at": "2026-09-19T14:35:00Z"
  }
}
```

`expires_at` applies only while the file is **unattached**. Once referenced by a
product or an order it is permanent; orphans are swept after 24 hours.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `413` | `file.too_large` | `error.details.max_bytes` |
| `415` | `file.type_unsupported` | `error.details.accepted` |
| `422` | `file.corrupt` | Not a decodable image |

### Client guidance

Compress before uploading. A phone camera photo is several megabytes; the
catalogue thumbnail is 200 px. Resize to roughly 1200 px on the long edge and
encode at quality 80 before the request — that is the difference between an
upload that completes on a weak link and one that does not.

Uploads should be queued and retried like any other offline write, with the
`file_id` attached to the product record once it lands.

---

## GET /files/{id}

Fetches a file. Normally the client just uses the `url` from the create
response or the parent record; this endpoint exists for re-resolving an id.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `variant` | enum | `original` | `original` \| `thumb` |

**Response `302`** — redirect to a signed CDN URL, or **`200`** with the bytes
if the storage backend cannot sign.

Signed URLs are valid for one hour. Assets are not publicly enumerable — the
legacy `/public/<path>` scheme let anyone who guessed a path read another
merchant's product images.

---

## DELETE /files/{id}

Deletes an unattached file. Attached files are removed by clearing the
reference on their parent record.

**Response `200`**

```json
{ "success": true, "message": "File deleted", "data": { "file_id": "file_01JBXX1M4P", "deleted": true } }
```

**Errors** — `409 file.in_use` with `error.details.attached_to`.
