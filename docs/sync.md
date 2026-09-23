# Sync & Offline

New in v1. These endpoints turn the device from an app that caches a few
responses into a proper sync client.

Shops in Somaliland sell through outages. Today the app handles that with
ad-hoc fallbacks: a SQLite mirror that never prunes, two queues drained from
screen-open handlers, and a replay loop with no idempotency. This module gives
that behaviour a contract.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/health`](#get-health) | — |
| GET | [`/sync/manifest`](#get-syncmanifest) | Bearer |
| GET | [`/sync/changes`](#get-syncchanges) | Bearer |
| POST | [`/sync/mutations`](#post-syncmutations) | Bearer |

---

## GET /health

A cheap liveness probe.

The client currently decides whether it is online by fetching the **site root**
and treating any HTTP answer as success — pulling a full HTML page on exactly
the connections that can least afford it.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "status": "ok",
    "server_time": "2026-09-18T14:58:00Z",
    "min_app_version": "1.2.0",
    "message": null
  }
}
```

| Field | Notes |
| --- | --- |
| `status` | `ok` \| `degraded` \| `maintenance` |
| `server_time` | Lets the client detect a wrong device clock |
| `min_app_version` | Below this, the client should force an upgrade |
| `message` | Optional banner, e.g. "Zaad payments are slow right now" |

Roughly 200 bytes, no auth, no database work. Safe to call on a timer.

`status: "degraded"` is worth honouring — it lets the server tell a shop "we are
up but payments are unreliable" instead of the app guessing from timeouts.

---

## GET /sync/manifest

What has changed server-side, per collection, without downloading any of it.
The client compares against its local cursors and only pulls what moved.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "server_time": "2026-09-18T14:58:00Z",
    "collections": {
      "products":   { "cursor": "2026-09-18T14:03:11Z", "count": 318, "changed_since_epoch": 12 },
      "categories": { "cursor": "2026-08-01T00:00:00Z", "count": 5,   "changed_since_epoch": 0 },
      "employees":  { "cursor": "2026-09-11T08:00:00Z", "count": 4,   "changed_since_epoch": 1 },
      "orders":     { "cursor": "2026-09-18T14:22:10Z", "count": 125, "changed_since_epoch": 3 }
    },
    "full_resync_required": false,
    "full_resync_reason": null
  }
}
```

`full_resync_required` is the escape hatch: after a schema change, a merchant
transfer or a cursor older than the retention window, the server tells the
client to drop its mirror and start clean rather than applying a delta to a
state the server can no longer reason about.

---

## GET /sync/changes

The delta pull. One call covering several collections, so a device coming back
online does not make six round trips.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `since` | timestamp | required | Cursor from the last successful sync |
| `collections` | csv | all | e.g. `products,categories` |
| `limit` | int | `200` | Max per collection per page |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "since": "2026-09-17T00:00:00Z",
    "cursor": "2026-09-18T14:58:00Z",
    "has_more": false,
    "changes": {
      "products": {
        "upserted": [
          {
            "id": 101, "version": 4, "product_name": "Basmati Rice 5kg",
            "bar_code": "RCE-005",
            "price": { "amount": 1900, "currency": "USD", "display": "$19.00" },
            "quantities": { "in_shop": 19, "in_stock": 91, "in_transportation": 0 },
            "category": { "id": 1, "name": "Dry goods" },
            "updated_at": "2026-09-18T14:33:20Z"
          }
        ],
        "deleted": [
          { "id": 907, "deleted_at": "2026-09-16T10:04:00Z" }
        ]
      },
      "categories": { "upserted": [], "deleted": [] }
    }
  }
}
```

Apply `upserted` then `deleted`, then store `cursor`. Only advance the cursor
after the whole batch is committed locally — a crash mid-apply must re-pull, not
skip.

### Tombstones

`deleted` is the part the current client has no equivalent for. Its local
`products` table only ever gains rows, so a product deleted on one till stays
scannable and sellable on another indefinitely.

### Paging

When `has_more` is `true`, call again with `since` set to the returned `cursor`.
Keep going until it is `false`.

---

## POST /sync/mutations

Replays everything the device queued while offline, in one request, with a
per-entry result.

This replaces three separate ad-hoc mechanisms:

| Local queue | Legacy behaviour | Problem |
| --- | --- | --- |
| `pending_creates` | `syncPendingCreates()` exists but is **never called** | Offline-created products are stranded on the device permanently |
| `pending_updates` | Replayed on Modify Products screen open | No idempotency key; re-runs on every open |
| `held_register_ticket` | Looped `cart/add`, one line at a time | A timeout after a server-side success duplicates the line |

**Request**

```json
{
  "mutations": [
    {
      "client_mutation_id": "mut_local_31",
      "type": "product.create",
      "idempotency_key": "6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60",
      "occurred_at": "2026-09-18T09:40:00Z",
      "payload": {
        "client_uuid": "6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60",
        "product_name": "Sesame Oil 1L",
        "price": { "amount": 620, "currency": "USD" },
        "quantity": 12,
        "type": "shop",
        "category_id": 1
      }
    },
    {
      "client_mutation_id": "mut_local_32",
      "type": "product.update",
      "idempotency_key": "b8e0d3f5-2c17-4980-9b3a-50e1d7c93a28",
      "occurred_at": "2026-09-18T10:12:00Z",
      "payload": { "product_id": 101, "price": { "amount": 1900, "currency": "USD" }, "base_version": 3 }
    },
    {
      "client_mutation_id": "mut_local_33",
      "type": "inventory.transfer",
      "idempotency_key": "3a7f1e28-99b4-4d51-8e70-1a2c6f4b8d05",
      "occurred_at": "2026-09-18T11:05:00Z",
      "payload": { "product_id": 104, "quantity": 3, "from": "stock", "to": "shop" }
    }
  ]
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `client_mutation_id` | string | yes | Local row id; echoed back so the device knows what to delete |
| `type` | enum | yes | See the supported list below |
| `idempotency_key` | string | yes | Generated at user-intent time, persisted with the row |
| `occurred_at` | timestamp | yes | When the shopkeeper actually did it |
| `payload` | object | yes | Same body as the equivalent direct endpoint |

**Supported types**

| Type | Equivalent endpoint |
| --- | --- |
| `product.create` | [`POST /products`](inventory.md#post-products) |
| `product.update` | [`PATCH /products/{id}`](inventory.md#patch-productsid) |
| `product.delete` | [`DELETE /products/{id}`](inventory.md#delete-productsid) |
| `category.create` | [`POST /categories`](inventory.md#post-categories) |
| `inventory.transfer` | [`POST /inventory/transfers`](inventory.md#post-inventorytransfers) |
| `inventory.adjust` | [`PATCH /inventory/{id}/quantities`](inventory.md#patch-inventoryproduct_idquantities) |
| `shift.start` / `shift.end` | [Shifts](employees.md#post-shiftsstart) |
| `order.create` | [`POST /orders`](orders.md#3-post-apiv1orders--save-an-order-composed-offline) |

Held cart lines are **not** replayed here — they go through
[`POST /cart/sync`](cart.md#post-cartsync), which reconciles the whole ticket
against live stock and returns authoritative totals.

Mutations are applied **in array order**, so a `product.create` followed by an
`inventory.transfer` referencing it works. `occurred_at` is preserved so
offline activity lands on the right day in reports.

**Response `207`** — multi-status, because entries can individually fail.

```json
{
  "success": true,
  "message": "2 applied, 1 conflict",
  "data": {
    "results": [
      {
        "client_mutation_id": "mut_local_31",
        "status": "applied",
        "resource": { "type": "product", "id": 813, "client_uuid": "6f2a9c14-8b33-4e71-9d05-1c7e4a8b2f60", "version": 1 }
      },
      {
        "client_mutation_id": "mut_local_32",
        "status": "conflict",
        "reason": { "code": "resource.version_conflict", "message": "This product was changed on another device" },
        "current": { "id": 101, "version": 5, "price": { "amount": 1950, "currency": "USD", "display": "$19.50" } }
      },
      {
        "client_mutation_id": "mut_local_33",
        "status": "applied",
        "resource": { "type": "transfer", "id": 7742 }
      }
    ],
    "applied": 2,
    "failed": 1,
    "cursor": "2026-09-18T14:58:00Z"
  }
}
```

### Result handling

| `status` | Meaning | Client action |
| --- | --- | --- |
| `applied` | Written | Delete the local row. For creates, first rewrite the local id to `resource.id`. |
| `duplicate` | Already applied under this key | Delete the local row — this is the safe retry outcome |
| `conflict` | Server state moved | Keep the row, show the user `current`, let them choose |
| `rejected` | Permanently invalid (deleted product, bad data) | Delete the row and tell the user why |
| `failed` | Transient server fault | Keep the row and retry later |

`duplicate` is the outcome that makes retrying safe. A device that sent a batch,
lost the response and sent it again gets `duplicate` for everything already
written instead of a second set of products and a double-counted transfer.

### When to call

| Trigger | Notes |
| --- | --- |
| Connectivity restored | The main one. The current app has **no reconnect listener at all**. |
| App foregrounded | If the queue is non-empty |
| Before logout | Logout must be blocked while the queue has rows — that data exists nowhere else |
| Manual "Sync now" | Give the shopkeeper a way to force it and see the result |

Not from screen-open handlers. That is how the current client ends up replaying
the same queue three times during one sale.

---

## Client sync model

The device holds three kinds of local data, and they need different treatment.

| Kind | Examples | On wipe | Rule |
| --- | --- | --- | --- |
| **Cache** | Product mirror, categories, dashboard snapshot, report caches | Rebuildable | Safe to drop any time. Refresh via `/sync/changes`. |
| **Session** | Token, profile, plan | Re-login | Cleared on logout |
| **Queued business data** | `pending_creates`, `pending_updates`, held ticket | **Lost forever** | Must drain before logout or uninstall. Never silently discarded. |

The third row is the one that matters. It is the only data in the system with
no server copy, and today one of its queues is never drained at all.
