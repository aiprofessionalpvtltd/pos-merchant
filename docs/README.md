# EXELO Unified API — v1

Reference documentation for the single backend that replaces today's split
between the Laravel API and Firebase.

**Status: specification.** Not yet implemented. The consolidation rationale,
migration steps and rollout phases live in [`../unified-api-plan.md`](../unified-api-plan.md).

---

## Modules

| Module | Covers |
| --- | --- |
| [Auth & session](auth.md) | Phone lookup, PIN login, PIN reset, session, logout |
| [Registration](registration.md) | Signup fee, merchant creation, wallet verification |
| [Subscription](subscription.md) | Plans, current subscription, upgrade/downgrade |
| [Payments](payments.md) | Quotes, charges on every rail, payouts, webhooks |
| [Cart & checkout](cart.md) | The register: lines, totals, offline reconcile, pay |
| [Orders](orders.md) | Pending and complete orders, receipts |
| [Inventory](inventory.md) | Products, barcode lookup, categories, transfers, alerts |
| [Files](files.md) | Product images, customer signatures |
| [Employees](employees.md) | Staff, permissions, shifts |
| [Dashboard & reports](dashboard.md) | KPIs, revenue series, sales/inventory/product reports |
| [NFC & device keys](nfc.md) | Tag registration, encryption keys (replaces Firestore) |
| [Sync](sync.md) | Delta pull, offline mutation replay, health |
| [Merchant profile](merchant.md) | Business details, wallet numbers |
| [Errors](errors.md) | Full error code catalogue |
| [Legacy mapping](legacy-mapping.md) | Every old route → its replacement |

---

## Base URL

```
https://<host>/api/v1
```

One base URL for everything. The client stores it once; the server returns
absolute URLs for any asset, so the app never assembles a media path from a
constant.

---

## Request headers

| Header | Required | Notes |
| --- | --- | --- |
| `Authorization` | On authenticated routes | `Bearer <token>` |
| `Content-Type` | On write requests | `application/json` |
| `Accept` | Recommended | `application/json` |
| `X-EXELO-Device-Id` | Yes | Stable UUID per install. Scopes idempotency and attributes shifts. |
| `X-EXELO-App-Version` | Yes | e.g. `gold/1.4.2+112`. Lets the server warn or force upgrade. |
| `Idempotency-Key` | On retryable writes | See [Idempotency](#idempotency) |
| `If-Match` | Optional on updates | Resource `version` for optimistic concurrency |

---

## Response envelope

Every response, success or failure, uses the same outer shape.

**Success**

```json
{
  "success": true,
  "message": "Product saved",
  "data": { },
  "meta": {
    "request_id": "req_01JBXQ7K2M9F",
    "server_time": "2026-09-18T14:03:11Z"
  }
}
```

**Failure**

```json
{
  "success": false,
  "message": "That barcode already belongs to another product",
  "error": {
    "code": "product.barcode_taken",
    "field": "bar_code",
    "details": { "existing_product_id": 4812 }
  },
  "meta": {
    "request_id": "req_01JBXQ7K2M9F",
    "server_time": "2026-09-18T14:03:11Z"
  }
}
```

`message` is safe to show directly to a shopkeeper. `error.code` is the stable
string to branch on — never parse `message`.

---

## Status codes

| Code | Meaning | Client should |
| --- | --- | --- |
| `200` | OK | Proceed |
| `201` | Created | Store the returned `id` |
| `202` | Accepted, result pending | Poll the resource |
| `400` | Malformed request | Bug — log it |
| `401` | Token missing, expired or revoked | Clear session, send to login |
| `403` | Authenticated but not permitted | Hide the action |
| `404` | Not found for this merchant | Refresh the list |
| `409` | Conflict — stale version or duplicate | Show the server copy, ask the user |
| `422` | Validation failed | Show per-field errors from `error.details` |
| `429` | Rate limited | Back off, honour `Retry-After` |
| `500`–`503` | Server fault | Fall back to local cache, retry later |

A `401` is the only response that clears the local session. Any other failure
leaves the shop working from cache.

---

## Authentication

Bearer tokens issued by [`POST /auth/pin/login`](auth.md#post-authpinlogin).

Tokens are long-lived and device-scoped, because a shop on a weak line must not
be logged out mid-sale. Revocation is server-side: logout, staff removal or a
merchant-initiated "sign out all devices" invalidates immediately.

The token carries the merchant, the acting user, the plan and the permission
list, so the app does not re-derive permissions from a cached profile blob.

### Permissions

Every permission has a stable `key` plus a display `name`. Authorise on `key`;
show `name`.

| Key | Display name | Gates |
| --- | --- | --- |
| `pos` | POS | Register, scanning, taking payment |
| `inventory` | Inventory | Products, stock, transfers |
| `transactions` | Transactions | Orders, transaction history, receipts |
| `employees` | Employee Management | Staff and shifts |
| `reports` | Reports | Dashboard detail and reports |

A merchant account implicitly holds all five. Employees hold a subset.

---

## Idempotency

Any write the offline layer can replay **must** carry an idempotency key.

```
Idempotency-Key: 9c1f2a4e-6b20-4a11-9f02-7d3b8c5e1a44
```

Rules:

1. The client generates the key **once, at the moment of user intent** — when
   the shopkeeper taps Add or Pay — and persists it beside the queued row.
2. Every retry of that same intent reuses the same key. It is never regenerated
   per attempt.
3. The server stores the response against `(merchant_id, device_id, key)` for
   **24 hours**. A repeat returns the stored response verbatim with
   `meta.idempotent_replay: true`, and performs no second write.
4. Reusing a key with a *different* body returns `409` /
   `idempotency.key_reused`.

**Required on:** `POST /cart/items`, `POST /cart/sync`, `POST /cart/pay`,
`POST /orders`, `POST /orders/{id}/pay`, `PATCH /orders/{id}/status`,
`POST /products`, `PATCH /products/{id}`, `POST /inventory/transfers`,
`POST /payments/charges`, `POST /payments/payouts`, `POST /sync/mutations`.

This is what prevents a timeout after a successful charge from billing the
shopper twice.

---

## Optimistic concurrency

Mutable resources return an integer `version` that increments on every write.
Send it back as `If-Match` to make an update conditional.

On mismatch the server returns `409` with the current copy, so the app can show
"this changed on another till" instead of silently overwriting.

```json
{
  "success": false,
  "message": "This product was changed on another device",
  "error": {
    "code": "resource.version_conflict",
    "details": { "current": { "id": 812, "version": 9, "price": { "amount": 165, "currency": "USD" } } }
  }
}
```

---

## Pagination

List endpoints accept:

| Param | Default | Notes |
| --- | --- | --- |
| `page` | `1` | 1-based |
| `per_page` | `50` | Max `200` |
| `q` | — | Free-text search |
| `updated_since` | — | ISO 8601. Returns only rows changed since, **including tombstones**. |
| `sort` | Per endpoint | e.g. `-created_at` for descending |

And respond with:

```json
{
  "data": [ ],
  "meta": {
    "pagination": {
      "page": 1,
      "per_page": 50,
      "total": 318,
      "total_pages": 7,
      "has_more": true
    }
  }
}
```

`updated_since` is what makes delta sync possible on a slow link — see
[Sync](sync.md).

---

## Shared objects

### Money

Never a float, never a parsed string. An integer in the currency's smallest
unit, plus the currency.

```json
{ "amount": 1850, "currency": "USD", "display": "$18.50" }
```

| Currency | Exponent | `amount: 1850` means |
| --- | --- | --- |
| `USD` | 2 | $18.50 |
| `SLSH` | 0 | 1,850 SLSH |

`display` is server-formatted and safe to render directly. Clients must not do
arithmetic on `display`.

Where both currencies are relevant (the register shows each), the object is
paired:

```json
{
  "total":     { "amount": 1850, "currency": "USD", "display": "$18.50" },
  "total_alt": { "amount": 148000, "currency": "SLSH", "display": "148,000 SLSH" },
  "exchange_rate": 8000
}
```

### Product

```json
{
  "id": 101,
  "client_uuid": null,
  "version": 3,
  "product_name": "Basmati Rice 5kg",
  "bar_code": "RCE-005",
  "price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
  "price_alt": { "amount": 148000, "currency": "SLSH", "display": "148,000 SLSH" },
  "vat_rate": 0.05,
  "category": { "id": 1, "name": "Dry goods" },
  "quantities": {
    "in_shop": 24,
    "in_stock": 86,
    "in_transportation": 0
  },
  "limits": { "stock_limit": 10, "alarm_limit": 4 },
  "image": {
    "id": "file_01JB...",
    "url": "https://cdn.exelo.co/p/101/rice.png",
    "thumb_url": "https://cdn.exelo.co/p/101/rice_thumb.png"
  },
  "created_at": "2026-04-02T09:00:00Z",
  "updated_at": "2026-09-14T11:22:03Z",
  "deleted_at": null
}
```

### Cart line

```json
{
  "product_id": 101,
  "product_name": "Basmati Rice 5kg",
  "quantity": 2,
  "unit_price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
  "line_total": { "amount": 3700, "currency": "USD", "display": "$37.00" },
  "line_total_alt": { "amount": 296000, "currency": "SLSH", "display": "296,000 SLSH" }
}
```

### Totals

```json
{
  "subtotal": { "amount": 3700, "currency": "USD", "display": "$37.00" },
  "vat":      { "amount": 185,  "currency": "USD", "display": "$1.85" },
  "fee":      { "amount": 74,   "currency": "USD", "display": "$0.74" },
  "total":    { "amount": 3774, "currency": "USD", "display": "$37.74" },
  "total_alt":{ "amount": 301920, "currency": "SLSH", "display": "301,920 SLSH" },
  "vat_rate": 0.05,
  "exchange_rate": 8000
}
```

`fee` is the EXELO platform charge, called `exelo_amount` in the legacy API.

### Timestamps

ISO 8601, UTC, always suffixed `Z`: `2026-09-18T14:03:11Z`. The server also
returns `*_display` strings pre-formatted in the merchant's timezone where a
screen shows a date, so the client does no locale parsing.

---

## Conventions summary

- Resources are plural nouns; actions are sub-resources (`/orders/{id}/pay`),
  never verbs in the path (`/updateOrderStatusToComplete`).
- `PATCH` for partial updates, `PUT` never used.
- Filters are query parameters, not path segments. `?type=shop`, not
  `/products/shop`.
- Booleans are real JSON booleans. The legacy API mixes `true`, `"1"` and
  `"Verified"` — v1 does not.
- Empty list responses return `[]`, never `null`.
- All IDs are integers for existing entities and prefixed strings for new
  resources introduced in v1 (`chg_`, `file_`, `inv_`).
