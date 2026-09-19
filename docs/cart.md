# Cart & Checkout (the register)

The active sale. One cart per device per merchant, so two tills in the same shop
do not fight over one ticket — the legacy API keyed the cart to the merchant
alone.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/cart`](#get-cart) | Bearer · `pos` |
| POST | [`/cart/items`](#post-cartitems) | Bearer · `pos` |
| PATCH | [`/cart/items/{product_id}`](#patch-cartitemsproduct_id) | Bearer · `pos` |
| DELETE | [`/cart/items/{product_id}`](#delete-cartitemsproduct_id) | Bearer · `pos` |
| DELETE | [`/cart`](#delete-cart) | Bearer · `pos` |
| POST | [`/cart/sync`](#post-cartsync) | Bearer · `pos` |
| POST | [`/cart/pay`](#post-cartpay) | Bearer · `pos` |
| POST | [`/cart/hold`](#post-carthold) | Bearer · `pos` |

---

## GET /cart

The current ticket with totals. Replaces both
`GET /api/cart/cart-valid` and `GET /api/cart/checkout?cart_type=shop` — the
legacy client called the first to learn whether a cart existed, then the second
to get it.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `type` | enum | `shop` | `shop` \| `stock` |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "cart_id": 4471,
    "type": "shop",
    "version": 7,
    "is_empty": false,
    "items": [
      {
        "product_id": 101,
        "product_name": "Basmati Rice 5kg",
        "bar_code": "RCE-005",
        "quantity": 2,
        "unit_price":     { "amount": 1850, "currency": "USD", "display": "$18.50" },
        "unit_price_alt": { "amount": 148000, "currency": "SLSH", "display": "148,000 SLSH" },
        "line_total":     { "amount": 3700, "currency": "USD", "display": "$37.00" },
        "line_total_alt": { "amount": 296000, "currency": "SLSH", "display": "296,000 SLSH" },
        "available_quantity": 24,
        "held": false
      }
    ],
    "totals": {
      "subtotal":  { "amount": 3700, "currency": "USD", "display": "$37.00" },
      "vat":       { "amount": 185,  "currency": "USD", "display": "$1.85" },
      "fee":       { "amount": 74,   "currency": "USD", "display": "$0.74" },
      "total":     { "amount": 3774, "currency": "USD", "display": "$37.74" },
      "total_alt": { "amount": 301920, "currency": "SLSH", "display": "301,920 SLSH" },
      "vat_rate": 0.05,
      "exchange_rate": 8000
    },
    "item_count": 1,
    "unit_count": 2,
    "updated_at": "2026-09-18T14:12:40Z"
  }
}
```

The legacy response returned `subtotal`, `vat`, `exelo_amount`, `total`,
`total_in_sls` and `subtotal_in_sls` as loose sibling strings that the client
parsed with `double.tryParse`. `fee` here is the same value as `exelo_amount`.

**Empty cart** returns `200` with `is_empty: true` and `items: []` — not a
`404`.

---

## POST /cart/items

Adds a line, or increases the quantity of one already on the ticket.
**Idempotent — key required.** Replaces `POST /api/cart/add`.

**Request**

```json
{
  "product_id": 101,
  "quantity": 1,
  "type": "shop",
  "idempotency_key": "e3c9a7b2-1f84-4d60-9a15-c7b208e4f931"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `product_id` | int | yes | |
| `quantity` | int | no | Default `1`. Must be positive. |
| `type` | enum | no | Default `shop` |
| `unit_price` | Money | no | Overrides the catalogue price for this line |
| `idempotency_key` | string | yes | Generated when the shopkeeper scans or taps |

**Response `200`** — the full cart, exactly as
[`GET /cart`](#get-cart). Returning the whole ticket means the register never
needs a follow-up fetch to redraw totals, which saves a round trip per scan.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `product.not_found` | Deleted since the device last synced |
| `409` | `product.out_of_stock` | `error.details.available: 0` |
| `422` | `cart.quantity_invalid` | Zero or negative |

Out of stock is a `409` rather than a hard block: `error.details.available`
tells the till how many can still be sold, so the shopkeeper can decide.

### Why the idempotency key matters here

This is the endpoint the offline queue replays. In the legacy client, a held
line was re-queued whenever the response was not `200` — **including on
timeout**. If the server had accepted the line and the reply was lost, the
retry added it a second time and the shopper was overcharged. The key makes the
retry a no-op that returns the original cart.

---

## PATCH /cart/items/{product_id}

Changes quantity or price on an existing line. Replaces
`POST /api/cart/update-cart-items`.

**Request**

```json
{ "quantity": 3, "unit_price": { "amount": 1800, "currency": "USD" } }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `quantity` | int | no | Positive. Send `0` → use DELETE instead. |
| `unit_price` | Money | no | Manual price override (haggling, damaged goods) |

Send `If-Match: <cart version>` to make the edit conditional. Without it, last
write wins.

**Response `200`** — the full cart.

**Errors** — `404 cart.line_not_found`, `409 resource.version_conflict`.

---

## DELETE /cart/items/{product_id}

Removes a line. Replaces `DELETE /api/cart/delete-cart-items`, which sent its
parameters in a **body on a DELETE** — awkward for proxies and some HTTP
clients. v1 puts the id in the path.

**Response `200`** — the full cart.

---

## DELETE /cart

Clears the ticket. Used by the "cancel sale" action.

**Query**

| Param | Type | Default |
| --- | --- | --- |
| `type` | enum | `shop` |

**Response `200`**

```json
{
  "success": true,
  "message": "Sale cancelled",
  "data": { "cart_id": 4471, "type": "shop", "version": 8, "is_empty": true, "items": [], "totals": { } }
}
```

---

## POST /cart/sync

**New in v1.** Reconciles a whole offline ticket in one call.

This is the fix for the most damaging bug in the current offline layer. When
the link drops, the register holds lines in local storage. On reconnect the
legacy client looped over them posting `cart/add` one at a time, removing a line
only on an exact `200`, with no idempotency — so a partial failure or a timeout
duplicated lines, and a second flush could be triggered from three different
places (register open, add-to-cart, and again just before payment).

One call, one transaction, per-line results.

**Request**

```json
{
  "type": "shop",
  "client_ticket_id": "tkt_local_18",
  "idempotency_key": "9c1f2a4e-6b20-4a11-9f02-7d3b8c5e1a44",
  "strategy": "merge",
  "lines": [
    { "client_line_id": "ln_1", "product_id": 101, "quantity": 2 },
    { "client_line_id": "ln_2", "product_id": 907, "quantity": 1 },
    { "client_line_id": "ln_3", "product_id": 106, "quantity": 5,
      "unit_price": { "amount": 560, "currency": "USD" } }
  ]
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `type` | enum | no | Default `shop` |
| `client_ticket_id` | string | yes | Stable local id for this held ticket |
| `idempotency_key` | string | yes | One key for the whole reconcile |
| `strategy` | enum | no | `merge` (default) adds to the server cart; `replace` discards it first |
| `lines[].client_line_id` | string | yes | Lets the client match results back to its rows |
| `lines[].product_id` | int | yes | |
| `lines[].quantity` | int | yes | |
| `lines[].unit_price` | Money | no | Preserves a price override made offline |

**Response `200`**

```json
{
  "success": true,
  "message": "3 held lines applied, 1 needs attention",
  "data": {
    "results": [
      { "client_line_id": "ln_1", "status": "applied",  "product_id": 101, "quantity": 2 },
      { "client_line_id": "ln_2", "status": "rejected", "product_id": 907,
        "reason": { "code": "product.not_found", "message": "This product was deleted" } },
      { "client_line_id": "ln_3", "status": "adjusted", "product_id": 106, "quantity": 3,
        "reason": { "code": "product.partial_stock", "message": "Only 3 left in the shop" } }
    ],
    "cart": { "cart_id": 4471, "type": "shop", "version": 12, "items": [ ], "totals": { } }
  }
}
```

| `status` | Client action |
| --- | --- |
| `applied` | Delete the local held line |
| `duplicate` | Already applied under this key — delete the local line |
| `adjusted` | Applied at a different quantity — delete local, show what changed |
| `rejected` | Keep the line flagged and tell the shopkeeper why |

Because every outcome is reported per line, the device knows exactly what to
delete. The legacy flush could only infer it from an HTTP status for the whole
loop.

**Recommended call sites:** once when the register opens, and once when
connectivity returns. Not before every payment, and not after every scan.

---

## POST /cart/pay

Completes the sale. **Idempotent — key required.** Replaces
`POST /api/cart/transactionByCash` and `POST /api/cart/placeOrder`.

A single call takes payment, creates the order and clears the cart atomically.
The legacy flow did these as separate requests, so a dropped connection between
them could leave a paid sale with no order.

**Request**

```json
{
  "type": "shop",
  "cart_version": 7,
  "rail": "cash",
  "amount_tendered": { "amount": 4000, "currency": "USD" },
  "customer": { "name": "Amina Yusuf", "mobile_number": "+252635550101" },
  "idempotency_key": "5a8f2d19-7c34-4b02-91ea-3f6d8b0c7e25"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `type` | enum | no | Default `shop` |
| `cart_version` | int | yes | Rejected with `409` if the cart moved |
| `rail` | enum | yes | `cash` \| `zaad` \| `edahab` \| `card` \| `nfc` |
| `amount_tendered` | Money | For `cash` | Used to compute change |
| `customer.wallet_number` | string | On wallet rails | |
| `payment_nonce` | string | For `card` | |
| `customer.name` | string | no | On the receipt |
| `customer.mobile_number` | string | no | |
| `idempotency_key` | string | yes | |

**Response `200` — cash, settled**

```json
{
  "success": true,
  "message": "Sale complete",
  "data": {
    "status": "paid",
    "charge_id": "chg_01JBXT6Q4S",
    "order": {
      "id": 10246,
      "order_status": "Complete",
      "total": { "amount": 3774, "currency": "USD", "display": "$37.74" }
    },
    "change_due": { "amount": 226, "currency": "USD", "display": "$2.26" },
    "receipt": { "invoice_no": "INV-10246", "url": "/api/v1/orders/10246/receipt" },
    "cart": { "cart_id": 4471, "is_empty": true, "version": 8, "items": [] }
  }
}
```

**Response `202` — wallet rail, awaiting approval**

```json
{
  "success": true,
  "message": "Ask the customer to approve the payment",
  "data": {
    "status": "pending",
    "charge_id": "chg_01JBXT5P3R",
    "poll": "/api/v1/payments/charges/chg_01JBXT5P3R",
    "poll_after": 3
  }
}
```

The cart is **not** cleared until the charge reaches `paid`. The client polls
the charge; on success the response carries the order and the cleared cart.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `409` | `cart.version_conflict` | Cart changed — re-read and re-confirm the total |
| `409` | `cart.has_held_lines` | Unsynced offline lines exist; call `/cart/sync` first |
| `422` | `cart.empty` | Nothing to pay for |
| `402` | `payment.declined` | |

`cart.has_held_lines` is a server-side guard mirroring the client's rule that a
sale cannot be taken while lines exist only on the device.

---

## POST /cart/hold

Parks the ticket as a pending order with customer details and a signature.
Replaces `POST /api/cart/placePendingOrder`.

**Request**

```json
{
  "type": "shop",
  "customer": {
    "name": "Amina Yusuf",
    "mobile_number": "+252635550101"
  },
  "signature_file_id": "file_01JBXV2K7M",
  "note": "Collecting Thursday",
  "idempotency_key": "c71a4e82-..."
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `customer.name` | string | yes | |
| `customer.mobile_number` | string | yes | |
| `signature_file_id` | string | no | From [`POST /files`](files.md#post-files) |
| `note` | string | no | |

### Signatures

The legacy endpoint accepted the signature inline as a base64 data URL in the
JSON body. On a Somaliland connection that makes the whole request large and
slow, and a failure loses the order along with the image.

In v1 the signature is uploaded separately to
[`POST /files`](files.md#post-files) — retryable on its own — and only the
resulting id is sent here. `signature_file_id` is optional so the order can be
saved even if the upload has not finished.

**Response `201`**

```json
{
  "success": true,
  "message": "Order held for Amina Yusuf",
  "data": {
    "order": {
      "id": 10247,
      "order_status": "Pending",
      "name": "Amina Yusuf",
      "mobile_number": "+252635550101",
      "total": { "amount": 3774, "currency": "USD", "display": "$37.74" },
      "created_at": "2026-09-18T14:22:10Z"
    },
    "cart": { "cart_id": 4471, "is_empty": true, "version": 9, "items": [] }
  }
}
```

The held order is settled later through
[`POST /orders/{id}/pay`](orders.md#post-ordersidpay).
