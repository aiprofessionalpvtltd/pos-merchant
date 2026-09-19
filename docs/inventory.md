# Inventory & Catalogue

Products, categories, stock movement and low-stock alerts.

This module carries most of the offline weight: the product catalogue is
mirrored into SQLite on the device so scanning keeps working when the line
drops. The endpoints here are shaped to make that mirror correct — delta pulls,
tombstones, and client-supplied identity for products created offline.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/products`](#get-products) | Bearer · `inventory` |
| GET | [`/products/{id}`](#get-productsid) | Bearer · `inventory` |
| GET | [`/products/lookup`](#get-productslookup) | Bearer · `pos` |
| POST | [`/products`](#post-products) | Bearer · `inventory` |
| PATCH | [`/products/{id}`](#patch-productsid) | Bearer · `inventory` |
| DELETE | [`/products/{id}`](#delete-productsid) | Bearer · `inventory` |
| GET | [`/categories`](#get-categories) | Bearer |
| POST | [`/categories`](#post-categories) | Bearer · `inventory` |
| POST | [`/inventory/transfers`](#post-inventorytransfers) | Bearer · `inventory` |
| PATCH | [`/inventory/{product_id}/quantities`](#patch-inventoryproduct_idquantities) | Bearer · `inventory` |
| GET | [`/inventory/alerts`](#get-inventoryalerts) | Bearer · `inventory` |

---

## Stock locations

Every product holds a quantity in three places:

| Location | Meaning |
| --- | --- |
| `shop` | On the shelf, sellable at the register |
| `stock` | In the back room |
| `transportation` | In transit between the two |

The legacy API expressed this as a `type` path segment (`/products/shop`,
`/products/barcode/{code}/stock`) and four separate transfer endpoints. v1 uses
a `type` query parameter and one transfer endpoint with `from` and `to`.

---

## GET /products

The catalogue. This is the sync endpoint — the device mirrors it into SQLite.
Replaces `GET /api/products/merchant`, `GET /api/inventory/products/shop`,
`GET /api/inventory/products/stock` and
`GET /api/inventory/products/{categoryId}/{type}`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `type` | enum | all | `shop` \| `stock` \| `transportation` |
| `category_id` | int | — | |
| `q` | string | — | Name or barcode |
| `updated_since` | timestamp | — | Delta pull. **Includes tombstones.** |
| `include_deleted` | bool | `false` | Forced `true` when `updated_since` is set |
| `in_stock_only` | bool | `false` | Excludes zero quantities |
| `page`, `per_page` | int | `1`, `50` | Max `200` |

**Response `200`**

```json
{
  "success": true,
  "data": [
    {
      "id": 101,
      "version": 3,
      "product_name": "Basmati Rice 5kg",
      "bar_code": "RCE-005",
      "price":     { "amount": 1850, "currency": "USD", "display": "$18.50" },
      "price_alt": { "amount": 148000, "currency": "SLSH", "display": "148,000 SLSH" },
      "vat_rate": 0.05,
      "category": { "id": 1, "name": "Dry goods" },
      "quantities": { "in_shop": 24, "in_stock": 86, "in_transportation": 0 },
      "limits": { "stock_limit": 10, "alarm_limit": 4 },
      "image": {
        "id": "file_01JBXX1M4P",
        "url": "https://cdn.exelo.co/p/101/rice.png",
        "thumb_url": "https://cdn.exelo.co/p/101/rice_thumb.png"
      },
      "total_sold": 124,
      "created_at": "2026-04-02T09:00:00Z",
      "updated_at": "2026-09-14T11:22:03Z",
      "deleted_at": null
    }
  ],
  "meta": {
    "pagination": { "page": 1, "per_page": 50, "total": 318, "total_pages": 7, "has_more": true },
    "sync_cursor": "2026-09-18T14:03:11Z"
  }
}
```

### Tombstones

With `updated_since`, deleted products come back with `deleted_at` set and the
rest of the fields minimal:

```json
{ "id": 907, "version": 5, "deleted_at": "2026-09-16T10:04:00Z" }
```

The client deletes those rows from its mirror. **This is what the current
client is missing** — the local `products` table never prunes, so a product
deleted on one till stays scannable and sellable on another.

Store `meta.sync_cursor` and pass it as the next `updated_since`.

### Image URLs

Absolute, and pointing at opaque paths. The legacy client built these itself as
`${baseUrl}/public${item['image']}`, which meant a public guessable path and a
client that broke whenever the host moved. `thumb_url` exists because the
inventory grid was pulling full-size images over a phone connection.

---

## GET /products/{id}

One product. Same object as the list entry.

**Errors** — `404 product.not_found`.

---

## GET /products/lookup

Barcode lookup at the register. Replaces
`GET /api/products/barcode/{barcode}/{type}`.

This is the hottest path in the app — it runs on every scan — so it is
deliberately small and fast.

**Query**

| Param | Type | Required | Notes |
| --- | --- | --- | --- |
| `barcode` | string | yes | Raw scan. Server normalises GTIN-8/12/13/14. |
| `type` | enum | no | Default `shop` |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "id": 101,
    "version": 3,
    "product_name": "Basmati Rice 5kg",
    "bar_code": "RCE-005",
    "price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
    "price_alt": { "amount": 148000, "currency": "SLSH", "display": "148,000 SLSH" },
    "vat_rate": 0.05,
    "category": { "id": 1, "name": "Dry goods" },
    "quantities": { "in_shop": 24, "in_stock": 86 },
    "image": { "thumb_url": "https://cdn.exelo.co/p/101/rice_thumb.png" }
  }
}
```

**Response `404` — unknown barcode**

```json
{
  "success": false,
  "message": "No product with that barcode",
  "error": {
    "code": "product.barcode_unknown",
    "details": { "barcode": "RCE-999", "normalised": "0000RCE999" }
  }
}
```

The register offers "add this product" on a `404`, so the shopkeeper can create
it from the scan.

**Offline.** The client checks its SQLite mirror first and only calls this on a
miss, with a short timeout. A failure here is never fatal — the scan falls back
to the mirror.

---

## POST /products

Creates a product. **Idempotent — key required.** Replaces the multipart
`POST /api/products`.

**Request**

```json
{
  "client_uuid": "6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60",
  "product_name": "Basmati Rice 5kg",
  "bar_code": "RCE-005",
  "price": { "amount": 1850, "currency": "USD" },
  "vat_rate": 0.05,
  "category_id": 1,
  "quantity": 24,
  "type": "shop",
  "limits": { "stock_limit": 10, "alarm_limit": 4 },
  "image_file_id": "file_01JBXX1M4P",
  "created_at": "2026-09-18T09:40:00Z",
  "idempotency_key": "6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `client_uuid` | string | yes | Generated on the device at save time |
| `product_name` | string | yes | |
| `bar_code` | string | no | Unique per merchant when present |
| `price` | Money | yes | |
| `vat_rate` | float | no | Defaults to the merchant's rate |
| `category_id` | int | no | |
| `quantity` | int | yes | Opening quantity |
| `type` | enum | yes | `shop` \| `stock` — where the opening quantity sits |
| `limits.stock_limit` | int | no | Restock threshold |
| `limits.alarm_limit` | int | no | Low-stock alarm threshold |
| `image_file_id` | string | no | From [`POST /files`](files.md#post-files) |
| `created_at` | timestamp | no | When it was actually created offline |

### `client_uuid` and offline creation

This is the fix for products created while offline being stranded on the
device. Today they queue into a local `pending_creates` table whose only
identity is an autoincrement row id — and the function that would sync them,
`syncPendingCreates()`, **is never called**, so they stay on the phone forever
and are lost on reinstall.

In v1 the device generates a `client_uuid` when the shopkeeper saves, stores it
with the queued row, and sends it on sync. The response echoes it alongside the
real server `id`, so the device can rewrite its local row instead of orphaning
it. Reusing the same `client_uuid` returns the existing product rather than
creating a duplicate — so a replay after a lost response is safe.

Images are uploaded separately and referenced by id, so a large photo does not
have to survive the same request as the product record.

**Response `201`**

```json
{
  "success": true,
  "message": "Product added",
  "data": {
    "id": 812,
    "client_uuid": "6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60",
    "version": 1,
    "product_name": "Basmati Rice 5kg",
    "bar_code": "RCE-005",
    "price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
    "quantities": { "in_shop": 24, "in_stock": 0, "in_transportation": 0 },
    "created_at": "2026-09-18T09:40:00Z"
  }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `409` | `product.barcode_taken` | `error.details.existing_product_id` |
| `409` | `product.duplicate_client_uuid` | Returns the existing product in `error.details.product` |
| `422` | `validation.failed` | |

---

## PATCH /products/{id}

Partial update. **Idempotent — key required.** Replaces
`POST /api/products/{id}`, which used multipart and a POST for what is a PATCH.

**Request**

```json
{
  "product_name": "Basmati Rice 5kg Premium",
  "price": { "amount": 1900, "currency": "USD" },
  "image_file_id": "file_01JBXX9R2T",
  "idempotency_key": "b8e0d3f5-..."
}
```

Any subset of the create fields except `client_uuid` and `quantity`. Quantities
change through [transfers](#post-inventorytransfers) or a
[correction](#patch-inventoryproduct_idquantities), never through a product
edit — that keeps an audit trail of stock movement.

Send `If-Match: <version>` to avoid overwriting a change made on another till.

**Response `200`** — the updated product with an incremented `version`.

**Errors** — `409 resource.version_conflict`, `409 product.barcode_taken`,
`404 product.not_found`.

---

## DELETE /products/{id}

Soft delete. The row stays with `deleted_at` set so other devices learn about it
through `updated_since`.

**Response `200`**

```json
{
  "success": true,
  "message": "Product deleted",
  "data": { "id": 812, "deleted_at": "2026-09-18T14:31:02Z" }
}
```

**Errors** — `409 product.in_active_cart` when the product is on an open
ticket, with `error.details.cart_ids`.

---

## GET /categories

Replaces `GET /api/categories/merchant` and `POST /api/categories/search` —
the latter being a POST that performed a search.

**Query**

| Param | Type | Notes |
| --- | --- | --- |
| `q` | string | Name search |
| `with_counts` | bool | Include product counts |
| `updated_since` | timestamp | Delta pull |

**Response `200`**

```json
{
  "success": true,
  "data": [
    { "id": 1, "name": "Dry goods", "product_count": 4, "updated_at": "2026-08-01T00:00:00Z" },
    { "id": 2, "name": "Dairy",     "product_count": 1, "updated_at": "2026-08-01T00:00:00Z" },
    { "id": 3, "name": "Produce",   "product_count": 1, "updated_at": "2026-08-01T00:00:00Z" },
    { "id": 4, "name": "Beverages", "product_count": 2, "updated_at": "2026-08-01T00:00:00Z" }
  ]
}
```

---

## POST /categories

**Request**

```json
{ "name": "Frozen", "idempotency_key": "..." }
```

**Response `201`**

```json
{
  "success": true,
  "message": "Category created",
  "data": { "id": 5, "name": "Frozen", "product_count": 0 }
}
```

**Errors** — `409 category.name_taken`, returning the existing category in
`error.details` so the client can just use it.

---

## POST /inventory/transfers

Moves stock between locations. **Idempotent — key required.** Replaces all four
of `POST /api/inventory/transfer/shop-to-stock`, `stock-to-shop`,
`shop-to-transportation` and `stock-to-transportation`.

**Request**

```json
{
  "product_id": 101,
  "quantity": 5,
  "from": "shop",
  "to": "stock",
  "note": "End of day return",
  "idempotency_key": "3a7f1e28-..."
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `product_id` | int | yes | |
| `quantity` | int | yes | Positive |
| `from` | enum | yes | `shop` \| `stock` \| `transportation` |
| `to` | enum | yes | Must differ from `from` |
| `note` | string | no | Recorded on the movement |

**Response `200`**

```json
{
  "success": true,
  "message": "5 moved from shop to stock",
  "data": {
    "transfer_id": 7741,
    "product_id": 101,
    "quantity": 5,
    "from": "shop",
    "to": "stock",
    "quantities_after": { "in_shop": 19, "in_stock": 91, "in_transportation": 0 },
    "created_at": "2026-09-18T14:33:20Z"
  }
}
```

**Errors** — `409 inventory.insufficient_quantity` with
`error.details.available`.

---

## PATCH /inventory/{product_id}/quantities

Manual stock correction — a recount, breakage, shrinkage. Replaces
`POST /api/inventory/updateInventory`.

Unlike a transfer this changes the total on hand, so it always records a reason.

**Request**

```json
{
  "in_shop": 20,
  "in_stock": 80,
  "reason": "recount",
  "note": "Monthly count",
  "idempotency_key": "..."
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `in_shop` | int | no | Absolute value, not a delta |
| `in_stock` | int | no | |
| `reason` | enum | yes | `recount` \| `damage` \| `theft` \| `expiry` \| `correction` |
| `note` | string | no | |

**Response `200`**

```json
{
  "success": true,
  "message": "Quantities updated",
  "data": {
    "product_id": 101,
    "quantities": { "in_shop": 20, "in_stock": 80, "in_transportation": 0 },
    "adjustment": { "in_shop": -4, "in_stock": -6 },
    "reason": "recount",
    "version": 4
  }
}
```

---

## GET /inventory/alerts

Products at or below their thresholds. Replaces
`GET /api/getProductsByAlarmLimit` and `GET /api/getProductsByStockLimit`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `type` | enum | `alarm` | `alarm` (shelf running out) \| `restock` (back room running out) |
| `page`, `per_page` | int | `1`, `50` | |

**Response `200`**

```json
{
  "success": true,
  "data": [
    {
      "id": 104,
      "product_name": "Dates Premium 1kg",
      "bar_code": "DTS-001",
      "quantities": { "in_shop": 2, "in_stock": 4 },
      "limits": { "stock_limit": 10, "alarm_limit": 4 },
      "shortfall": 2,
      "severity": "critical",
      "image": { "thumb_url": "https://cdn.exelo.co/p/104/dates_thumb.png" }
    }
  ],
  "meta": {
    "pagination": { "page": 1, "per_page": 50, "total": 6, "total_pages": 1, "has_more": false },
    "summary": { "alarm_count": 6, "restock_count": 11 }
  }
}
```

`severity` is `critical` at zero, `warning` at or below the threshold.
`shortfall` is how many to move or buy to clear the alert.
