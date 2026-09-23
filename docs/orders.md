# Orders

Completed and pending sales, and the receipts they produce.

The legacy API spread this across two families — `cart/*` for creating and
transitioning orders, `order/*` for reading them — with a separate endpoint per
state change (`updateOrderStatusToComplete`, `updateOrderStatusToPending`,
`placeOrder`, `paidOrder`). v1 has one resource and one status transition.

> **Status: implemented**, except two items: `GET /invoices/{id}/receipt` and
> `format=pdf` on the order receipt are not built yet. Every other response
> below is captured from the running API. See
> [Implementation notes](#implementation-notes) for the rules the server
> applies.

| Method | Path | Auth |
| --- | --- | --- |
| GET | `/orders` | Bearer · `transactions` |
| GET | `/orders/{id}` | Bearer · `transactions` |
| POST | `/orders` | Bearer · `pos` |
| PATCH | `/orders/{id}/status` | Bearer · `transactions` |
| POST | `/orders/{id}/pay` | Bearer · `pos` |
| DELETE | `/orders/{id}` | Bearer · `transactions` |
| GET | `/orders/{id}/receipt` | Bearer · `transactions` |
| GET | `/invoices/{id}/receipt` | Bearer · `transactions` |

---

## Complete endpoint list

Full URL = `{BASE_URL}/api/v1` + path.

**Headers**

| Header | Sent on | Value |
| --- | --- | --- |
| `Accept` | Every request | `application/json` |
| `Content-Type` | Requests with a body | `application/json` |
| `Authorization` | Every request | `Bearer <token>` from [`POST /auth/pin/login`](auth.md#post-authpinlogin) |

| # | Method | Full path | Purpose | Needs | Body / query | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | GET | `/api/v1/orders` | List orders with header counts | `transactions` | `status`, `from`, `to`, `q`, `employee_id`, `sort`, `page`, `per_page` | `200` | `403`, `422` |
| 2 | GET | `/api/v1/orders/{id}` | One order in full | `transactions` | — | `200` | `404 order.not_found` |
| 3 | POST | `/api/v1/orders` | Save an order composed outside the register | `pos` | `client_order_id`, `items[]`, `customer`, `note`, `signature_file_id`, `created_at` | `201` new / `200` replay | `404 product.not_found`, `422` |
| 4 | PATCH | `/api/v1/orders/{id}/status` | Move an order between statuses | `transactions` | `status`, `reason`, `idempotency_key` | `200` | `404`, `409 order.invalid_transition`, `422` |
| 5 | POST | `/api/v1/orders/{id}/pay` | Take payment for a pending order | `pos` | `rail`, `customer`, `amount_tendered`, `idempotency_key` | `200` cash / `202` wallet | `404`, `409 order.already_paid`, `409 payment.charge_pending`, `422` |
| 6 | DELETE | `/api/v1/orders/{id}` | Delete a pending order | `transactions` | — | `200` | `404`, `409 order.cannot_delete_complete` |
| 7 | GET | `/api/v1/orders/{id}/receipt` | Receipt data for printing | `transactions` | `format` | `200` | `404 order.not_found` |
| 8 | GET | `/api/v1/invoices/{id}/receipt` | Receipt for a request-payment invoice | `transactions` | — | not built | — |

**Status codes shared by every endpoint**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.token_invalid` | Missing, revoked or expired token: clear the session |
| `403` | `auth.permission_denied` | The user lacks the needed permission (`error.details.required_permission`) |
| `422` | `validation.failed` | Per-field messages in `error.details` |
| `429` | `rate_limited` | Slow down; honour `Retry-After` |

**Response envelope.** Every response carries `success`, `message`, `data` (or
`error`) and `meta.request_id` / `meta.server_time`; `GET /orders` also adds
`meta.pagination` and `meta.summary`. Branch on `error.code`, never on
`message`. See [errors.md](errors.md).

**Who can do what.** A shop owner holds every permission. Staff need
`transactions` to read and manage orders, and `pos` to create or pay them, so a
cashier can take sales without browsing the ledger and an auditor can read
without taking money. Another shop's order is always `404 order.not_found`,
never `403`.

**Money.** Every amount is `{ "amount": 2520, "currency": "USD", "display": "$25.20" }`
in minor units. `_alt` fields show the SLSH equivalent at the shop's own
exchange rate.

---

## Concepts

### Statuses, and paid is a separate fact

| `order_status` | Meaning |
| --- | --- |
| `Pending` | Held, not yet fulfilled |
| `Complete` | Fulfilled |
| `Cancelled` | Abandoned, with a reason |

Whether **money** was received is tracked separately: `paid_at` is set only
when the order was actually paid. A `Complete` order can have `paid_at: null`
if it was completed by [`PATCH /orders/{id}/status`](#4-patch-apiv1ordersidstatus--move-an-order-between-statuses)
without ever being paid — completing this way records **fulfilment, not
payment**. Orders written by the legacy app as `Paid` show as `Complete`.

### Where orders come from

Most orders are never created directly through this module — they come from
[`POST /cart/pay`](cart.md#7-post-apiv1cartpay--complete-the-sale) (a
till sale) or [`POST /cart/hold`](cart.md#8-post-apiv1carthold--hold-the-ticket-as-a-pending-order)
(parked for later). `POST /orders` exists for a fourth path: replaying an
order that was composed entirely offline. All four end up as the same
resource, read the same way.

### The one transition endpoint

`PATCH /orders/{id}/status` replaces four legacy endpoints
(`updateOrderStatusToComplete`, `updateOrderStatusToPending`,
`updateOrderStatusToCancelled` in effect, and the state-change half of
`placeOrder`) with one call and a state machine:

| From | To | Allowed |
| --- | --- | --- |
| `pending` | `complete` | Yes — marks fulfilled |
| `pending` | `cancelled` | Yes, with a `reason` |
| `complete` | `pending` | Yes — reopens a wrongly closed order |
| `complete` | `cancelled` | No — refund instead |
| `cancelled` | anything | No |

### Stock leaves the shelf once

Stock is taken off the shelf the moment an order is **first** paid or **first**
marked `complete` — never when it is only held. Reopening a completed order
back to `pending` does not put stock back. Only **deleting** a pending order
returns stock, and only if it had left. A shortfall never blocks an order that
is already paid: the shelf goes to `0` instead of failing.

### Never a client-asserted payment

There is no field, on `POST /orders` or anywhere else, that lets a client claim
money was received. `POST /orders` can only create `pending` orders — sending
`status: complete` is refused with `422 validation.failed`. The only way an
order becomes paid is through [`POST /cart/pay`](cart.md#7-post-apiv1cartpay--complete-the-sale)
or [`POST /orders/{id}/pay`](#5-post-apiv1ordersidpay--take-payment-for-a-pending-order),
which talk to a real payment rail.

---

## 1. GET `/api/v1/orders` — List orders

**Purpose:** Lists orders with header counts. Replaces
`GET /api/order/allByStatus?order_status=`. Needs `transactions`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `status` | enum | all | `pending` \| `complete` \| `cancelled` |
| `from`, `to` | date | — | `YYYY-MM-DD`, inclusive |
| `q` | string | — | Matches customer name, phone or order id |
| `employee_id` | int | — | Orders taken by one staff member |
| `sort` | string | `-created_at` | `created_at` \| `-created_at` \| `total` \| `-total` |
| `page`, `per_page` | int | `1`, `50` | `per_page` is at most `200` |

**Response `200`** (captured)

```json
{
  "success": true,
  "data": [
    {
      "id": 550,
      "version": 1,
      "order_status": "Pending",
      "name": "Hodan Farah",
      "initial_name": "HF",
      "mobile_number": "+252635550202",
      "payment_method": null,
      "item_count": 1,
      "unit_count": 1,
      "total": { "amount": 1890, "currency": "USD", "display": "$18.90" },
      "total_alt": { "amount": 151200, "currency": "SLSH", "display": "151,200 SLSH" },
      "has_signature": false,
      "created_at": "2026-09-23T10:02:15Z",
      "created_at_display": "23 Sep 2026, 13:02",
      "employee": { "id": null, "name": "Kalid Ahmed" }
    },
    {
      "id": 549,
      "version": 2,
      "order_status": "Complete",
      "name": "Amina Yusuf",
      "initial_name": "AY",
      "mobile_number": "+252635550101",
      "payment_method": "cash",
      "item_count": 1,
      "unit_count": 6,
      "total": { "amount": 2520, "currency": "USD", "display": "$25.20" },
      "total_alt": { "amount": 201600, "currency": "SLSH", "display": "201,600 SLSH" },
      "has_signature": false,
      "created_at": "2026-09-23T10:02:15Z",
      "created_at_display": "23 Sep 2026, 13:02",
      "employee": { "id": null, "name": "Kalid Ahmed" }
    }
  ],
  "meta": {
    "pagination": { "page": 1, "per_page": 50, "total": 2, "total_pages": 1, "has_more": false },
    "summary": {
      "pending_count": 1,
      "complete_count": 1,
      "pending_value": { "amount": 1890, "currency": "USD", "display": "$18.90" }
    }
  }
}
```

| Field | Meaning |
| --- | --- |
| `meta.summary` | Header counts for the whole shop, unfiltered by `status` — lets the screen show its badges without a second request |
| `initial_name` | Avatar initials, kept because the current client draws them from this |
| `created_at_display` | Server-formatted in the shop's timezone, so the client does no date parsing |
| `employee.id` | `null` when the owner took the sale, not a staff member |

Line items are **not** included in the list — fetch the order for those. The
legacy `allByStatus` returned every line of every order, a large payload on a
slow link when the screen only shows a summary row.

**`?status=pending`** filters the `data` array; `meta.summary` is always the
whole shop's counts regardless of the filter.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `422` | `validation.failed` | An unknown `status` or `sort`, or `to` before `from` |

## 2. GET `/api/v1/orders/{id}` — Get one order

**Purpose:** One order in full, with its lines and totals. Needs
`transactions`.

**Response `200`: a paid order** (captured)

```json
{
  "success": true,
  "data": {
    "id": 549,
    "version": 2,
    "order_status": "Complete",
    "name": "Amina Yusuf",
    "initial_name": "AY",
    "mobile_number": "+252635550101",
    "payment_method": "cash",
    "items": [
      {
        "product_id": 2392,
        "product_name": "Bottled Water 12pk",
        "quantity": 6,
        "unit_price": { "amount": 400, "currency": "USD", "display": "$4.00" },
        "line_total": { "amount": 2400, "currency": "USD", "display": "$24.00" },
        "line_total_alt": { "amount": 192000, "currency": "SLSH", "display": "192,000 SLSH" }
      }
    ],
    "totals": {
      "subtotal":  { "amount": 2400, "currency": "USD", "display": "$24.00" },
      "vat":       { "amount": 120,  "currency": "USD", "display": "$1.20" },
      "fee":       { "amount": 0,    "currency": "USD", "display": "$0.00" },
      "total":     { "amount": 2520, "currency": "USD", "display": "$25.20" },
      "total_alt": { "amount": 201600, "currency": "SLSH", "display": "201,600 SLSH" },
      "vat_rate": 0.05,
      "exchange_rate": 8000
    },
    "signature": null,
    "charge": { "charge_id": "chg_01M36VEHTSDRNFJT412EZG20BC", "status": "paid", "rail": "cash" },
    "note": null,
    "employee": { "id": null, "name": "Kalid Ahmed" },
    "created_at": "2026-09-23T10:02:15Z",
    "created_at_display": "23 Sep 2026, 13:02",
    "paid_at": "2026-09-23T10:02:15Z"
  }
}
```

**Response `200`: a pending order held with a signature** (captured)

```json
{
  "success": true,
  "data": {
    "id": 550,
    "version": 1,
    "order_status": "Pending",
    "name": "Hodan Farah",
    "initial_name": "HF",
    "mobile_number": "+252635550202",
    "payment_method": null,
    "items": [
      {
        "product_id": 2393,
        "product_name": "Basmati Rice 5kg",
        "quantity": 1,
        "unit_price": { "amount": 1800, "currency": "USD", "display": "$18.00" },
        "line_total": { "amount": 1800, "currency": "USD", "display": "$18.00" },
        "line_total_alt": { "amount": 144000, "currency": "SLSH", "display": "144,000 SLSH" }
      }
    ],
    "totals": {
      "subtotal":  { "amount": 1800, "currency": "USD", "display": "$18.00" },
      "vat":       { "amount": 90,   "currency": "USD", "display": "$0.90" },
      "fee":       { "amount": 0,    "currency": "USD", "display": "$0.00" },
      "total":     { "amount": 1890, "currency": "USD", "display": "$18.90" },
      "total_alt": { "amount": 151200, "currency": "SLSH", "display": "151,200 SLSH" },
      "vat_rate": 0.05,
      "exchange_rate": 8000
    },
    "signature": { "file_id": "file_01M36VEHWPHSVZVNWCNA539WB3", "url": "https://your-host/storage/files/4796/bd274eda-....png" },
    "charge": null,
    "note": "Collecting Thursday",
    "employee": { "id": null, "name": "Kalid Ahmed" },
    "created_at": "2026-09-23T10:02:15Z",
    "created_at_display": "23 Sep 2026, 13:02",
    "paid_at": null
  }
}
```

`signature` is a real URL through the [Files module](files.md#1-post-apiv1files--upload-a-file),
not inline base64 — the legacy response embedded it and the client rebuilt the
path as `${baseUrl}/public<signature>`. `charge` is `null` until a payment has
been started for this order; once one exists it shows the same `charge_id`,
`status` and `rail` you would get from
[`GET /payments/charges/{charge_id}`](payments.md#4-get-apiv1paymentschargescharge_id--check-a-payment).

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `order.not_found` | No such order for this shop |

```json
{ "success": false, "message": "We could not find that order", "error": { "code": "order.not_found" } }
```

## 3. POST `/api/v1/orders` — Save an order composed offline

**Purpose:** Creates an order directly, outside the register flow. Most orders
come from [`POST /cart/pay`](cart.md#7-post-apiv1cartpay--complete-the-sale)
or [`POST /cart/hold`](cart.md#8-post-apiv1carthold--hold-the-ticket-as-a-pending-order);
this exists for replaying an order composed while offline. **Idempotent
through `client_order_id`.** Needs `pos`.

**Request**

```json
{
  "client_order_id": "ord_local_44",
  "customer": { "name": "Zainab Omar", "mobile_number": "+252635550303" },
  "items": [
    { "product_id": 2392, "quantity": 2 },
    { "product_id": 2393, "quantity": 1 }
  ],
  "note": null,
  "signature_file_id": null,
  "created_at": "2026-09-18T11:02:00Z"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `client_order_id` | string | yes | Local id; sending it again replays instead of duplicating |
| `status` | enum | no | Only `pending` is accepted; `complete` is refused (see [Concepts](#never-a-client-asserted-payment)) |
| `customer.name`, `customer.mobile_number` | string | no | |
| `items[].product_id`, `items[].quantity` | int | yes | Priced from the current catalogue |
| `note` | string | no | |
| `signature_file_id` | string | no | From [`POST /files`](files.md#1-post-apiv1files--upload-a-file), `purpose: signature` |
| `created_at` | timestamp | no | When the sale actually happened offline |
| `idempotency_key` | string | no | Optional here: `client_order_id` already identifies the request |

`created_at` is honoured so a sale recorded during an outage lands in reports
on the day it happened, not the day it synced.

**Response `201`** (captured)

```json
{
  "success": true,
  "message": "Order saved",
  "data": {
    "id": 551,
    "version": 1,
    "order_status": "Pending",
    "name": "Zainab Omar",
    "initial_name": "ZO",
    "mobile_number": "+252635550303",
    "payment_method": null,
    "items": [
      { "product_id": 2392, "product_name": "Bottled Water 12pk", "quantity": 2,
        "unit_price": { "amount": 400, "currency": "USD", "display": "$4.00" },
        "line_total": { "amount": 800, "currency": "USD", "display": "$8.00" },
        "line_total_alt": { "amount": 64000, "currency": "SLSH", "display": "64,000 SLSH" } },
      { "product_id": 2393, "product_name": "Basmati Rice 5kg", "quantity": 1,
        "unit_price": { "amount": 1800, "currency": "USD", "display": "$18.00" },
        "line_total": { "amount": 1800, "currency": "USD", "display": "$18.00" },
        "line_total_alt": { "amount": 144000, "currency": "SLSH", "display": "144,000 SLSH" } }
    ],
    "totals": {
      "subtotal":  { "amount": 2600, "currency": "USD", "display": "$26.00" },
      "vat":       { "amount": 130,  "currency": "USD", "display": "$1.30" },
      "fee":       { "amount": 0,    "currency": "USD", "display": "$0.00" },
      "total":     { "amount": 2730, "currency": "USD", "display": "$27.30" },
      "total_alt": { "amount": 218400, "currency": "SLSH", "display": "218,400 SLSH" },
      "vat_rate": 0.05,
      "exchange_rate": 8000
    },
    "signature": null,
    "charge": null,
    "note": null,
    "employee": { "id": null, "name": "Kalid Ahmed" },
    "created_at": "2026-09-18T11:02:00Z",
    "created_at_display": "18 Sep 2026, 14:02",
    "paid_at": null,
    "client_order_id": "ord_local_44"
  }
}
```

**Sending the same `client_order_id` again is a replay, not an error** —
`200` with the existing order and the message `Order already saved`, ignoring
the rest of the body; nothing is duplicated or edited.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `product.not_found` | A line's `product_id` is not a product of this shop (`error.details.product_id`) |
| `422` | `validation.failed` | `status: complete` (see below), `items` missing or empty, or a required field missing |

```json
{
  "success": false,
  "message": "Please check the form",
  "error": { "code": "validation.failed", "details": { "status": ["Create the order as pending, then pay it"] } }
}
```

## 4. PATCH `/api/v1/orders/{id}/status` — Move an order between statuses

**Purpose:** The one transition endpoint. Replaces
`POST /api/cart/updateOrderStatusToComplete` and
`POST /api/cart/updateOrderStatusToPending`. **Idempotent: `idempotency_key`
is required.** Needs `transactions`.

**Request**

```json
{ "status": "complete", "idempotency_key": "6b2e9a14-5c3d-4f81-9e02-1a7b3c5d8e91" }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `status` | enum | yes | `pending` \| `complete` \| `cancelled` |
| `reason` | string | required when `status: cancelled` | |
| `idempotency_key` | string | yes | UUID, one per attempt |

See [Concepts](#the-one-transition-endpoint) for the allowed transitions.

**Response `200`** (captured)

```json
{
  "success": true,
  "message": "Order marked complete",
  "data": { "id": 550, "order_status": "Complete", "version": 2, "paid_at": null }
}
```

`paid_at` stays `null` here — this transition records fulfilment, not payment.
To take money, use [`POST /orders/{id}/pay`](#5-post-apiv1ordersidpay--take-payment-for-a-pending-order).

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `order.not_found` | No such order for this shop |
| `409` | `order.invalid_transition` | Not one of the allowed transitions; `error.details` has `from`, `to` and `allowed` |
| `422` | `validation.failed` | Cancelling without a `reason` (`error.field: reason`) |

```json
{
  "success": false,
  "message": "An order cannot go from complete to cancelled",
  "error": { "code": "order.invalid_transition", "details": { "from": "complete", "to": "cancelled", "allowed": ["pending"] } }
}
```

## 5. POST `/api/v1/orders/{id}/pay` — Take payment for a pending order

**Purpose:** Settles a pending order. **Idempotent: `idempotency_key` is
required.** Replaces `POST /api/cart/paidOrder` and the invoice-payment
endpoints. Needs `pos`. Uses the same [charge](payments.md) as every other
payment in the API.

**Request**

```json
{
  "rail": "edahab",
  "customer": { "wallet_number": "+252651110009" },
  "idempotency_key": "af03c8d2-6b14-4e97-a205-3f8c9d1e7b40"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `rail` | enum | yes | `cash` \| `zaad` \| `edahab`. `card` and `nfc` are not built yet. |
| `customer.wallet_number` | string | wallet rails | Must belong to the rail |
| `amount_tendered` | Money | no | Cash only, USD: returns `change_due` |
| `idempotency_key` | string | yes | UUID, one per attempt |

**Response `202`: wallet, awaiting the customer** (captured)

```json
{
  "success": true,
  "message": "Ask the customer to approve the payment",
  "data": {
    "status": "pending",
    "charge_id": "chg_01M36VEJ1E2216MH9QSMS3WNVM",
    "order_id": 550,
    "poll": "/api/v1/payments/charges/chg_01M36VEJ1E2216MH9QSMS3WNVM",
    "poll_after": 3
  }
}
```

Poll [`GET /payments/charges/{charge_id}`](payments.md#4-get-apiv1paymentschargescharge_id--check-a-payment)
every `poll_after` seconds. When it reports `paid`, the order has already
flipped to `Complete` with `paid_at` set and stock has already left the shelf
— nothing more to call.

**Response `200`: cash, settled immediately** — same shape as
[`POST /cart/pay`](cart.md#7-post-apiv1cartpay--complete-the-sale)'s cash
response: `{ "status": "paid", "charge_id", "order", "change_due" }`, with
`order` in the same detail shape as [`GET /orders/{id}`](#2-get-apiv1ordersid--get-one-order)
and `change_due` present only when `amount_tendered` was sent.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `order.not_found` | No such order for this shop |
| `409` | `order.already_paid` | This order already has `paid_at` set |
| `409` | `payment.charge_pending` | A payment for this order is already waiting for the customer (`error.details.charge_id`) |
| `422` | `validation.failed` | An unknown `rail`, or a missing wallet number |

## 6. DELETE `/api/v1/orders/{id}` — Delete a pending order

**Purpose:** Deletes a pending order. Replaces
`DELETE /api/order/delete/{orderID}`. Needs `transactions`.

Only `pending` orders can be deleted — a completed order is financial history,
so cancel it through [`PATCH /orders/{id}/status`](#4-patch-apiv1ordersidstatus--move-an-order-between-statuses)
instead. If the order had already taken stock off the shelf (a `Complete`
order reopened to `pending`, then deleted), that stock is put back.

**Response `200`** (captured)

```json
{ "success": true, "message": "Pending order deleted", "data": { "id": 552, "deleted": true } }
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `order.not_found` | No such order for this shop |
| `409` | `order.cannot_delete_complete` | Not a pending order |

```json
{
  "success": false,
  "message": "Only a pending order can be deleted. Completed orders are history.",
  "error": { "code": "order.cannot_delete_complete" }
}
```

## 7. GET `/api/v1/orders/{id}/receipt` — Get the receipt

**Purpose:** The receipt payload for printing or PDF. Replaces
`GET /api/order/getOrderDetailsForInvoice/{id}`. Needs `transactions`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `format` | enum | `json` | Only `json` is built; `pdf` is rejected for now (see [Implementation notes](#implementation-notes)) |

**Response `200`** (captured)

```json
{
  "success": true,
  "data": {
    "merchant": {
      "business_name": "Exelo Retail",
      "location": "Hargeisa, Maroodi Jeex",
      "zaad_number": "+252632220001",
      "edahab_number": "+252651110001",
      "merchant_code": "CAP-102"
    },
    "invoice": {
      "invoice_no": "INV-549",
      "invoice_date": "2026-09-23T10:02:15Z",
      "invoice_date_display": "23 Sep 2026, 13:02",
      "payment_status": "Paid",
      "payment_method": "cash"
    },
    "customer": {
      "account": "ACC-549",
      "name": "Amina Yusuf",
      "mobile_number": "+252635550101"
    },
    "items": [
      { "product_name": "Bottled Water 12pk", "quantity": 6,
        "unit_price": { "amount": 400, "currency": "USD", "display": "$4.00" },
        "line_total": { "amount": 2400, "currency": "USD", "display": "$24.00" } }
    ],
    "totals": {
      "subtotal":  { "amount": 2400, "currency": "USD", "display": "$24.00" },
      "vat":       { "amount": 120,  "currency": "USD", "display": "$1.20" },
      "fee":       { "amount": 0,    "currency": "USD", "display": "$0.00" },
      "total":     { "amount": 2520, "currency": "USD", "display": "$25.20" },
      "total_alt": { "amount": 201600, "currency": "SLSH", "display": "201,600 SLSH" },
      "vat_rate": 0.05,
      "exchange_rate": 8000,
      "vat_label": "5%"
    },
    "signature_url": null,
    "charge": { "charge_id": "chg_01M36VEHTSDRNFJT412EZG20BC", "status": "paid", "rail": "cash" },
    "footer": "Thank you for shopping with Exelo Retail"
  }
}
```

`payment_status` is `Paid` or `Unpaid`. `signature_url` resolves through the
[Files module](files.md) when the order was held with a `signature_file_id`,
and is `null` otherwise. `footer` comes from the shop's own
[receipt settings](merchant.md#5-get-apiv1merchantsettings--get-the-shop-preferences),
falling back to a generic thank-you line.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `order.not_found` | No such order for this shop |

## 8. GET `/api/v1/invoices/{id}/receipt` — Receipt for a request-payment invoice

**Purpose:** The receipt for a request-payment invoice that has no order
behind it — the "request payment" screen, where a shop bills a customer
directly. Replaces `GET /api/order/getInvoiceDetailsForInvoice/{invoiceId}`.

**Not built yet.** Same response shape as
[`GET /orders/{id}/receipt`](#7-get-apiv1ordersidreceipt--get-the-receipt) is
planned, with `invoice.invoice_no` set and `items` empty when the charge was a
bare amount rather than a basket.

---

## Step by step: composing an order offline, then paying it

```
POST /orders           { client_order_id, items, customer, created_at }
                                          → 201, Pending order (or 200 "Order already saved" if replayed)
POST /orders/{id}/pay  { rail: "cash", amount_tendered, idempotency_key }
                                          → 200, order + change_due
```

| State | Client does |
| --- | --- |
| `200 "Order already saved"` on create | Adopt the returned order, drop the queued row — nothing was duplicated |
| `409 order.invalid_transition` | Show `error.details.allowed` and offer only those |
| `409 order.already_paid` | Refresh the order; it is already settled |
| `409 payment.charge_pending` | Go back to polling `error.details.charge_id` instead of starting a second payment |
| `409 order.cannot_delete_complete` | Cancel through the status endpoint instead of deleting |
| `403 auth.permission_denied` | Hide the ledger for a cashier without `transactions`, or the pay button for staff without `pos` |

---

## Postman / curl quick start

```bash
BASE=https://your-host/api/v1

curl "$BASE/orders?status=pending" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl $BASE/orders/550 -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X POST $BASE/orders -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"client_order_id":"ord_local_44","customer":{"name":"Zainab Omar","mobile_number":"+252635550303"},"items":[{"product_id":2392,"quantity":2}]}'

curl -X PATCH $BASE/orders/550/status -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"status":"complete","idempotency_key":"6b2e9a14-5c3d-4f81-9e02-1a7b3c5d8e91"}'

curl -X POST $BASE/orders/550/pay -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"rail":"cash","amount_tendered":{"amount":2000,"currency":"USD"},"idempotency_key":"af03c8d2-6b14-4e97-a205-3f8c9d1e7b40"}'

curl -X DELETE $BASE/orders/550 -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl $BASE/orders/549/receipt -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

---

## Implementation notes

- **Storage.** Orders use the existing `orders` and `order_items` tables. A
  migration adds `version`, `paid_at`, `payment_method`, `note`,
  `client_order_id`, `cancel_reason`, `stock_deducted_at` and
  `signature_file_id`. Prices on an order are the ones on the ticket when it
  was sold, so later catalogue price changes never rewrite history.
- **`version` starts at `1`** on every order, whichever path created it — a
  freshly created order from `POST /orders` now returns `version: 1`, not
  `null` (fixed while writing this doc: `createFromLines()` was leaving it
  unset until the next read).
- **Totals.** `subtotal` is the sum of the lines, `vat` is each line's VAT,
  and `total` is `subtotal + vat`. `fee` is the platform fee taken on a wallet
  payment (`0` for cash), kept for reporting; whether the customer or the shop
  paid it is on the [charge](payments.md), not the order. `totals.vat_rate` is
  `vat ÷ subtotal`, so it can show a little rounding noise (e.g. `0.0503`
  instead of `0.05`) on a multi-line order where each line's VAT was rounded
  to the cent independently — the stored per-line rate is exact, this
  displayed ratio is a derived approximation.
- **Stock leaves the shelf once**, at the moment an order is first paid or
  first marked `complete`, never when it is held. See
  [Concepts](#stock-leaves-the-shelf-once).
- **`POST /orders`** creates `pending` orders only, priced from the catalogue.
  `status: complete` is refused (`422`) — see
  [Concepts](#never-a-client-asserted-payment). Sending the same
  `client_order_id` again returns the existing order with `200` and the
  message `Order already saved`. `created_at` is honoured so an offline sale
  lands in reports on the day it happened.
- **`PATCH /orders/{id}/status`.** Cancelling needs a `reason`
  (`422`, `error.field: reason`). The transitions are in
  [Concepts](#the-one-transition-endpoint); anything else returns
  `409 order.invalid_transition` with the allowed targets in
  `error.details.allowed`.
- **`POST /orders/{id}/pay`** returns `200` for cash and `202` for a wallet,
  and the order becomes `Complete` with `paid_at` set only when the payment
  reaches `paid` (polled through [`GET /payments/charges/{charge_id}`](payments.md#4-get-apiv1paymentschargescharge_id--check-a-payment)).
- **Receipt.** JSON only; `format=pdf` is not built yet, though the doc
  invoice/subscription PDF pattern (`barryvdh/laravel-dompdf`, already used
  for the admin invoice PDF) is the intended approach when it is.
- **`employee`** is the staff member (or the owner) who took the sale; for the
  owner `employee.id` is `null`.
- **Not built:** `GET /invoices/{id}/receipt` and PDF receipts.
- **Legacy routes** (`/api/cart/*`, `/api/order/*`) keep working alongside.
