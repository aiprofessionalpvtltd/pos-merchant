# Inventory & Catalogue

Products, categories, stock movement and low-stock alerts.

This module carries most of the offline weight: the product catalogue is mirrored
into SQLite on the device so scanning keeps working when the line drops. The
endpoints here are shaped to make that mirror correct: delta pulls, tombstones, and
client-supplied identity for products created offline.

> **Status: implemented.** All eleven endpoints are live and tested, and every
> response below is captured from the running API. Product images are the one open
> item: `image_file_id` is accepted but ignored until the Files module exists, and
> `image` shows only pictures uploaded through the legacy app.

| Method | Path | Auth |
| --- | --- | --- |
| GET | `/products` | Bearer · `inventory` |
| GET | `/products/{id}` | Bearer · `inventory` |
| GET | `/products/lookup` | Bearer · `pos` |
| POST | `/products` | Bearer · `inventory` |
| PATCH | `/products/{id}` | Bearer · `inventory` |
| DELETE | `/products/{id}` | Bearer · `inventory` |
| GET | `/categories` | Bearer |
| POST | `/categories` | Bearer · `inventory` |
| POST | `/inventory/transfers` | Bearer · `inventory` |
| PATCH | `/inventory/{product_id}/quantities` | Bearer · `inventory` |
| GET | `/inventory/alerts` | Bearer · `inventory` |

---

## Complete endpoint list

Full URL = `{BASE_URL}/api/v1` + path.

**Headers**

| Header | Sent on | Value |
| --- | --- | --- |
| `Accept` | Every request | `application/json` |
| `Content-Type` | Requests with a body | `application/json` |
| `Authorization` | Every request | `Bearer <token>` from [`POST /auth/pin/login`](auth.md#post-authpinlogin) |
| `If-Match` | `PATCH /products/{id}` (optional) | The product `version` you last read, so a change from another till is not overwritten |

| # | Method | Full path | Purpose | Needs | Body / query | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | GET | `/api/v1/products` | The catalogue, or a delta pull | Bearer, `inventory` | `type`, `category_id`, `q`, `updated_since`, `include_deleted`, `in_stock_only`, `page`, `per_page` | `200` | `403`, `422` |
| 2 | GET | `/api/v1/products/{id}` | One product | Bearer, `inventory` | — | `200` | `404 product.not_found` |
| 3 | GET | `/api/v1/products/lookup` | Barcode lookup at the register | Bearer, `pos` | `barcode`, `type` | `200` | `404 product.barcode_unknown`, `422` |
| 4 | POST | `/api/v1/products` | Add a product with its opening stock | Bearer, `inventory` | product fields, `client_uuid` | `201` new / `200` replay | `409 product.barcode_taken`, `422` |
| 5 | PATCH | `/api/v1/products/{id}` | Edit a product | Bearer, `inventory` | any subset, `idempotency_key` | `200` | `404`, `409 resource.version_conflict`, `409 product.barcode_taken`, `409 idempotency.key_reused`, `422` |
| 6 | DELETE | `/api/v1/products/{id}` | Delete a product (soft) | Bearer, `inventory` | — | `200` | `404`, `409 product.in_active_cart` |
| 7 | GET | `/api/v1/categories` | List categories | Bearer | `q`, `with_counts`, `updated_since` | `200` | `422` |
| 8 | POST | `/api/v1/categories` | Add a category | Bearer, `inventory` | `name` | `201` | `409 category.name_taken`, `422` |
| 9 | POST | `/api/v1/inventory/transfers` | Move stock between locations | Bearer, `inventory` | `product_id`, `quantity`, `from`, `to`, `idempotency_key` | `200` | `404`, `409 inventory.insufficient_quantity`, `409 idempotency.key_reused`, `422` |
| 10 | PATCH | `/api/v1/inventory/{product_id}/quantities` | Correct quantities after a recount or loss | Bearer, `inventory` | `in_shop`, `in_stock`, `reason`, `idempotency_key` | `200` | `404`, `409 idempotency.key_reused`, `422` |
| 11 | GET | `/api/v1/inventory/alerts` | Products running low | Bearer, `inventory` | `type`, `page`, `per_page` | `200` | `422` |

**Status codes shared by every endpoint**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.token_invalid` | Missing, revoked or expired token: clear the session |
| `403` | `auth.permission_denied` | The user lacks the needed permission (`error.details.required_permission` is `inventory` or `pos`) |
| `422` | `validation.failed` | Per-field messages in `error.details` |
| `429` | `rate_limited` | Slow down; honour `Retry-After` |

**Response envelope.** Every response carries `success`, `message`, `data` (or
`error`) and `meta.request_id` / `meta.server_time`; lists add
`meta.pagination`. Branch on `error.code`, never on `message`. See
[errors.md](errors.md).

---

## Concepts

### Who can do what

| Action | Owner | Staff with `inventory` | Staff with `pos` only |
| --- | --- | --- | --- |
| Browse and edit products, stock, alerts | yes | yes | no (`403`) |
| Scan a barcode (`/products/lookup`) | yes | only if they also hold `pos` | yes |
| Read categories | yes | yes | yes |
| Add a category | yes | yes | no |

Another shop's product or category always returns `404`, never `403`.

### Stock locations

Every product holds a quantity in three places:

| Location | Meaning |
| --- | --- |
| `shop` | On the shelf, sellable at the register |
| `stock` | In the back room |
| `transportation` | In transit between the two |

A location with no record counts as `0`. The API names them `in_shop`, `in_stock`
and `in_transportation` in responses, and `shop`, `stock`, `transportation` in
requests. The legacy API expressed this as a `type` path segment
(`/products/shop`) and four separate transfer endpoints; v1 uses a `type` query
parameter and one transfer endpoint with `from` and `to`.

### Money and prices

A price is `{ "amount": 1850, "currency": "USD" }` in **minor units** (cents for
USD, whole shillings for SLSH). Send `USD` or `SLSH`; an SLSH price is converted to
USD at the shop's own exchange rate when it is saved. Responses always show the USD
`price` and `price_alt` in SLSH at the shop's rate. **Prices are before VAT**:
`vat_rate` is a fraction (`0.05` is 5%) that defaults to the shop's rate from
[`GET /merchant/settings`](merchant.md#5-get-apiv1merchantsettings--get-the-shop-preferences).

### Version

Every product has a `version` that goes up by one on every edit, transfer,
correction and sale. Use it as `If-Match` to avoid overwriting a change made on
another till, and as a change marker when syncing. A `PATCH` or correction that
changes nothing does not bump it.

### Barcodes

A barcode is unique within a shop. It is compared ignoring case, dashes and
spaces (`rce005` finds `RCE-005`), and numeric codes also match their zero-padded
GTIN-8/12/13/14 forms, so a scanner that drops leading zeros still finds the
product.

### Idempotency

`PATCH /products/{id}`, `POST /inventory/transfers` and
`PATCH /inventory/{id}/quantities` need an `idempotency_key` (a UUID the app
generates once per attempt). A retry with the same key and body returns the
**first** response and changes nothing; the same key with a different body returns
`409 idempotency.key_reused`. Keys are remembered for 24 hours. `POST /products`
uses `client_uuid` as its identity instead (see [endpoint 4](#4-post-apiv1products--add-a-product)).

---

## 1. GET `/api/v1/products` — List the catalogue

**Purpose:** The catalogue. This is the **sync endpoint**: the device mirrors it into
SQLite, then keeps it fresh with delta pulls. Replaces `GET /api/products/merchant`,
`GET /api/inventory/products/shop`, `.../stock` and
`GET /api/inventory/products/{categoryId}/{type}`. Needs the `inventory` permission.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `type` | enum | all | `shop` \| `stock` \| `transportation`: only products with a record in that location |
| `category_id` | int | — | |
| `q` | string | — | Matches the product name or barcode |
| `updated_since` | timestamp | — | Delta pull, any ISO 8601 time. **Includes tombstones.** |
| `include_deleted` | bool | `false` | Deleted products are always included when `updated_since` is set |
| `in_stock_only` | bool | `false` | Excludes zero quantities (in `type` if given, else anywhere) |
| `page`, `per_page` | int | `1`, `50` | `per_page` is at most `200` |

**Response `200`**

```json
{
  "success": true,
  "data": [
    {
      "id": 646,
      "version": 1,
      "product_name": "Basmati Rice 5kg",
      "bar_code": "RCE-005",
      "price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
      "price_alt": { "amount": 148000, "currency": "SLSH", "display": "148,000 SLSH" },
      "vat_rate": 0.05,
      "category": { "id": 24, "name": "Dry goods" },
      "client_uuid": "6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60",
      "quantities": { "in_shop": 24, "in_stock": 0, "in_transportation": 0 },
      "limits": { "stock_limit": 10, "alarm_limit": 4 },
      "image": null,
      "total_sold": 0,
      "created_at": "2026-09-18T09:40:00Z",
      "updated_at": "2026-09-20T08:53:29Z",
      "deleted_at": null
    },
    {
      "id": 647,
      "version": 1,
      "product_name": "Dates Premium 1kg",
      "bar_code": "DTS-001",
      "price": { "amount": 400, "currency": "USD", "display": "$4.00" },
      "price_alt": { "amount": 32000, "currency": "SLSH", "display": "32,000 SLSH" },
      "vat_rate": 0.05,
      "category": null,
      "client_uuid": "u2",
      "quantities": { "in_shop": 2, "in_stock": 0, "in_transportation": 0 },
      "limits": { "stock_limit": 10, "alarm_limit": 4 },
      "image": null,
      "total_sold": 0,
      "created_at": "2026-09-20T08:53:29Z",
      "updated_at": "2026-09-20T08:53:29Z",
      "deleted_at": null
    }
  ],
  "meta": {
    "request_id": "req_01M2Z0AF0A9WYQRA4N7Z0DT54R",
    "server_time": "2026-09-20T08:53:29Z",
    "pagination": { "page": 1, "per_page": 2, "total": 2, "total_pages": 1, "has_more": false },
    "sync_cursor": "2026-09-20T08:53:29Z"
  }
}
```

| Field | Meaning |
| --- | --- |
| `price`, `price_alt` | USD price and its SLSH equivalent at the shop's rate |
| `vat_rate` | Fraction, `0.05` = 5% |
| `category` | `null` when the product has none |
| `client_uuid` | The device id it was created with; `null` for products created in the legacy app |
| `quantities` | On hand in each location |
| `limits` | `alarm_limit` warns when the **shelf** runs low, `stock_limit` when the **back room** does; `0` switches an alert off |
| `image` | `null` unless the legacy app uploaded a picture; then `{ id, url, thumb_url }` with absolute URLs |
| `total_sold` | Units sold across all orders |

### Delta pull and tombstones

Pass `meta.sync_cursor` from the last pull as `updated_since`. Changed products come
back in full; **deleted products come back as tombstones** with only three fields:

```json
{ "id": 646, "version": 5, "deleted_at": "2026-09-20T08:53:29Z" }
```

The client deletes those rows from its mirror. This is what the legacy client is
missing: its local `products` table never prunes, so a product deleted on one till
stays scannable and sellable on another. Store the new `meta.sync_cursor` after every
pull.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.permission_denied` | No `inventory` permission |
| `422` | `validation.failed` | An unknown `type`, `per_page` over `200`, or a bad `updated_since` |

## 2. GET `/api/v1/products/{id}` — Get one product

**Purpose:** One product, in the same shape as a list entry. Needs the `inventory`
permission.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "id": 646,
    "version": 1,
    "product_name": "Basmati Rice 5kg",
    "bar_code": "RCE-005",
    "price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
    "price_alt": { "amount": 148000, "currency": "SLSH", "display": "148,000 SLSH" },
    "vat_rate": 0.05,
    "category": { "id": 24, "name": "Dry goods" },
    "client_uuid": "6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60",
    "quantities": { "in_shop": 24, "in_stock": 0, "in_transportation": 0 },
    "limits": { "stock_limit": 10, "alarm_limit": 4 },
    "image": null,
    "total_sold": 0,
    "created_at": "2026-09-18T09:40:00Z",
    "updated_at": "2026-09-20T08:53:29Z",
    "deleted_at": null
  }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `product.not_found` | No such product in this shop (or it was deleted) |

```json
{ "success": false, "message": "We could not find that product", "error": { "code": "product.not_found" } }
```

## 3. GET `/api/v1/products/lookup` — Look up a product by barcode

**Purpose:** Barcode lookup at the register. It runs on every scan, so it is small
and fast. Replaces `GET /api/products/barcode/{barcode}/{type}`. Needs the `pos`
permission.

**Query**

| Param | Type | Required | Notes |
| --- | --- | --- | --- |
| `barcode` | string | yes | The raw scan, up to 64 characters. Matched as described under [Barcodes](#barcodes). |
| `type` | enum | no | `shop` (default) or `stock`: the product must have a record in that location |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "id": 646,
    "version": 1,
    "product_name": "Basmati Rice 5kg",
    "bar_code": "RCE-005",
    "price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
    "price_alt": { "amount": 148000, "currency": "SLSH", "display": "148,000 SLSH" },
    "vat_rate": 0.05,
    "category": { "id": 24, "name": "Dry goods" },
    "quantities": { "in_shop": 24, "in_stock": 0 },
    "image": { "thumb_url": null }
  }
}
```

The example scanned `rce005`; the product is stored as `RCE-005`.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `product.barcode_unknown` | No product with that barcode, or none in that `type` |
| `422` | `validation.failed` | `barcode` is missing |

```json
{
  "success": false,
  "message": "No product with that barcode",
  "error": {
    "code": "product.barcode_unknown",
    "details": { "barcode": "RCE-999", "normalised": "RCE999" }
  }
}
```

The register offers "add this product" on a `404`, so the shopkeeper can create it
from the scan.

**Offline.** The client checks its SQLite mirror first and only calls this on a
miss, with a short timeout. A failure here is never fatal: the scan falls back to
the mirror.

## 4. POST `/api/v1/products` — Add a product

**Purpose:** Creates a product with its opening stock. **Idempotent through
`client_uuid`.** Replaces the multipart `POST /api/products`. Needs the `inventory`
permission.

**Request**

```json
{
  "client_uuid": "6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60",
  "product_name": "Basmati Rice 5kg",
  "bar_code": "RCE-005",
  "price": { "amount": 1850, "currency": "USD" },
  "vat_rate": 0.05,
  "category_id": 24,
  "quantity": 24,
  "type": "shop",
  "limits": { "stock_limit": 10, "alarm_limit": 4 },
  "created_at": "2026-09-18T09:40:00Z"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `client_uuid` | string | yes | Generated on the device when the shopkeeper saves; up to 64 characters |
| `product_name` | string | yes | Up to 255 characters |
| `bar_code` | string | no | Unique per shop when present |
| `price` | Money | yes | `amount` 0 to 100,000,000; `currency` `USD` or `SLSH` |
| `vat_rate` | number | no | `0` to `1`. Defaults to the shop's rate. |
| `category_id` | int | no | Must be one of the shop's categories |
| `quantity` | int | yes | Opening quantity, 0 to 1,000,000 |
| `type` | enum | yes | `shop` \| `stock`: where the opening quantity sits |
| `limits.stock_limit` | int | no | Restock threshold for the back room. Default `0` (off) |
| `limits.alarm_limit` | int | no | Low-stock alarm for the shelf. Default `0` (off) |
| `image_file_id` | string | no | Accepted and ignored until the Files module exists |
| `created_at` | timestamp | no | When it was really created offline |
| `idempotency_key` | string | no | Optional here: `client_uuid` already identifies the request |

### `client_uuid` and offline creation

This fixes products created offline being stranded on the device. The device
generates a `client_uuid` when the shopkeeper saves, stores it with the queued row,
and sends it on sync. The response echoes it next to the real server `id`, so the
device can rewrite its local row instead of orphaning it.

**Sending the same `client_uuid` again is a replay, not an error.** The server
returns `200` with the existing product and the message `Product already added`;
the request body is ignored, so a retry after a lost response never edits or
duplicates anything.

**Response `201`**

```json
{
  "success": true,
  "message": "Product added",
  "data": {
    "id": 646,
    "version": 1,
    "product_name": "Basmati Rice 5kg",
    "bar_code": "RCE-005",
    "price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
    "price_alt": { "amount": 148000, "currency": "SLSH", "display": "148,000 SLSH" },
    "vat_rate": 0.05,
    "category": { "id": 24, "name": "Dry goods" },
    "client_uuid": "6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60",
    "quantities": { "in_shop": 24, "in_stock": 0, "in_transportation": 0 },
    "limits": { "stock_limit": 10, "alarm_limit": 4 },
    "image": null,
    "total_sold": 0,
    "created_at": "2026-09-18T09:40:00Z",
    "updated_at": "2026-09-20T08:53:29Z",
    "deleted_at": null
  }
}
```

**Response `200` (replay):** the same product, with `"message": "Product already added"`.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.permission_denied` | No `inventory` permission |
| `409` | `product.barcode_taken` | Another product owns the barcode (`error.details.existing_product_id`, `error.field: bar_code`) |
| `422` | `validation.failed` | Missing fields, an unknown or foreign `category_id`, a bad currency |

```json
{
  "success": false,
  "message": "That barcode is already on another product",
  "error": {
    "code": "product.barcode_taken",
    "field": "bar_code",
    "details": { "existing_product_id": 646 }
  }
}
```

```json
{
  "success": false,
  "message": "Please check the form",
  "error": {
    "code": "validation.failed",
    "details": {
      "client_uuid": ["The client uuid field is required."],
      "price.currency": ["The selected price.currency is invalid."],
      "quantity": ["The quantity field is required."],
      "type": ["The selected type is invalid."]
    }
  }
}
```

## 5. PATCH `/api/v1/products/{id}` — Edit a product

**Purpose:** Partial update. **Idempotent: `idempotency_key` is required.** Replaces
`POST /api/products/{id}`, which used multipart and a POST for what is a PATCH.
Needs the `inventory` permission.

**Headers:** `If-Match: 1` (optional): the `version` you last read.

**Request:** any subset

```json
{
  "product_name": "Basmati Rice 5kg Premium",
  "price": { "amount": 1900, "currency": "USD" },
  "idempotency_key": "b8e0d3f5-4c1a-4e7b-9a52-0d6c3f7e8a19"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `product_name` | string | no | |
| `bar_code` | string \| null | no | Must stay unique in the shop |
| `price` | Money | no | Updates `price_alt` at the shop's current rate |
| `vat_rate` | number | no | `0` to `1` |
| `category_id` | int \| null | no | `null` removes the category |
| `limits.stock_limit`, `limits.alarm_limit` | int | no | |
| `idempotency_key` | string | yes | UUID, one per attempt |

`client_uuid` and quantities **cannot** be changed here. Quantities change through
[transfers](#9-post-apiv1inventorytransfers--move-stock-between-locations) or a
[correction](#10-patch-apiv1inventoryproduct_idquantities--correct-the-quantities),
never through a product edit, which keeps an audit trail of every stock movement.

**Response `200`**

```json
{
  "success": true,
  "message": "Product saved",
  "data": {
    "id": 646,
    "version": 2,
    "product_name": "Basmati Rice 5kg Premium",
    "bar_code": "RCE-005",
    "price": { "amount": 1900, "currency": "USD", "display": "$19.00" },
    "price_alt": { "amount": 152000, "currency": "SLSH", "display": "152,000 SLSH" },
    "vat_rate": 0.05,
    "category": { "id": 24, "name": "Dry goods" },
    "client_uuid": "6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60",
    "quantities": { "in_shop": 24, "in_stock": 0, "in_transportation": 0 },
    "limits": { "stock_limit": 10, "alarm_limit": 4 },
    "image": null,
    "total_sold": 0,
    "created_at": "2026-09-18T09:40:00Z",
    "updated_at": "2026-09-20T08:53:29Z",
    "deleted_at": null
  }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `product.not_found` | No such product in this shop |
| `409` | `resource.version_conflict` | `If-Match` is stale; `error.details.current` has the current product |
| `409` | `product.barcode_taken` | Another product owns the barcode |
| `409` | `idempotency.key_reused` | Same key, different request |
| `422` | `validation.failed` | `idempotency_key` missing, or a bad field |

```json
{
  "success": false,
  "message": "This product was changed on another device",
  "error": {
    "code": "resource.version_conflict",
    "details": { "current": { "id": 646, "version": 2, "product_name": "Basmati Rice 5kg Premium", "...": "full product as in GET /products/{id}" } }
  }
}
```

## 6. DELETE `/api/v1/products/{id}` — Delete a product

**Purpose:** Soft delete. The row stays with `deleted_at` set so other devices learn
about it through the [delta pull](#delta-pull-and-tombstones). Needs the `inventory`
permission.

**Response `200`**

```json
{
  "success": true,
  "message": "Product deleted",
  "data": { "id": 646, "deleted_at": "2026-09-20T08:53:29Z" }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `product.not_found` | No such product in this shop |
| `409` | `product.in_active_cart` | The product is on an open ticket (`error.details.cart_ids`); remove it from the ticket first |

## 7. GET `/api/v1/categories` — List categories

**Purpose:** The shop's categories. Replaces `GET /api/categories/merchant` and
`POST /api/categories/search` (a POST that performed a search). Any signed-in user
can read categories, so a till can show them.

**Query**

| Param | Type | Notes |
| --- | --- | --- |
| `q` | string | Name search |
| `with_counts` | bool | Adds `product_count` to each category |
| `updated_since` | timestamp | Delta pull; includes deleted categories with `deleted_at` set |

**Response `200`** (with `?with_counts=1`)

```json
{
  "success": true,
  "data": [
    { "id": 24, "name": "Dry goods", "product_count": 1, "updated_at": "2026-09-20T08:53:29Z" }
  ]
}
```

Without `with_counts`, `product_count` is left out. Categories are sorted by name.

## 8. POST `/api/v1/categories` — Add a category

**Purpose:** Creates a category. Needs the `inventory` permission.

**Request**

```json
{ "name": "Dry goods" }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `name` | string | yes | Up to 100 characters; unique per shop, ignoring case |
| `idempotency_key` | string | no | A retry with the same key returns the first response |

**Response `201`**

```json
{
  "success": true,
  "message": "Category created",
  "data": { "id": 24, "name": "Dry goods", "product_count": 0 }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `409` | `category.name_taken` | It already exists; the existing one is in `error.details.category` so the client can just use it |
| `422` | `validation.failed` | `name` missing |

```json
{
  "success": false,
  "message": "That category already exists",
  "error": { "code": "category.name_taken", "details": { "category": { "id": 24, "name": "Dry goods" } } }
}
```

## 9. POST `/api/v1/inventory/transfers` — Move stock between locations

**Purpose:** Moves stock from one location to another. **Idempotent: `idempotency_key`
is required.** Replaces all four of `POST /api/inventory/transfer/shop-to-stock`,
`stock-to-shop`, `shop-to-transportation` and `stock-to-transportation`. Needs the
`inventory` permission.

**Request**

```json
{
  "product_id": 646,
  "quantity": 5,
  "from": "shop",
  "to": "stock",
  "note": "End of day return",
  "idempotency_key": "3a7f1e28-9c04-4b16-a5d2-6e8f0b1c7d33"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `product_id` | int | yes | |
| `quantity` | int | yes | 1 to 1,000,000 |
| `from` | enum | yes | `shop` \| `stock` \| `transportation` |
| `to` | enum | yes | Must differ from `from` |
| `note` | string | no | Recorded on the movement |
| `idempotency_key` | string | yes | UUID, one per attempt |

**Response `200`**

```json
{
  "success": true,
  "message": "5 moved from shop to stock",
  "data": {
    "transfer_id": 738,
    "product_id": 646,
    "quantity": 5,
    "from": "shop",
    "to": "stock",
    "quantities_after": { "in_shop": 19, "in_stock": 5, "in_transportation": 0 },
    "created_at": "2026-09-20T08:53:29Z"
  }
}
```

The product's `version` goes up by one and a movement is written to its history.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `product.not_found` | No such product in this shop |
| `409` | `inventory.insufficient_quantity` | Not enough in `from` (`error.details.available`) |
| `409` | `idempotency.key_reused` | Same key, different request |
| `422` | `validation.failed` | `from` equals `to`, a zero quantity, an unknown location |

```json
{
  "success": false,
  "message": "Not enough stock to move",
  "error": { "code": "inventory.insufficient_quantity", "details": { "available": 19 } }
}
```

## 10. PATCH `/api/v1/inventory/{product_id}/quantities` — Correct the quantities

**Purpose:** A manual stock correction: a recount, breakage, shrinkage. Replaces
`POST /api/inventory/updateInventory`. Unlike a transfer this changes the **total** on
hand, so it always records a reason. Needs the `inventory` permission.

**Request**

```json
{
  "in_shop": 15,
  "in_stock": 8,
  "reason": "recount",
  "note": "Monthly count",
  "idempotency_key": "e51c8a02-7d3b-4f96-b0a4-2c9d1e6f3a58"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `in_shop` | int | at least one of `in_shop`, `in_stock` | The new **absolute** value, not a change. 0 to 1,000,000 |
| `in_stock` | int | at least one of the two | |
| `reason` | enum | yes | `recount` \| `damage` \| `theft` \| `expiry` \| `correction` |
| `note` | string | no | |
| `idempotency_key` | string | yes | UUID, one per attempt |

**Response `200`**

```json
{
  "success": true,
  "message": "Quantities updated",
  "data": {
    "product_id": 646,
    "quantities": { "in_shop": 15, "in_stock": 8, "in_transportation": 0 },
    "adjustment": { "in_shop": -4, "in_stock": 3 },
    "reason": "recount",
    "version": 4
  }
}
```

`adjustment` shows the change per location; a location whose value did not change is
left out, and when nothing changed `adjustment` is empty and `version` stays the same.
One history row is written per location that changed.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `product.not_found` | No such product in this shop |
| `409` | `idempotency.key_reused` | Same key, different request |
| `422` | `validation.failed` | Neither quantity sent, a negative quantity, or an unknown `reason` |

## 11. GET `/api/v1/inventory/alerts` — Low-stock alerts

**Purpose:** Products at or below their thresholds. Replaces
`GET /api/getProductsByAlarmLimit` and `GET /api/getProductsByStockLimit`. Needs the
`inventory` permission.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `type` | enum | `alarm` | `alarm` (the **shelf** is at or below `alarm_limit`) \| `restock` (the **back room** is at or below `stock_limit`) |
| `page`, `per_page` | int | `1`, `50` | `per_page` is at most `200` |

**Response `200`** (`type=alarm`)

```json
{
  "success": true,
  "data": [
    {
      "id": 647,
      "product_name": "Dates Premium 1kg",
      "bar_code": "DTS-001",
      "quantities": { "in_shop": 2, "in_stock": 0 },
      "limits": { "stock_limit": 10, "alarm_limit": 4 },
      "shortfall": 2,
      "severity": "warning"
    }
  ],
  "meta": {
    "pagination": { "page": 1, "per_page": 50, "total": 1, "total_pages": 1, "has_more": false },
    "summary": { "alarm_count": 1, "restock_count": 2 }
  }
}
```

| Field | Meaning |
| --- | --- |
| `severity` | `critical` when the counted location is at `0`, otherwise `warning` |
| `shortfall` | How many to move or buy to reach the threshold (`limit` minus quantity) |
| `meta.summary` | Both counts, so the badge on the inventory screen needs only one call |

A limit of `0` switches that alert off for the product, so a product with no limits
set never appears. Results are sorted with the emptiest first. With
`type=restock` the same product above appears with `shortfall: 10` and
`severity: "critical"`, because its back room is empty.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.permission_denied` | No `inventory` permission |
| `422` | `validation.failed` | An unknown `type` |

---

## Step by step: adding stock for a new product

```
GET   /categories                          → pick or find the category
POST  /categories  { name }                → 201 (or 409 with the existing one)
POST  /products    { client_uuid, ... }    → 201, quantities.in_shop = opening quantity
GET   /products/lookup?barcode=...         → the register can now scan it
```

## Step by step: keeping the device catalogue current

```
GET /products                                → first sync: page through everything
                                               store meta.sync_cursor
GET /products?updated_since=<sync_cursor>    → later syncs: changed rows + tombstones
                                               delete tombstoned ids locally, store the new cursor
```

| State | Client does |
| --- | --- |
| `409 product.barcode_taken` | Offer to open the existing product (`existing_product_id`) |
| `200 Product already added` on create | Adopt the returned product, drop the queued row |
| `409 resource.version_conflict` | Show `error.details.current` and let the user re-apply the edit |
| `409 inventory.insufficient_quantity` | Show how many are available and let the user change the quantity |
| `409 product.in_active_cart` | Tell the user to remove it from the open ticket first |
| `403 auth.permission_denied` | Hide the inventory screens for that user |
| No network | Queue creates with their `client_uuid`; replay them when online |

---

## Postman / curl quick start

```bash
BASE=https://your-host/api/v1

curl $BASE/products?per_page=50 -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl "$BASE/products/lookup?barcode=RCE-005" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X POST $BASE/products -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"client_uuid":"6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60","product_name":"Basmati Rice 5kg","bar_code":"RCE-005","price":{"amount":1850,"currency":"USD"},"quantity":24,"type":"shop"}'

curl -X PATCH $BASE/products/646 -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -H 'If-Match: 1' \
  -d '{"price":{"amount":1900,"currency":"USD"},"idempotency_key":"b8e0d3f5-4c1a-4e7b-9a52-0d6c3f7e8a19"}'

curl -X POST $BASE/inventory/transfers -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"product_id":646,"quantity":5,"from":"shop","to":"stock","idempotency_key":"3a7f1e28-9c04-4b16-a5d2-6e8f0b1c7d33"}'

curl -X PATCH $BASE/inventory/646/quantities -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"in_shop":15,"in_stock":8,"reason":"recount","idempotency_key":"e51c8a02-7d3b-4f96-b0a4-2c9d1e6f3a58"}'

curl "$BASE/inventory/alerts?type=restock" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X DELETE $BASE/products/646 -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

---

## Implementation notes

Built on the existing tables (`products`, `product_inventories`, `categories`,
`inventory_histories`), so shops already using the legacy app see their catalogue
unchanged. Two migrations add what v1 needs:

| Table | Added | Why |
| --- | --- | --- |
| `products` | `version`, `client_uuid` (unique per shop) | `If-Match`, sync, and safe offline creation |
| `products` | `category_id` made nullable | A product can be added before its category exists |
| `inventory_histories` | `kind`, `reason`, `note` | Records whether a row is an opening balance, a transfer, an adjustment, a sale or a return |

How it behaves:

- **Prices** are stored in USD as before (`price`, `vat` as a whole percent,
  `total_price` with VAT). `vat_rate` in the API is the fraction.
- **Stock** lives in `product_inventories`, one row per product and location.
- **Delta pull.** `updated_since` accepts any ISO 8601 time and returns changed
  products plus tombstones for deleted ones.
- **Lookup** returns `404 product.barcode_unknown` both for an unknown barcode and
  for a product that has no stock record in the requested `type`.
- **Deleting** is refused while the product sits on an open ticket, so a till never
  holds a line for a product that no longer exists.
- **Sales** take stock off the shelf when an order is paid or completed, and bump the
  product `version`; see [orders.md](orders.md).
- **Alerts** do not include an `image`; the alert screen shows name, quantities and
  severity only.
- **Validation messages** are the standard Laravel wording (for example `The
  quantity field is required.`) under `error.details`, keyed by field. Branch on
  `error.code`, not on the text.
- **Not built yet:** image upload (`image_file_id`, waits for the Files module).
- **Error codes** are listed in [errors.md](errors.md): `product.not_found`,
  `product.barcode_unknown`, `product.barcode_taken`, `product.in_active_cart`,
  `category.name_taken`, `inventory.insufficient_quantity`.
- **Legacy routes** (`/api/products/*`, `/api/inventory/*`, `/api/categories/*`)
  keep working alongside.
