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
      "total":     { "amount": 3560, "currency": "USD", "display": "$35.60" },
      "total_alt": { "amount": 284800, "currency": "SLSH", "display": "284,800 SLSH" },
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
      "total":    { "amount": 6477, "currency": "USD", "display": "$64.77" },
      "total_alt":{ "amount": 518160, "currency": "SLSH", "display": "518,160 SLSH" },
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
