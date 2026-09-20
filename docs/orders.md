# Orders

Completed and pending sales, and the receipts they produce.

The legacy API spread this across two families — `cart/*` for creating and
transitioning orders, `order/*` for reading them — with a separate endpoint per
state change (`updateOrderStatusToComplete`, `updateOrderStatusToPending`,
`placeOrder`, `paidOrder`). v1 has one resource and one status transition.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/orders`](#get-orders) | Bearer · `transactions` |
| GET | [`/orders/{id}`](#get-ordersid) | Bearer · `transactions` |
| POST | [`/orders`](#post-orders) | Bearer · `pos` |
| PATCH | [`/orders/{id}/status`](#patch-ordersidstatus) | Bearer · `transactions` |
| POST | [`/orders/{id}/pay`](#post-ordersidpay) | Bearer · `pos` |
| DELETE | [`/orders/{id}`](#delete-ordersid) | Bearer · `transactions` |
| GET | [`/orders/{id}/receipt`](#get-ordersidreceipt) | Bearer · `transactions` |
| GET | [`/invoices/{id}/receipt`](#get-invoicesidreceipt) | Bearer · `transactions` |

> **Status: implemented**, except two items: `GET /invoices/{id}/receipt` and
> `format=pdf` on the order receipt are not built yet. See
> [Implementation notes](#implementation-notes) for the rules the server applies.

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
| 3 | POST | `/api/v1/orders` | Save an order composed outside the register | `pos` | `client_order_id`, `items[]`, `customer`, `note`, `created_at` | `201` new / `200` replay | `404 product.not_found`, `422` |
| 4 | PATCH | `/api/v1/orders/{id}/status` | Move an order between statuses | `transactions` | `status`, `reason`, `idempotency_key` | `200` | `404`, `409 order.invalid_transition`, `422` |
| 5 | POST | `/api/v1/orders/{id}/pay` | Take payment for a pending order | `pos` | `rail`, `customer`, `amount_tendered`, `idempotency_key` | `200` cash / `202` wallet | `404`, `409 order.already_paid`, `409 payment.charge_pending`, `422` |
| 6 | DELETE | `/api/v1/orders/{id}` | Delete a pending order | `transactions` | — | `200` | `404`, `409 order.cannot_delete_complete` |
| 7 | GET | `/api/v1/orders/{id}/receipt` | Receipt data for printing | `transactions` | — | `200` | `404 order.not_found` |
| 8 | GET | `/api/v1/invoices/{id}/receipt` | Receipt for a request-payment invoice | `transactions` | — | not built | — |

**Status codes shared by every endpoint**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.token_invalid` | Missing, revoked or expired token: clear the session |
| `403` | `auth.permission_denied` | The user lacks the needed permission (`error.details.required_permission`) |
| `422` | `validation.failed` | Per-field messages in `error.details` |
| `429` | `rate_limited` | Slow down; honour `Retry-After` |

**Who can do what.** A shop owner holds every permission. Staff need `transactions`
to read and manage orders, and `pos` to create or pay them, so a cashier can take
sales without browsing the ledger and an auditor can read without taking money.
Another shop's order is always `404 order.not_found`.

**Statuses.** `Pending` (held, not yet fulfilled), `Complete` and `Cancelled`.
Whether money was received is a separate fact: `paid_at` is set only when it was.
Orders written by the legacy app as `Paid` show as `Complete`.

---

## GET /orders

Lists orders. Replaces `GET /api/order/allByStatus?order_status=`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `status` | enum | all | `pending` \| `complete` \| `cancelled` |
| `from` | date | — | `YYYY-MM-DD`, inclusive |
| `to` | date | — | inclusive |
| `q` | string | — | Matches customer name, phone or order id |
| `employee_id` | int | — | Orders taken by one staff member |
| `page`, `per_page` | int | `1`, `50` | |
| `sort` | string | `-created_at` | |

**Response `200`**

```json
{
  "success": true,
  "data": [
    {
      "id": 10245,
      "order_status": "Pending",
      "name": "Amina Yusuf",
      "initial_name": "AY",
      "mobile_number": "+252635550101",
      "payment_method": "card",
      "item_count": 2,
      "unit_count": 5,
      "total":     { "amount": 8534, "currency": "USD", "display": "$85.34" },
      "total_alt": { "amount": 682720, "currency": "SLSH", "display": "682,720 SLSH" },
      "has_signature": true,
      "created_at": "2026-09-15T08:14:00Z",
      "created_at_display": "15 Sep 2026, 08:14",
      "employee": { "id": 21, "name": "Layla Ahmed" }
    }
  ],
  "meta": {
    "pagination": { "page": 1, "per_page": 50, "total": 121, "total_pages": 3, "has_more": true },
    "summary": {
      "pending_count": 4,
      "complete_count": 117,
      "pending_value": { "amount": 24180, "currency": "USD", "display": "$241.80" }
    }
  }
}
```

`meta.summary` lets the orders screen show its header counts without a second
request. `initial_name` is kept because the current list draws avatar initials
from it. `created_at_display` is server-formatted in the merchant's timezone so
the client does no date parsing.

Line items are **not** included in the list — fetch the order for those. The
legacy `allByStatus` returned every line of every order, which is a large
payload on a slow link when the screen only shows a summary row.

---

## GET /orders/{id}

One order in full.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "id": 10245,
    "version": 2,
    "order_status": "Pending",
    "name": "Amina Yusuf",
    "initial_name": "AY",
    "mobile_number": "+252635550101",
    "payment_method": "card",
    "items": [
      {
        "product_id": 107,
        "product_name": "Bottled Water 12pk",
        "quantity": 4,
        "unit_price": { "amount": 410, "currency": "USD", "display": "$4.10" },
        "line_total": { "amount": 1640, "currency": "USD", "display": "$16.40" },
        "line_total_alt": { "amount": 131200, "currency": "SLSH", "display": "131,200 SLSH" }
      },
      {
        "product_id": 101,
        "product_name": "Basmati Rice 5kg",
        "quantity": 1,
        "unit_price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
        "line_total": { "amount": 1850, "currency": "USD", "display": "$18.50" },
        "line_total_alt": { "amount": 148000, "currency": "SLSH", "display": "148,000 SLSH" }
      }
    ],
    "totals": {
      "subtotal":  { "amount": 3490, "currency": "USD", "display": "$34.90" },
      "vat":       { "amount": 175,  "currency": "USD", "display": "$1.75" },
      "fee":       { "amount": 70,   "currency": "USD", "display": "$0.70" },
      "total":     { "amount": 3665, "currency": "USD", "display": "$36.65" },
      "total_alt": { "amount": 293200, "currency": "SLSH", "display": "293,200 SLSH" },
      "vat_rate": 0.05,
      "exchange_rate": 8000
    },
    "signature": {
      "file_id": "file_01JBXV2K7M",
      "url": "https://cdn.exelo.co/sig/10245.png"
    },
    "charge": { "charge_id": "chg_01JBXT5P3R", "status": "pending", "rail": "card" },
    "note": "Collecting Thursday",
    "employee": { "id": 21, "name": "Layla Ahmed" },
    "created_at": "2026-09-15T08:14:00Z",
    "created_at_display": "15 Sep 2026, 08:14",
    "paid_at": null
  }
}
```

The signature is a URL, not inline base64 — the legacy response embedded it and
the client rebuilt the path as `${baseUrl}/public<signature>`.

---

## POST /orders

Creates an order directly, outside the register flow. Most orders are created
by [`POST /cart/pay`](cart.md#post-cartpay) or
[`POST /cart/hold`](cart.md#post-carthold); this exists for replaying an order
composed offline. **Idempotent — key required.**

**Request**

```json
{
  "client_order_id": "ord_local_44",
  "status": "pending",
  "customer": { "name": "Hodan Farah", "mobile_number": "+252635550202" },
  "items": [
    { "product_id": 104, "quantity": 1 },
    { "product_id": 103, "quantity": 3 }
  ],
  "signature_file_id": null,
  "created_at": "2026-09-18T11:02:00Z",
  "idempotency_key": "d18b3f70-..."
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `client_order_id` | string | yes | Local id; echoed back so the device can reconcile |
| `status` | enum | no | `pending` (default). `complete` requires a settled `charge_id`. |
| `items[]` | array | yes | Must be non-empty |
| `created_at` | timestamp | no | When the sale actually happened offline |
| `charge_id` | string | no | Required if `status: complete` |

`created_at` is honoured so a sale recorded during an outage lands in reports on
the day it happened, not the day it synced.

**Response `201`**

```json
{
  "success": true,
  "data": {
    "id": 10248,
    "client_order_id": "ord_local_44",
    "order_status": "Pending",
    "totals": { }
  }
}
```

An order can never be created as `complete` without a settled charge. There is
no path in this API for a client to assert that money was received.

---

## PATCH /orders/{id}/status

The one transition endpoint. Replaces
`POST /api/cart/updateOrderStatusToComplete` and
`POST /api/cart/updateOrderStatusToPending`.

**Request**

```json
{ "status": "complete", "idempotency_key": "6b2e9a14-..." }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `status` | enum | yes | `pending` \| `complete` \| `cancelled` |
| `reason` | string | no | Required when cancelling |

Allowed transitions:

| From | To | Allowed |
| --- | --- | --- |
| `pending` | `complete` | Yes — marks fulfilled |
| `pending` | `cancelled` | Yes |
| `complete` | `pending` | Yes — reopens a wrongly closed order |
| `complete` | `cancelled` | No — refund instead |
| `cancelled` | anything | No |

Completing an order this way records **fulfilment, not payment**. A pending
order that was never paid stays unpaid; `paid_at` remains `null`. To take money,
use [`POST /orders/{id}/pay`](#post-ordersidpay).

**Response `200`**

```json
{
  "success": true,
  "message": "Order marked complete",
  "data": { "id": 10245, "order_status": "Complete", "version": 3, "paid_at": null }
}
```

**Errors** — `409 order.invalid_transition` with the allowed targets in
`error.details.allowed`.

---

## POST /orders/{id}/pay

Settles a pending order. **Idempotent — key required.** Replaces
`POST /api/cart/paidOrder` and the invoice-payment endpoints.

**Request**

```json
{
  "rail": "edahab",
  "customer": { "wallet_number": "+252635550101" },
  "idempotency_key": "af03c8d2-..."
}
```

**Response `202`**

```json
{
  "success": true,
  "message": "Ask the customer to approve the payment",
  "data": {
    "status": "pending",
    "charge_id": "chg_01JBXW3N8T",
    "order_id": 10245,
    "poll": "/api/v1/payments/charges/chg_01JBXW3N8T",
    "poll_after": 3
  }
}
```

On settlement the order flips to `Complete` with `paid_at` set. Cash returns
`200` immediately with the completed order.

---

## DELETE /orders/{id}

Deletes a pending order. Replaces `DELETE /api/order/delete/{orderId}`.

Only `pending` orders can be deleted. A completed order is financial history —
cancel or refund it instead.

**Response `200`**

```json
{
  "success": true,
  "message": "Pending order deleted",
  "data": { "id": 10247, "deleted": true }
}
```

**Errors** — `409 order.cannot_delete_complete`.

---

## GET /orders/{id}/receipt

The receipt payload for printing or PDF. Replaces
`GET /api/order/getOrderDetailsForInvoice/{id}`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `format` | enum | `json` | `json` \| `pdf` |

`format=pdf` returns `application/pdf` directly rather than the envelope, so
the app no longer has to compose the document itself.

**Response `200` (`format=json`)**

```json
{
  "success": true,
  "data": {
    "merchant": {
      "business_name": "Exelo Retail",
      "location": "Hargeisa, Maroodi Jeex",
      "zaad_number": "+252632222222",
      "edahab_number": "+252631111111",
      "merchant_code": "EXL-102"
    },
    "invoice": {
      "invoice_no": "INV-10241",
      "invoice_date": "2026-09-14T09:42:00Z",
      "invoice_date_display": "14 Sep 2026, 09:42",
      "payment_status": "Paid",
      "payment_method": "card"
    },
    "customer": {
      "account": "ACC-10241",
      "name": "Amina Yusuf",
      "mobile_number": "+252635550101"
    },
    "items": [
      {
        "product_name": "Bottled Water 12pk",
        "quantity": 6,
        "unit_price": { "amount": 410, "currency": "USD", "display": "$4.10" },
        "line_total": { "amount": 2460, "currency": "USD", "display": "$24.60" }
      }
    ],
    "totals": {
      "subtotal": { "amount": 6350, "currency": "USD", "display": "$63.50" },
      "vat":      { "amount": 318,  "currency": "USD", "display": "$3.18" },
      "fee":      { "amount": 127,  "currency": "USD", "display": "$1.27" },
      "total":    { "amount": 6668, "currency": "USD", "display": "$66.68" },
      "total_alt":{ "amount": 533440, "currency": "SLSH", "display": "533,440 SLSH" },
      "vat_label": "5%"
    },
    "signature_url": "https://cdn.exelo.co/sig/10241.png",
    "footer": "Thank you for shopping with Exelo Retail"
  }
}
```

---

## GET /invoices/{id}/receipt

The receipt for a request-payment invoice that has no order behind it — the
"request payment" screen, where a merchant bills a customer directly. Replaces
`GET /api/order/getInvoiceDetailsForInvoice/{invoiceId}`.

Same response shape as the order receipt, with `invoice.invoice_no` set and
`items` empty when the charge was a bare amount rather than a basket.

---

## Implementation notes

- **Storage.** Orders use the existing `orders` and `order_items` tables. A
  migration adds `version`, `paid_at`, `payment_method`, `note`, `client_order_id`,
  `cancel_reason` and `stock_deducted_at`. Prices on an order are the ones on the
  ticket when it was sold, so later price changes never rewrite history.
- **Totals.** `subtotal` is the sum of the lines, `vat` is each line's VAT, and
  `total` is `subtotal + vat`. `fee` is the platform fee taken on a wallet payment
  (`0` for cash), kept for reporting; whether the customer or the shop paid it is on
  the [charge](payments.md), not the order.
- **Stock leaves the shelf once**, at the moment an order is first paid or first
  marked `complete`, never when it is held. A shortfall never blocks an order that
  is already paid: the shelf goes to `0` instead. Reopening a completed order does
  not put stock back; **deleting** a pending order does, if it had left.
- **`POST /orders`** creates `pending` orders only, priced from the catalogue.
  `status: complete` is refused (`422`): create the order, then pay it, so a client
  can never assert that money was received. Sending the same `client_order_id`
  again returns the existing order with `200` and the message `Order already saved`.
  `created_at` is honoured so an offline sale lands in reports on the day it
  happened.
- **`PATCH /orders/{id}/status`.** Cancelling needs a `reason` (`422`, `error.field:
  reason`). `complete` records fulfilment, not payment. The transitions are those in
  the table in that section; anything else returns `409 order.invalid_transition` with the
  allowed targets in `error.details.allowed`.
- **`POST /orders/{id}/pay`** returns `200` for cash and `202` for a wallet, and
  the order becomes `Complete` with `paid_at` set only when the payment reaches
  `paid` (polled through
  [`GET /payments/charges/{charge_id}`](payments.md#4-get-apiv1paymentschargescharge_id--check-a-payment)).
  The cash response is `{ "status": "paid", "charge_id", "order", "change_due" }`
  (`change_due` only when `amount_tendered` is sent); the wallet response is
  `{ "status": "pending", "charge_id", "order_id", "poll", "poll_after" }`.
- **Receipt.** JSON only; `format=pdf` is not built yet. `payment_status` is `Paid`
  or `Unpaid`. `signature_url` is `null` until the Files module exists, and the
  `charge` block shows how it was paid. `initial_name` and `created_at_display`
  (in the shop's timezone) are included.
- **`employee`** is the staff member (or the owner) who took the sale; for the
  owner `employee.id` is `null`.
- **Not built:** `GET /invoices/{id}/receipt`, PDF receipts and signature upload.
- **Legacy routes** (`/api/cart/*`, `/api/order/*`) keep working alongside.
