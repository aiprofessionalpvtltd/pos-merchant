# Cart & Checkout (the register)

The active sale: the ticket a shopkeeper builds by scanning, and the two ways to
close it, take payment now or park it as a pending order.

One ticket per till, so two tills in the same shop never fight over one ticket. The
legacy API keyed the cart to the merchant alone.

> **Status: implemented.** All eight endpoints are live and tested, and every
> response below is captured from the running API. Two things are deliberately not
> enforced or built yet: the `cart.has_held_lines` guard (the server cannot see lines
> that exist only on the device) and the `card` and `nfc` payment rails.

| Method | Path | Auth |
| --- | --- | --- |
| GET | `/cart` | Bearer · `pos` |
| POST | `/cart/items` | Bearer · `pos` |
| PATCH | `/cart/items/{product_id}` | Bearer · `pos` |
| DELETE | `/cart/items/{product_id}` | Bearer · `pos` |
| DELETE | `/cart` | Bearer · `pos` |
| POST | `/cart/sync` | Bearer · `pos` |
| POST | `/cart/pay` | Bearer · `pos` |
| POST | `/cart/hold` | Bearer · `pos` |

---

## Complete endpoint list

Full URL = `{BASE_URL}/api/v1` + path. Every cart endpoint needs the `pos` permission.

**Headers**

| Header | Sent on | Value |
| --- | --- | --- |
| `Accept` | Every request | `application/json` |
| `Content-Type` | Requests with a body | `application/json` |
| `Authorization` | Every request | `Bearer <token>` from [`POST /auth/pin/login`](auth.md#post-authpinlogin). The token carries the device, which is what separates one till's ticket from another's. |
| `If-Match` | `PATCH /cart/items/{product_id}` (optional) | The cart `version` you last read |

| # | Method | Full path | Purpose | Body / query | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | GET | `/api/v1/cart` | The current ticket with totals | `type` | `200` | `403`, `422` |
| 2 | POST | `/api/v1/cart/items` | Add a line, or add to one already there | `product_id`, `quantity`, `type`, `unit_price`, `idempotency_key` | `200` | `404 product.not_found`, `409 product.out_of_stock`, `409 idempotency.key_reused`, `422 cart.quantity_invalid` |
| 3 | PATCH | `/api/v1/cart/items/{product_id}` | Change quantity or price | `quantity`, `unit_price`, `type` | `200` | `404 cart.line_not_found`, `409 cart.version_conflict`, `409 product.out_of_stock`, `422 cart.quantity_invalid` |
| 4 | DELETE | `/api/v1/cart/items/{product_id}` | Remove a line | `type` | `200` | `404 cart.line_not_found` |
| 5 | DELETE | `/api/v1/cart` | Cancel the sale (clear the ticket) | `type` | `200` | — |
| 6 | POST | `/api/v1/cart/sync` | Reconcile a ticket held offline | `client_ticket_id`, `lines[]`, `strategy`, `idempotency_key` | `200` | `409 idempotency.key_reused`, `422` |
| 7 | POST | `/api/v1/cart/pay` | Complete the sale | `cart_version`, `rail`, `amount_tendered`, `customer`, `idempotency_key` | `200` paid / `202` waiting | `409`, `422`, `502` (see [endpoint 7](#7-post-apiv1cartpay--complete-the-sale)) |
| 8 | POST | `/api/v1/cart/hold` | Park the ticket as a pending order | `customer`, `note`, `idempotency_key` | `201` | `422 cart.empty`, `422 validation.failed` |

**Status codes shared by every endpoint**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.token_invalid` | Missing, revoked or expired token: clear the session |
| `403` | `auth.permission_denied` | The user lacks the `pos` permission (`error.details.required_permission`) |
| `422` | `validation.failed` | Per-field messages in `error.details` |
| `429` | `rate_limited` | Slow down; honour `Retry-After` |

**Response envelope.** Every response carries `success`, `message`, `data` (or
`error`) and `meta.request_id` / `meta.server_time`. Branch on `error.code`, never
on `message`. See [errors.md](errors.md).

---

## Concepts

### One ticket per till

A ticket belongs to one shop, one signed-in user, one device and one `type`:

| `type` | Sells from |
| --- | --- |
| `shop` (default) | The shelf (`in_shop` stock) |
| `stock` | The back room (`in_stock` stock) |

Two tills in the same shop never share a ticket, and the same user signed in on two
devices gets two. The device comes from the login (`X-EXELO-Device-Id`). Tickets made
by the legacy app have no device and are never touched by v1.

### Every write returns the whole ticket

`GET /cart`, add, change, remove, clear and sync all return the same **ticket
object** (described in [endpoint 1](#1-get-apiv1cart--get-the-ticket)), so the
register redraws its totals without a follow-up call, which saves a round trip per
scan. It carries a `version` that goes up by one on every change. Send it back as
`If-Match` to make an edit conditional, or as `cart_version` when paying.

### How the totals are worked out

| Field | How |
| --- | --- |
| `unit_price` | The catalogue price **before VAT**, unless the shopkeeper overrode it |
| `line_total` | `unit_price` × `quantity` |
| `subtotal` | Sum of the `line_total`s |
| `vat` | Each line's total × its product's VAT rate, rounded per line, summed |
| `fee` | Always `0` in the cart: the wallet fee depends on the rail, so it comes from [`POST /payments/quote`](payments.md#2-post-apiv1paymentsquote--price-a-payment-before-charging) |
| `total` | `subtotal + vat` |
| `total_alt` | `total` in SLSH at the shop's exchange rate |

Worked example from [endpoint 3](#3-patch-apiv1cartitemsproduct_id--change-a-line):
3 × $18.00 + 1 × $1.75 = **$55.75**; VAT is $2.70 + $0.09 = **$2.79** (each line
rounded to the cent); total **$58.54**, which is 468,320 SLSH at a rate of 8,000.

### The stock rule

A line can never exceed what is in stock at the ticket's location. Adding or raising
past it returns `409 product.out_of_stock` with `error.details.available` (how many
can still be sold) and `error.details.requested` (the line quantity that was asked
for, existing plus added). It is a `409` rather than a hard block so the shopkeeper
can decide, for example sell the 3 that are left.

### Idempotency

`POST /cart/items`, `/cart/sync`, `/cart/pay` and `/cart/hold` need an
`idempotency_key` (a UUID the app generates when the shopkeeper scans, taps or pays,
**not** on every retry). A retry with the same key and body never adds a second line
or takes a second payment; the same key with a different body returns
`409 idempotency.key_reused`. Keys are remembered for 24 hours.

This matters most for `POST /cart/items`, the call the offline queue replays. The
legacy client re-queued a held line whenever the response was not `200`, including on
a timeout, so a lost reply added the line twice and overcharged the shopper.

### Money

`{ "amount": 1850, "currency": "USD", "display": "$18.50" }` in **minor units**
(cents for USD, whole shillings for SLSH). The `_alt` fields show SLSH at the shop's
own exchange rate. To override a price, send `unit_price` as
`{ "amount": 1800, "currency": "USD" }`; an SLSH override is converted to USD at the
shop's rate.

---

## 1. GET `/api/v1/cart` — Get the ticket

**Purpose:** The current ticket with priced lines and totals. Replaces both
`GET /api/cart/cart-valid` and `GET /api/cart/checkout?cart_type=shop`: the legacy
client called the first to learn whether a cart existed, then the second to get it.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `type` | enum | `shop` | `shop` \| `stock` |

**Response `200`: an empty ticket**

```json
{
  "success": true,
  "data": {
    "cart_id": 182,
    "type": "shop",
    "version": 1,
    "is_empty": true,
    "items": [],
    "totals": {
      "subtotal":  { "amount": 0, "currency": "USD", "display": "$0.00" },
      "vat":       { "amount": 0, "currency": "USD", "display": "$0.00" },
      "fee":       { "amount": 0, "currency": "USD", "display": "$0.00" },
      "total":     { "amount": 0, "currency": "USD", "display": "$0.00" },
      "total_alt": { "amount": 0, "currency": "SLSH", "display": "0 SLSH" },
      "vat_rate": 0.05,
      "exchange_rate": 8000
    },
    "item_count": 0,
    "unit_count": 0,
    "updated_at": "2026-09-20T09:38:12Z"
  }
}
```

An empty ticket is `200` with `is_empty: true`, never a `404`. The ticket is created
on first use.

**The ticket object** (a ticket with two lines)

```json
{
  "cart_id": 182,
  "type": "shop",
  "version": 4,
  "is_empty": false,
  "items": [
    {
      "product_id": 685,
      "product_name": "Basmati Rice 5kg",
      "bar_code": "RCE-005",
      "quantity": 3,
      "unit_price":     { "amount": 1800, "currency": "USD", "display": "$18.00" },
      "unit_price_alt": { "amount": 144000, "currency": "SLSH", "display": "144,000 SLSH" },
      "line_total":     { "amount": 5400, "currency": "USD", "display": "$54.00" },
      "line_total_alt": { "amount": 432000, "currency": "SLSH", "display": "432,000 SLSH" },
      "available_quantity": 24,
      "held": false
    },
    {
      "product_id": 686,
      "product_name": "Black Tea 100 bags",
      "bar_code": "TEA-1",
      "quantity": 1,
      "unit_price":     { "amount": 175, "currency": "USD", "display": "$1.75" },
      "unit_price_alt": { "amount": 14000, "currency": "SLSH", "display": "14,000 SLSH" },
      "line_total":     { "amount": 175, "currency": "USD", "display": "$1.75" },
      "line_total_alt": { "amount": 14000, "currency": "SLSH", "display": "14,000 SLSH" },
      "available_quantity": 3,
      "held": false
    }
  ],
  "totals": {
    "subtotal":  { "amount": 5575, "currency": "USD", "display": "$55.75" },
    "vat":       { "amount": 279, "currency": "USD", "display": "$2.79" },
    "fee":       { "amount": 0, "currency": "USD", "display": "$0.00" },
    "total":     { "amount": 5854, "currency": "USD", "display": "$58.54" },
    "total_alt": { "amount": 468320, "currency": "SLSH", "display": "468,320 SLSH" },
    "vat_rate": 0.05,
    "exchange_rate": 8000
  },
  "item_count": 2,
  "unit_count": 4,
  "updated_at": "2026-09-20T09:38:12Z"
}
```

| Field | Meaning |
| --- | --- |
| `cart_id` | Send as `cart_id` to [`POST /payments/charges`](payments.md#3-post-apiv1paymentscharges--start-a-payment) |
| `version` | Goes up by one on every change |
| `items[].available_quantity` | Stock left at the ticket's location, for the "only N left" hint |
| `items[].held` | Always `false`: held lines exist only on the device until synced |
| `totals.vat_rate` | The shop's default rate; each line uses its own product's rate |
| `item_count`, `unit_count` | Number of lines, and total units across them |

The legacy response returned `subtotal`, `vat`, `exelo_amount`, `total`,
`total_in_sls` and `subtotal_in_sls` as loose sibling strings that the client parsed
with `double.tryParse`.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.permission_denied` | No `pos` permission |
| `422` | `validation.failed` | An unknown `type` |

## 2. POST `/api/v1/cart/items` — Add an item

**Purpose:** Adds a line, or increases the quantity of one already on the ticket.
**Idempotent: `idempotency_key` is required.** Replaces `POST /api/cart/add`.

**Request**

```json
{
  "product_id": 686,
  "quantity": 1,
  "type": "shop",
  "unit_price": { "amount": 175, "currency": "USD" },
  "idempotency_key": "e3c9a7b2-1f84-4d60-9a15-c7b208e4f931"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `product_id` | int | yes | A product of this shop |
| `quantity` | int | no | Default `1`. Must be at least `1`. |
| `type` | enum | no | `shop` (default) \| `stock` |
| `unit_price` | Money | no | Overrides the catalogue price for this line (haggling, damaged goods). On a line already on the ticket it replaces the price. |
| `idempotency_key` | string | yes | Generated when the shopkeeper scans or taps |

**Response `200`:** the whole ticket, as in [endpoint 1](#1-get-apiv1cart--get-the-ticket),
with the line added and `version` one higher. Adding a product that is already on the
ticket raises that line's `quantity`; there is one line per product.

**Retrying with the same key** returns the same ticket and changes nothing (the
`version` does not move).

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `product.not_found` | Not a product of this shop, or deleted since the device last synced |
| `409` | `product.out_of_stock` | Not enough in stock (`error.details.available`, `error.details.requested`) |
| `409` | `idempotency.key_reused` | Same key, different request |
| `422` | `cart.quantity_invalid` | Zero or negative (`error.field: quantity`) |
| `422` | `validation.failed` | `product_id` or `idempotency_key` missing |

```json
{
  "success": false,
  "message": "Only 3 left",
  "error": {
    "code": "product.out_of_stock",
    "details": { "available": 3, "requested": 6 }
  }
}
```

The example is adding 5 tea to a line that already has 1, with 3 in stock.

```json
{
  "success": false,
  "message": "Quantity must be at least 1",
  "error": { "code": "cart.quantity_invalid", "field": "quantity" }
}
```

```json
{
  "success": false,
  "message": "Please check the form",
  "error": {
    "code": "validation.failed",
    "details": {
      "product_id": ["The product id field is required."],
      "idempotency_key": ["The idempotency key field is required."]
    }
  }
}
```

## 3. PATCH `/api/v1/cart/items/{product_id}` — Change a line

**Purpose:** Changes the quantity or the price of a line already on the ticket.
Replaces `POST /api/cart/update-cart-items`.

**Headers:** `If-Match: 3` (optional): the cart `version` you last read. Without it,
the last write wins.

**Request:** any subset

```json
{ "quantity": 3, "unit_price": { "amount": 1800, "currency": "USD" } }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `quantity` | int | no | At least `1`. To remove a line use [DELETE](#4-delete-apiv1cartitemsproduct_id--remove-a-line). |
| `unit_price` | Money | no | Manual price override |
| `type` | enum | no | `shop` (default) \| `stock` |

**Response `200`:** the whole ticket. The ticket object in
[endpoint 1](#1-get-apiv1cart--get-the-ticket) is exactly this response: rice raised
to 3 at an overridden $18.00, tea unchanged, `version` 4.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `cart.line_not_found` | That product is not on the ticket |
| `409` | `cart.version_conflict` | `If-Match` is stale; the current ticket is in `error.details.current` |
| `409` | `product.out_of_stock` | Raising the quantity past what is in stock |
| `422` | `cart.quantity_invalid` | `0` or a negative number |

```json
{
  "success": false,
  "message": "This ticket was changed on another screen",
  "error": {
    "code": "cart.version_conflict",
    "details": { "current": { "cart_id": 182, "version": 4, "...": "the whole ticket, as in GET /cart" } }
  }
}
```

```json
{
  "success": false,
  "message": "Quantity must be at least 1. Remove the item to clear it.",
  "error": { "code": "cart.quantity_invalid", "field": "quantity" }
}
```

## 4. DELETE `/api/v1/cart/items/{product_id}` — Remove a line

**Purpose:** Removes a line. Replaces `DELETE /api/cart/delete-cart-items`, which sent
its parameters in a **body on a DELETE**, awkward for proxies and some HTTP clients.
v1 puts the id in the path.

**Query:** `type` (`shop` default \| `stock`).

**Response `200`:** the whole ticket without that line (`version` one higher).

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `cart.line_not_found` | Already removed, or never on the ticket |

```json
{
  "success": false,
  "message": "That item is no longer on the ticket",
  "error": { "code": "cart.line_not_found" }
}
```

## 5. DELETE `/api/v1/cart` — Cancel the sale

**Purpose:** Clears the ticket. Used by the "cancel sale" action. Clearing an already
empty ticket is fine and returns `200` again.

**Query:** `type` (`shop` default \| `stock`).

**Response `200`**

```json
{
  "success": true,
  "message": "Sale cancelled",
  "data": {
    "cart_id": 182,
    "type": "shop",
    "version": 11,
    "is_empty": true,
    "items": [],
    "totals": { "total": { "amount": 0, "currency": "USD", "display": "$0.00" }, "...": "all totals at zero" },
    "item_count": 0,
    "unit_count": 0,
    "updated_at": "2026-09-20T09:38:12Z"
  }
}
```

## 6. POST `/api/v1/cart/sync` — Reconcile an offline ticket

**Purpose:** **New in v1.** Reconciles a whole ticket held offline in one call, with
one outcome per line.

This fixes the most damaging bug in the current offline layer. When the link drops,
the register holds lines in local storage. On reconnect the legacy client looped over
them posting `cart/add` one at a time, removing a line only on an exact `200`, with no
idempotency, so a partial failure or a timeout duplicated lines, and a second flush
could be triggered from three different places.

One call, one transaction, per-line results.

**Request**

```json
{
  "type": "shop",
  "client_ticket_id": "tkt_local_18",
  "idempotency_key": "9c1f2a4e-6b20-4a11-9f02-7d3b8c5e1a44",
  "strategy": "merge",
  "lines": [
    { "client_line_id": "ln_1", "product_id": 685, "quantity": 2 },
    { "client_line_id": "ln_2", "product_id": 999999, "quantity": 1 },
    { "client_line_id": "ln_3", "product_id": 686, "quantity": 5,
      "unit_price": { "amount": 175, "currency": "USD" } }
  ]
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `type` | enum | no | `shop` (default) \| `stock` |
| `client_ticket_id` | string | yes | Stable local id for this held ticket |
| `idempotency_key` | string | yes | One key for the whole reconcile |
| `strategy` | enum | no | `merge` (default) adds to the server ticket; `replace` clears it first |
| `lines` | array | yes | 1 to 200 lines |
| `lines[].client_line_id` | string | yes | Lets the client match results back to its rows; unique in the request |
| `lines[].product_id` | int | yes | |
| `lines[].quantity` | int | yes | |
| `lines[].unit_price` | Money | no | Preserves a price override made offline |

**Response `200`**

```json
{
  "success": true,
  "message": "2 held lines applied, 1 needs attention",
  "data": {
    "results": [
      { "status": "applied", "client_line_id": "ln_1", "product_id": 685, "quantity": 2 },
      { "status": "rejected", "client_line_id": "ln_2", "product_id": 999999,
        "reason": { "code": "product.not_found", "message": "This product was deleted" } },
      { "status": "adjusted", "client_line_id": "ln_3", "product_id": 686, "quantity": 3,
        "reason": { "code": "product.partial_stock", "message": "Only 3 left in the shop" } }
    ],
    "cart": { "cart_id": 182, "type": "shop", "version": 6, "...": "the whole ticket, as in GET /cart" }
  }
}
```

| `status` | Meaning | Client action |
| --- | --- | --- |
| `applied` | Added in full | Delete the local held line |
| `adjusted` | Added, but trimmed to what is in stock (`reason.code: product.partial_stock`) | Delete the local line and show what changed |
| `duplicate` | The same `idempotency_key` was already applied | Delete the local line |
| `rejected` | Not added (`reason.code` is `product.not_found`, `product.out_of_stock` or `cart.quantity_invalid`) | Keep the line flagged and tell the shopkeeper why |

`quantity` on a result is how many units **this line added**, not the line total on the
ticket. Every line is judged on its own, so one bad line never fails the batch.

**Retrying the same request** (same `idempotency_key`) changes nothing: every
`applied` or `adjusted` line is reported as `duplicate`, and `cart` is the ticket as
it is now.

**Recommended call sites:** once when the register opens, and once when connectivity
returns. Not before every payment, and not after every scan.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `409` | `idempotency.key_reused` | Same key, different lines |
| `422` | `validation.failed` | No lines, more than 200, a repeated `client_line_id`, an unknown `strategy` |

## 7. POST `/api/v1/cart/pay` — Complete the sale

**Purpose:** Completes the sale. **Idempotent: `idempotency_key` is required.**
Replaces `POST /api/cart/transactionByCash` and `POST /api/cart/placeOrder`.

One call takes the payment and, once it is paid, creates the order, takes the stock
off the shelf and clears the ticket, together. The legacy flow did these as separate
requests, so a dropped connection between them could leave a paid sale with no order.

**Request**

```json
{
  "type": "shop",
  "cart_version": 6,
  "rail": "cash",
  "amount_tendered": { "amount": 20000, "currency": "USD" },
  "customer": { "name": "Amina Yusuf", "mobile_number": "+252635550101" },
  "idempotency_key": "5a8f2d19-7c34-4b02-91ea-3f6d8b0c7e25"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `type` | enum | no | `shop` (default) \| `stock` |
| `cart_version` | int | yes | The ticket `version` the shopkeeper confirmed; `409` if it moved |
| `rail` | enum | yes | `cash`, `zaad` or `edahab`. `card` and `nfc` are not built yet. |
| `amount_tendered` | Money | no | Cash only, USD: the cash handed over. When sent, `change_due` is returned. |
| `customer.name` | string | no | On the receipt |
| `customer.mobile_number` | string | no | |
| `customer.wallet_number` | string | wallet rails | The number to bill; must belong to the rail (`63…` is Zaad, `65…` `66…` `62…` is eDahab) |
| `quote_id` | string | no | From [`POST /payments/quote`](payments.md#2-post-apiv1paymentsquote--price-a-payment-before-charging), to lock the quoted fee |
| `idempotency_key` | string | yes | UUID, generated when the shopkeeper taps Pay |

**Response `200`: cash, settled**

```json
{
  "success": true,
  "message": "Sale complete",
  "data": {
    "status": "paid",
    "charge_id": "chg_01M2Z2WBNVER5JK4H8C7H42AQW",
    "order": {
      "id": 164,
      "order_status": "Complete",
      "total": { "amount": 10001, "currency": "USD", "display": "$100.01" }
    },
    "change_due": { "amount": 9999, "currency": "USD", "display": "$99.99" },
    "receipt": { "invoice_no": "INV-164", "url": "/api/v1/orders/164/receipt" },
    "cart": { "cart_id": 182, "type": "shop", "version": 7, "is_empty": true, "items": [], "...": "all totals at zero" }
  }
}
```

`change_due` is `null` when no `amount_tendered` was sent. The response is stored
against the `idempotency_key`, so a retry returns this same sale and never a second
order.

**Response `202`: wallet rail, awaiting approval**

```json
{
  "success": true,
  "message": "Ask the customer to approve the payment",
  "data": {
    "status": "pending",
    "charge_id": "chg_01M2Z2WBMQA3QTGYBY3Q85XN98",
    "poll": "/api/v1/payments/charges/chg_01M2Z2WBMQA3QTGYBY3Q85XN98",
    "poll_after": 3
  }
}
```

The ticket and the stock are **not** touched until the charge reaches `paid`. The
client polls [`GET /payments/charges/{charge_id}`](payments.md#4-get-apiv1paymentschargescharge_id--check-a-payment)
every `poll_after` seconds; when it reports `paid` it carries the order and receipt,
and the ticket is already cleared. A wallet is billed in **SLSH** at the shop's
exchange rate (plus the wallet fee on Gold).

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `409` | `cart.version_conflict` | The ticket changed: re-read and re-confirm the total (`error.details.current`) |
| `409` | `payment.charge_pending` | A payment for this ticket is already waiting for the customer (`error.details.charge_id`) |
| `409` | `idempotency.key_reused` | Same key, different request |
| `422` | `cart.empty` | Nothing to pay for |
| `422` | `payment.tender_too_low` | Cash given is less than the total (`error.details.due`, `error.field: amount_tendered`) |
| `422` | `payment.rail_unavailable` | The shop cannot take that rail (`error.details.available_rails`) |
| `422` | `payment.wallet_invalid` | The customer number is missing or does not belong to that rail (`error.field: customer.wallet_number`) |
| `422` | `validation.failed` | Missing or malformed fields, for example an unknown `rail` |
| `502` | `payment.provider_unavailable` | The wallet provider is down; nothing was charged |

```json
{
  "success": false,
  "message": "The ticket changed. Check the total and try again.",
  "error": {
    "code": "cart.version_conflict",
    "details": { "current": { "cart_id": 182, "version": 6, "...": "the whole ticket, as in GET /cart" } }
  }
}
```

```json
{
  "success": false,
  "message": "The cash given is less than the total",
  "error": {
    "code": "payment.tender_too_low",
    "field": "amount_tendered",
    "details": { "due": { "amount": 10001, "currency": "USD", "display": "$100.01" } }
  }
}
```

```json
{
  "success": false,
  "message": "That payment method is not available for this shop",
  "error": {
    "code": "payment.rail_unavailable",
    "field": "rail",
    "details": { "available_rails": ["edahab", "cash"] }
  }
}
```

```json
{
  "success": false,
  "message": "A payment for this sale is already waiting for the customer",
  "error": { "code": "payment.charge_pending", "details": { "charge_id": "chg_01M2Z2WBMQA3QTGYBY3Q85XN98" } }
}
```

`cart.has_held_lines` is **not enforced**: the server cannot see lines that exist only
on the device, so the app keeps its own rule that a sale is not taken while lines are
held. Payments are also refused offline; see
[payments.md](payments.md#there-is-no-offline-charge).

## 8. POST `/api/v1/cart/hold` — Hold the ticket as a pending order

**Purpose:** Parks the ticket as a pending order with customer details, and clears
it. Replaces `POST /api/cart/placePendingOrder`. **Idempotent: `idempotency_key` is
required.**

**Request**

```json
{
  "type": "shop",
  "customer": { "name": "Amina Yusuf", "mobile_number": "+252635550101" },
  "note": "Collecting Thursday",
  "idempotency_key": "c71a4e82-3d95-4b08-8e1f-6a2c9d4b7f10"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `type` | enum | no | `shop` (default) \| `stock` |
| `customer.name` | string | yes | |
| `customer.mobile_number` | string | yes | |
| `note` | string | no | Up to 255 characters |
| `signature_file_id` | string | no | From [`POST /files`](files.md#1-post-apiv1files--upload-a-file), `purpose: signature` |
| `idempotency_key` | string | yes | UUID, one per attempt |

**Response `201`**

```json
{
  "success": true,
  "message": "Order held for Amina Yusuf",
  "data": {
    "order": {
      "id": 165,
      "order_status": "Pending",
      "name": "Amina Yusuf",
      "mobile_number": "+252635550101",
      "total": { "amount": 3885, "currency": "USD", "display": "$38.85" },
      "created_at": "2026-09-20T09:38:12Z"
    },
    "cart": { "cart_id": 182, "type": "shop", "version": 9, "is_empty": true, "items": [], "...": "all totals at zero" }
  }
}
```

Holding does **not** take stock off the shelf: it leaves when the order is paid or
completed. The held order is settled later through
[`POST /orders/{id}/pay`](orders.md).

### Signatures

The legacy endpoint accepted the signature inline as a base64 data URL in the JSON
body, which makes the request large and slow, and a failure loses the order along
with the image. In v1 the signature is uploaded separately to
[`POST /files`](files.md#1-post-apiv1files--upload-a-file) (retryable on its own)
and only its id sent here. Sending no `signature_file_id` saves the order without
one; sending one that was not uploaded with `purpose: signature` returns
`422 validation.failed` on `signature_file_id`.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `422` | `cart.empty` | There is nothing to hold |
| `422` | `validation.failed` | The customer name or mobile number is missing |

```json
{
  "success": false,
  "message": "Please check the form",
  "error": {
    "code": "validation.failed",
    "details": {
      "customer": ["The customer field is required."],
      "customer.name": ["The customer.name field is required."],
      "customer.mobile_number": ["The customer.mobile number field is required."]
    }
  }
}
```

---

## Step by step: a sale at the till

```
GET    /cart                              → the till's ticket (empty at the start of a sale)
GET    /products/lookup?barcode=...       → the shopkeeper scans a product
POST   /cart/items  { product_id, idempotency_key }   → the ticket, priced, with the line added
PATCH  /cart/items/{product_id}           → change quantity or price (optional)
GET    /cart                              → confirm the total; remember cart.version

Pay with cash:
POST   /cart/pay  { cart_version, rail: "cash", amount_tendered, idempotency_key }
                                          → 200, order + receipt + change_due, ticket cleared

Pay with a wallet:
POST   /cart/pay  { cart_version, rail: "edahab", customer.wallet_number, idempotency_key }
                                          → 202, charge_id + poll
GET    /payments/charges/{charge_id}      → repeat every poll_after seconds
                                          → "paid": order + receipt, ticket cleared (done)
                                          → "failed" / "expired": show why, offer another way

Or hold it for later:
POST   /cart/hold { customer, idempotency_key }       → 201, pending order, ticket cleared
```

## Step by step: working offline

```
(offline) scan → keep the line on the device with a client_line_id
(back online)
POST /cart/sync { client_ticket_id, lines, idempotency_key }
                                          → one result per line
                                          → delete lines reported applied / adjusted / duplicate
                                          → keep and flag lines reported rejected
GET  /cart                                → confirm the ticket before paying
```

| State | Client does |
| --- | --- |
| `409 product.out_of_stock` | Show "only N left" from `error.details.available` and let the shopkeeper decide |
| `409 cart.version_conflict` | Re-read the ticket from `error.details.current`, re-confirm the total, pay again |
| `409 payment.charge_pending` | Go back to polling `error.details.charge_id` instead of starting a second payment |
| `422 payment.tender_too_low` | Ask for more cash; the ticket is untouched |
| `422 payment.rail_unavailable` | Remove that rail from the picker |
| `422 cart.empty` | Nothing to pay: return to scanning |
| `202` on pay | Show the waiting screen and poll; do not clear the local ticket yet |
| `403 auth.permission_denied` | The user has no `pos` permission: hide the register |
| No network | Keep scanning into local held lines; payment is blocked until online |

---

## Postman / curl quick start

```bash
BASE=https://your-host/api/v1

curl $BASE/cart -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X POST $BASE/cart/items -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"product_id":685,"quantity":2,"idempotency_key":"e3c9a7b2-1f84-4d60-9a15-c7b208e4f931"}'

curl -X PATCH $BASE/cart/items/685 -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -H 'If-Match: 2' \
  -d '{"quantity":3}'

curl -X DELETE $BASE/cart/items/685 -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X POST $BASE/cart/sync -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"client_ticket_id":"tkt_local_18","idempotency_key":"9c1f2a4e-6b20-4a11-9f02-7d3b8c5e1a44","lines":[{"client_line_id":"ln_1","product_id":685,"quantity":2}]}'

curl -X POST $BASE/cart/pay -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"cart_version":6,"rail":"cash","amount_tendered":{"amount":20000,"currency":"USD"},"idempotency_key":"5a8f2d19-7c34-4b02-91ea-3f6d8b0c7e25"}'

curl -X POST $BASE/cart/hold -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"customer":{"name":"Amina Yusuf","mobile_number":"+252635550101"},"idempotency_key":"c71a4e82-3d95-4b08-8e1f-6a2c9d4b7f10"}'

curl -X DELETE $BASE/cart -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

To try a wallet payment locally without a real handset, use a customer number that
matches the rail and point the provider at a fake, as the tests do; the payment then
stays `pending` until the provider reports it paid.

---

## Implementation notes

- **Storage.** Tickets use the existing `carts` and `cart_items` tables. A migration
  adds `carts.device_id` and `carts.version`. There is one line per product, and a
  line's price is stored in USD.
- **The device** is read from the token name (`device:<id>`) set at login. A token
  without a device falls back to a shared `default` ticket.
- **Products on tickets.** A product cannot be deleted while it is on a ticket
  (`409 product.in_active_cart`, see [inventory.md](inventory.md)).
- **Stock is reserved by nothing.** A ticket does not hold stock; the stock rule is
  checked when a line is added or raised, and stock leaves the shelf only when the
  sale is paid (or the held order is paid or completed). If two tills sell the last
  unit, the second paid sale takes the shelf to `0` rather than failing, because the
  money is already taken.
- **Payments** are made through [payments.md](payments.md): `/cart/pay` builds a
  charge for the ticket total and follows the same rules (fee, currency, one open
  payment per sale).
- **After a wallet payment** the ticket is cleared only if it has not changed since
  the payment started, so a new sale begun in the meantime is never wiped.
- **Validation messages** are the standard Laravel wording under `error.details`,
  keyed by field. Branch on `error.code`, not the text.
- **Not built yet:** `cart.has_held_lines`, the `card` and `nfc` rails.
- **Legacy routes** (`/api/cart/*`) keep working alongside.
