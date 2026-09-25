# Payments & Wallets

How money moves: taking a payment from a customer (a **charge**) on Zaad, eDahab,
cash, card or NFC, checking what it will cost, paying money out, and receiving
provider callbacks.

One resource, the **charge**, covers every way money moves into the shop. The rail
is a field, not a different endpoint.

> **Status: partly implemented.**
>
> | Endpoint | State |
> | --- | --- |
> | `GET /payments/methods` | **Live** |
> | `POST /payments/quote` | **Live** |
> | `POST /payments/charges` | **Live** for `cash`, `zaad` and `edahab` (sales and held orders) |
> | `GET /payments/charges/{charge_id}` | **Live** for every payment of the shop: sales (`chg_…`) and subscriptions (`inv_…`) |
> | `confirm`, `cancel`, `payouts`, `card/session`, webhooks | **Specified, not built yet.** The examples are the **contract to build against**, not captured output. |
>
> Sales are also taken through [`POST /cart/pay`](cart.md#post-cartpay) and
> [`POST /orders/{id}/pay`](orders.md#5-post-apiv1ordersidpay--take-payment-for-a-pending-order), which use the same charge.
> See [Implementation notes](#implementation-notes) for how a charge works and what
> is still open.

This replaces the largest duplication in the legacy API: parallel near-identical
flows per wallet, split again between Gold (`payments.dart`) and Silver
(`paymentsforsmall.dart`), two endpoints that differ only by a `/zaad` path
segment, and `transaction/process` reused for the signup fee, the PIN-reset fee,
subscription upgrades and POS sales with only a `type` string to tell them apart.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/payments/methods`](#1-get-apiv1paymentsmethods--which-payment-methods-the-shop-accepts) | Bearer |
| POST | [`/payments/quote`](#2-post-apiv1paymentsquote--price-a-payment-before-charging) | Bearer · `pos` |
| POST | [`/payments/charges`](#3-post-apiv1paymentscharges--start-a-payment) | Bearer · `pos` |
| GET | [`/payments/charges/{charge_id}`](#4-get-apiv1paymentschargescharge_id--check-a-payment) | Bearer |
| POST | [`/payments/charges/{charge_id}/confirm`](#5-post-apiv1paymentschargescharge_idconfirm--confirm-a-payment) | Bearer · `pos` |
| POST | [`/payments/charges/{charge_id}/cancel`](#6-post-apiv1paymentschargescharge_idcancel--cancel-a-pending-payment) | Bearer · `pos` |
| POST | [`/payments/payouts`](#7-post-apiv1paymentspayouts--send-money-to-a-phone-number) | Bearer · `pos` · PIN confirmation |
| POST | [`/payments/card/session`](#8-post-apiv1paymentscardsession--start-a-card-payment) | Bearer · `pos` |
| POST | [`/webhooks/payments/{provider}`](#9-post-apiv1webhookspaymentsprovider--provider-callbacks) | Signature |

---

## Complete endpoint list

Full URL = `{BASE_URL}/api/v1` + path.

**Headers**

| Header | Sent on | Value |
| --- | --- | --- |
| `Accept` | Every request | `application/json` |
| `Content-Type` | Requests with a body | `application/json` |
| `Authorization` | Every request except webhooks | `Bearer <token>` from [`POST /auth/pin/login`](auth.md#post-authpinlogin) |
| `X-EXELO-Confirmation` | `POST /payments/payouts` | Single-use token from [`POST /auth/pin/verify`](auth.md) with `scope: "payments.payout"` |
| `X-EXELO-Signature`, `X-EXELO-Timestamp` | Webhooks only | HMAC of the raw body and the send time |

| # | Method | Full path | Purpose | Needs | Body / query | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | GET | `/api/v1/payments/methods` | Which wallets the shop is paid on and which rails it accepts | Bearer | — | `200` | `401` |
| 2 | POST | `/api/v1/payments/quote` | What an amount costs the customer and yields the shop | Bearer, `pos` | `amount`, `rail`, `purpose` | `200` | `403 auth.permission_denied`, `422 payment.rail_unavailable` |
| 3 | POST | `/api/v1/payments/charges` | Start a payment on any rail | Bearer, `pos` | `rail`, `purpose`, `amount`, `idempotency_key`, rail fields | `202` wallet / `200` cash | `402 payment.declined`, `409 payment.cart_changed`, `409 idempotency.key_reused`, `410 quote.expired`, `422 payment.rail_unavailable`, `502 payment.provider_unavailable` |
| 4 | GET | `/api/v1/payments/charges/{charge_id}` | Check a payment, poll until it settles | Bearer | — | `200` | `404 charge.not_found` |
| 5 | POST | `/api/v1/payments/charges/{charge_id}/confirm` | Second customer-side step, for rails that need one | Bearer, `pos` | `confirmation_code`, `idempotency_key` | `200` | `404 charge.not_found`, `409 payment.already_settled`, `422 payment.code_invalid` |
| 6 | POST | `/api/v1/payments/charges/{charge_id}/cancel` | Abandon a pending payment | Bearer, `pos` | — | `200` | `404 charge.not_found`, `409 payment.already_settled` |
| 7 | POST | `/api/v1/payments/payouts` | Send money to a phone number | Bearer, `pos`, PIN confirmation | `phone_number`, `amount`, `rail`, `idempotency_key` | `200` | `401 auth.confirmation_required`, `402 payment.declined`, `422 payment.wallet_invalid`, `502 payment.provider_unavailable` |
| 8 | POST | `/api/v1/payments/card/session` | Get a client token to tokenise a card | Bearer, `pos` | — | `200` | `422 payment.rail_unavailable`, `502 payment.provider_unavailable` |
| 9 | POST | `/api/v1/webhooks/payments/{provider}` | Provider callbacks (not called by the app) | Signature | provider event | `200` | `401 webhook.signature_invalid`, `404 not_found` |

**Status codes shared by every app endpoint**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.token_invalid` | Missing, revoked or expired token: clear the session |
| `403` | `auth.permission_denied` | The signed-in user lacks the `pos` permission (`error.details.required_permission`) |
| `422` | `validation.failed` | Per-field messages in `error.details` |
| `429` | `rate_limited` | Slow down; honour `Retry-After` |

**Response envelope.** Every response carries `success`, `message`, `data` (or
`error`) and `meta.request_id` / `meta.server_time`. Branch on `error.code`, never
on `message`. See [errors.md](errors.md).

**Money.** Every amount is an object `{ "amount": 3774, "currency": "USD", "display": "$37.74" }`.
`amount` is in **minor units** (cents for USD, whole shillings for SLSH). Send
`amount` and `currency` only; `display` is returned, never sent.

---

## Concepts

### Rails

How the money moves.

| Rail | Flow | Settles | Notes |
| --- | --- | --- | --- |
| `zaad` | Server issues, customer approves on their phone, server commits | After the customer approves | The server owns the commit; the app only polls |
| `edahab` | Push prompt to the customer's handset | After the customer approves | Customer approves on their phone |
| `cash` | Recorded, not routed | Instantly | Cash taken over the counter |
| `card` | Braintree | After the card is charged | Replaces the direct Cloud Function calls |
| `nfc` | Tag read, then routed | After the tag is verified | See [NFC](nfc.md) |

A wallet rail (`zaad`, `edahab`) is only offered when that wallet is **verified**
on [`GET /merchant/wallets`](merchant.md#3-get-apiv1merchantwallets--list-the-payout-wallets-and-their-status).
A `pending` or `not_set` wallet returns `422 payment.rail_unavailable`.

### Purpose

What the money is for. It decides fee treatment and what the charge settles
against.

| Purpose | Used by | Settles against |
| --- | --- | --- |
| `pos_sale` | Register checkout | The cart: creates the order and clears the cart |
| `order_settlement` | Paying an existing pending order | The order |
| `registration` | Signup fee (no login yet, see [Registration](registration.md)) | The registration invoice |
| `verification` | Wallet verification fee | The verification invoice |
| `subscription` | Plan upgrade or renewal (see [Subscription](subscription.md)) | The subscription invoice |
| `pin_reset` | PIN reset fee, if retained | The reset |

`registration`, `verification` and `subscription` charges are created by their own
modules. They are read through the same
[`GET /payments/charges/{charge_id}`](#4-get-apiv1paymentschargescharge_id--check-a-payment)
poll, so the app has one place to check any payment.

### Status lifecycle

```
pending ──► authorized ──► paid
   │                        │
   ├──► failed              └──► refunded
   ├──► expired
   └──► cancelled
```

| Status | Meaning | Client does |
| --- | --- | --- |
| `pending` | Waiting for the customer to approve | Show the waiting screen, poll after `poll_after` seconds |
| `authorized` | Approved, not yet captured | Keep polling |
| `paid` | Money received | Finish: show the receipt |
| `failed` | Declined or errored; `failure` explains why | Show the reason, offer another rail |
| `expired` | The customer did not approve in time | Offer to try again |
| `cancelled` | The shopkeeper cancelled | Return to the cart |
| `refunded` | Paid, then returned | Show as refunded |

`paid`, `failed`, `expired`, `cancelled` and `refunded` are final: stop polling.

### Idempotency

`POST /payments/charges`, `/confirm` and `/payouts` take an `idempotency_key`
(a UUID the app generates once per attempt). A retry with the same key and body
returns the **original** response and never charges twice. The same key with a
different body returns `409 idempotency.key_reused`. Keys are remembered for 24
hours.

Generate the key when the shopkeeper taps "Charge", not on every retry, so a
dropped connection can be retried safely.

### There is no offline charge

A charge needs the server. When the link is down the register holds the lines
locally (see [Cart](cart.md#post-cartsync)) and payment is blocked until
connectivity returns. Nothing in this API lets a client mark a sale paid on its own
authority.

---

## 1. GET `/api/v1/payments/methods` — Which payment methods the shop accepts

**Purpose:** Builds the payment-method picker: which wallets the shop is paid on,
whether each is verified, and which rails can be offered right now. This is the
**payment-screen** view of the same data as
[`GET /merchant/wallets`](merchant.md#3-get-apiv1merchantwallets--list-the-payout-wallets-and-their-status),
which is the settings view. Replaces `GET /api/merchants/getPhoneNumbersStatus`.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "wallets": [
      { "rail": "zaad",   "number": "+252632222222", "status": "verified", "label": "Zaad" },
      { "rail": "edahab", "number": "+252651111111", "status": "verified", "label": "eDahab" },
      { "rail": "golis",  "number": null, "status": "not_set", "label": "Golis" },
      { "rail": "evc",    "number": null, "status": "not_set", "label": "EVC" }
    ],
    "accepts": ["zaad", "edahab", "cash"],
    "card": { "enabled": false, "provider": "braintree", "environment": "sandbox" }
  },
  "meta": { "request_id": "req_01JBXS0A1B", "server_time": "2026-09-20T09:00:00Z" }
}
```

| Field | Meaning |
| --- | --- |
| `wallets[]` | All four wallet rails, as on the settings screen |
| `accepts` | **The definitive list** of rails to show, in the order `zaad`, `edahab`, `cash`, `card`, `nfc`. `cash` is always present. `zaad` and `edahab` are present only when that wallet is `verified` (a `pending` or `not_set` wallet is left out). `card` and `nfc` appear only when switched on (`EXELO_CARD_ENABLED`, `EXELO_NFC_ENABLED`). Golis and EVC are stored but cannot take payments yet. |
| `card.environment` | `production` or `sandbox` |

> `card.environment` is exposed deliberately. The current Braintree integration is
> pinned to **Sandbox** in `functions/index.js`, so card payments may not be
> taking real money today. Showing it makes that impossible to miss.

Employees receive the same object.

## 2. POST `/api/v1/payments/quote` — Price a payment before charging

**Purpose:** What a given amount will cost the customer and yield the shop, before
committing to a charge, so the app can show why the total differs from the cart.
Replaces `POST /api/merchant/transaction/process`. Needs the `pos` permission.

**Request**

```json
{
  "amount": { "amount": 3774, "currency": "USD" },
  "rail": "zaad",
  "purpose": "pos_sale"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `amount` | Money | yes | The sale total |
| `rail` | enum | yes | `zaad` \| `edahab` \| `cash` \| `card` \| `nfc` |
| `purpose` | enum | yes | See [Purpose](#purpose) |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "quote_id": "qte_01JBXT2N9K",
    "amount":            { "amount": 3774, "currency": "USD", "display": "$37.74" },
    "customer_charge":   { "amount": 3850, "currency": "USD", "display": "$38.50" },
    "merchant_receives": { "amount": 3700, "currency": "USD", "display": "$37.00" },
    "fees": {
      "platform": { "amount": 108, "currency": "USD", "display": "$1.08" },
      "rail":     { "amount": 0, "currency": "USD", "display": "$0.00" }
    },
    "fee_payer": "merchant",
    "amount_alt":    { "amount": 301920, "currency": "SLSH", "display": "301,920 SLSH" },
    "exchange_rate": 8000,
    "expires_at": "2026-09-20T09:15:11Z"
  }
}
```

The example is a Zaad quote for `$37.74` on a Silver shop. The amounts differ on Gold
(see below).

| Field | Meaning |
| --- | --- |
| `amount` | What was asked for |
| `customer_charge` | What the customer is billed |
| `merchant_receives` | What lands in the shop |
| `fees.platform` | The one combined wallet fee: **2.85%** of `amount`, rounded to the nearest unit (`EXELO_WALLET_FEE_RATE`) |
| `fees.rail` | Always `0` for now; kept so a separate provider fee can be added without changing the response |
| `fee_payer` | `customer` on Gold, `merchant` on Silver; `null` when there is no fee |
| `amount_alt`, `exchange_rate` | The equivalent in the other currency, at the shop's own rate from [`GET /merchant/settings`](merchant.md#5-get-apiv1merchantsettings--get-the-shop-preferences). A USD amount shows SLSH; an SLSH amount shows USD. |
| `expires_at` | Quotes last 15 minutes and are kept server-side under `quote_id` |

**Who pays the fee** follows the shop's plan, the same rule as the legacy
`transaction/process`:

| Plan | `customer_charge` | `merchant_receives` |
| --- | --- | --- |
| Gold | `amount` + fee | `amount` |
| Silver (and any other plan) | `amount` | `amount` − fee |

`cash` quotes carry no fee on any plan: `customer_charge` and `merchant_receives`
both equal `amount`, and `fee_payer` is `null`.

The legacy response returned three loose strings (`total_customer_charge`,
`amount_sent_to_merchant`, `amount_in_dollars`) with no fee breakdown.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.permission_denied` | No `pos` permission |
| `422` | `payment.rail_unavailable` | The rail is not in [`accepts`](#1-get-apiv1paymentsmethods--which-payment-methods-the-shop-accepts): no verified wallet, or card/NFC not enabled (`error.details.available_rails`, `error.field: rail`) |
| `422` | `validation.failed` | Missing or malformed fields. `amount.currency` is `USD` or `SLSH`, `amount.amount` is a whole number of at least 1, and `purpose` is `pos_sale` or `order_settlement`. |

```json
{
  "success": false,
  "message": "That payment method is not available for this shop",
  "error": { "code": "payment.rail_unavailable", "field": "rail", "details": { "available_rails": ["cash"] } }
}
```

## 3. POST `/api/v1/payments/charges` — Start a payment

**Purpose:** Starts a payment on any rail. **Idempotent: `idempotency_key` is
required.** Needs the `pos` permission.

**Request**

```json
{
  "rail": "zaad",
  "purpose": "pos_sale",
  "amount": { "amount": 3774, "currency": "USD" },
  "quote_id": "qte_01JBXT2N9K",
  "customer": {
    "wallet_number": "+252634110101",
    "name": "Amina Yusuf"
  },
  "cart_id": 4471,
  "idempotency_key": "7d4e1b90-5c22-4a63-b8f1-92e0a7c45d38"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `rail` | enum | yes | `zaad` \| `edahab` \| `cash` \| `card` \| `nfc` |
| `purpose` | enum | yes | `pos_sale` or `order_settlement`. The other purposes are created by their own modules. |
| `amount` | Money | yes | The sale total in **USD** (`currency` is `USD`); must equal the ticket or order total, or `409 payment.cart_changed`. Must also match `quote_id` if given |
| `quote_id` | string | no | Locks the quoted fees |
| `customer.wallet_number` | string | On wallet rails | The number to bill; must belong to the rail |
| `customer.name` | string | no | Recorded on the receipt |
| `customer.mobile_number` | string | no | Recorded on the order |
| `cart_version` | int | no | The ticket `version` you saw; `409 payment.cart_changed` if it moved |
| `amount_tendered` | Money | no | Cash only, through [`/cart/pay`](cart.md#post-cartpay) and [`/orders/{id}/pay`](orders.md#5-post-apiv1ordersidpay--take-payment-for-a-pending-order): checked against the amount due (`422 payment.tender_too_low`) |
| `cart_id` | int | For `pos_sale` | The cart being settled |
| `order_id` | int | For `order_settlement` | The order being settled |
| `payment_nonce` | string | For `card` | Braintree nonce from the client SDK |
| `tag_payload` | string | For `nfc` | Encrypted tag read, see [NFC](nfc.md) |
| `idempotency_key` | string | yes | UUID, one per attempt |

**Response `202`: wallet rail, awaiting the customer**

```json
{
  "success": true,
  "message": "Ask the customer to approve the payment",
  "data": {
    "charge_id": "chg_01JBXT5P3R",
    "status": "pending",
    "rail": "zaad",
    "amount": { "amount": 3774, "currency": "USD", "display": "$37.74" },
    "next_action": "await_customer_approval",
    "poll_after": 3,
    "poll": "/api/v1/payments/charges/chg_01JBXT5P3R",
    "expires_at": "2026-09-20T09:06:11Z"
  }
}
```

`next_action` tells the app what to do:

| `next_action` | Client does |
| --- | --- |
| `await_customer_approval` | Show "Ask the customer to approve", poll `poll` every `poll_after` seconds |
| `confirm` | Ask for the customer's code, then call [`/confirm`](#5-post-apiv1paymentschargescharge_idconfirm--confirm-a-payment) |
| `none` | Already final; read `status` |

**`prompt: "declined"`** (eDahab only). If the customer turned the payment prompt
down on their phone, a pending charge also carries `"prompt": "declined"`, on this
`202` and on every poll of
[`GET /payments/charges/{charge_id}`](#4-get-apiv1paymentschargescharge_id--check-a-payment)
while it stays pending. The charge **stays `pending`**: eDahab keeps the invoice
open, so it could still be paid. Show "The customer declined on their phone" so
the shopkeeper isn't left guessing. The field is absent otherwise. See
[edahab.md](edahab.md).

A second charge for the same ticket or order still returns `409
payment.charge_pending` until the declined one expires (10 minutes), because
[cancel](#6-post-apiv1paymentschargescharge_idcancel--cancel-a-pending-payment) is
not built yet. Allowing a new charge would risk the customer paying both.

**Response `200`: cash, settled immediately**

```json
{
  "success": true,
  "message": "Sale complete",
  "data": {
    "charge_id": "chg_01JBXT6Q4S",
    "status": "paid",
    "rail": "cash",
    "paid_at": "2026-09-20T09:16:44Z",
    "order": { "id": 10246, "order_status": "Complete" },
    "receipt": { "invoice_no": "INV-10246", "url": "/api/v1/orders/10246/receipt" }
  }
}
```

When a `pos_sale` charge reaches `paid`, the server **creates the order and clears
the cart in one transaction**. The legacy client did this in three separate calls
(`transactionByCash`, then `placeOrder`, then a cart refresh), any of which could
fail on its own and leave a paid sale with no order attached.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `402` | `payment.declined` | `error.details.reason`: `insufficient_funds`, `wallet_blocked`, `limit_exceeded` |
| `403` | `auth.permission_denied` | No `pos` permission |
| `409` | `payment.cart_changed` | The cart `version` moved since the quote: re-quote |
| `409` | `idempotency.key_reused` | Same key, different body |
| `410` | `quote.expired` | The quote passed its `expires_at`: request a new one |
| `422` | `payment.rail_unavailable` | The shop has no verified wallet on that rail |
| `422` | `payment.wallet_invalid` | The customer number does not belong to that rail (`error.field: customer.wallet_number`) |
| `422` | `validation.failed` | Missing or malformed fields |
| `502` | `payment.provider_unavailable` | The wallet provider is down; nothing was charged |

```json
{
  "success": false,
  "message": "The customer's Zaad balance is too low",
  "error": { "code": "payment.declined", "details": { "reason": "insufficient_funds" } }
}
```

## 4. GET `/api/v1/payments/charges/{charge_id}` — Check a payment

**Purpose:** Polls a payment until it settles. Works for **any** charge of the
shop: a sale, a subscription, a registration or verification fee. Replaces
`POST /api/merchant/invoice/status`.

> **Live today for subscription payments** (`charge_id` like
> `inv_01M2WP34K5G80C669C3FG1WPMR`, see
> [subscription.md](subscription.md#5-get-apiv1paymentschargescharge_id--check-a-subscription-payment)).
> Sale charges (`chg_…`) arrive with the rest of this module.

**Response `200`: paid**

```json
{
  "success": true,
  "data": {
    "charge_id": "chg_01JBXT5P3R",
    "status": "paid",
    "rail": "zaad",
    "purpose": "pos_sale",
    "amount": { "amount": 3774, "currency": "USD", "display": "$37.74" },
    "customer": { "wallet_number": "+252634110101", "name": "Amina Yusuf" },
    "provider_ref": { "transaction_id": "ZD-88213441", "reference_id": "REF-90211" },
    "created_at": "2026-09-20T09:01:11Z",
    "paid_at": "2026-09-20T09:01:52Z",
    "order": { "id": 10246, "order_status": "Complete" },
    "receipt": { "invoice_no": "INV-10246", "url": "/api/v1/orders/10246/receipt" }
  }
}
```

**Response `200`: still waiting**

```json
{
  "success": true,
  "data": {
    "charge_id": "chg_01JBXT5P3R",
    "status": "pending",
    "rail": "zaad",
    "purpose": "pos_sale",
    "amount": { "amount": 3774, "currency": "USD", "display": "$37.74" },
    "poll_after": 3,
    "expires_at": "2026-09-20T09:06:11Z"
  }
}
```

**Response `200`: failed** (carries the reason)

```json
{
  "success": true,
  "data": {
    "charge_id": "chg_01JBXT5P3R",
    "status": "failed",
    "failure": { "code": "insufficient_funds", "message": "The customer's Zaad balance is too low" }
  }
}
```

`failure.code` is one of `insufficient_funds`, `wallet_blocked`, `limit_exceeded`,
`declined`, `provider_error`.

`poll_after` is returned while the payment is pending. Honour it: a POS on a weak
link polling every 500 ms makes its own connection worse. Stop polling at the
first final status ([Status lifecycle](#status-lifecycle)).

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `charge.not_found` | No such payment for this shop |

## 5. POST `/api/v1/payments/charges/{charge_id}/confirm` — Confirm a payment

**Purpose:** Confirms a charge that reported `next_action: "confirm"`. Replaces
`POST /api/zaad/commit`.

In v1 the server performs the Zaad commit itself, so this is needed only for a rail
that genuinely needs a second customer-side step. It is specified so such a rail can
be added without a new endpoint. Needs the `pos` permission.

**Request**

```json
{ "confirmation_code": "882134", "idempotency_key": "2c81f0a6-5b1d-4d0e-9d55-0c2a1f6e8a11" }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `confirmation_code` | string | yes | The code the customer received |
| `idempotency_key` | string | yes | UUID, one per attempt |

**Response `200`**: same body as
[`GET /payments/charges/{charge_id}`](#4-get-apiv1paymentschargescharge_id--check-a-payment).

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `charge.not_found` | No such payment for this shop |
| `409` | `payment.already_settled` | The payment is already final |
| `422` | `payment.code_invalid` | Wrong or expired confirmation code |

## 6. POST `/api/v1/payments/charges/{charge_id}/cancel` — Cancel a pending payment

**Purpose:** Abandons a pending payment: the shopkeeper backed out, or the customer
walked away. The cart is left intact so the shopkeeper can retry on another rail.
Needs the `pos` permission.

**Request:** no body.

**Response `200`**

```json
{
  "success": true,
  "message": "Payment cancelled",
  "data": { "charge_id": "chg_01JBXT5P3R", "status": "cancelled" }
}
```

Cancelling an already-cancelled payment returns the same `200`.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `charge.not_found` | No such payment for this shop |
| `409` | `payment.already_settled` | It paid, failed or expired in the meantime; read its status with [`GET`](#4-get-apiv1paymentschargescharge_id--check-a-payment) |

## 7. POST `/api/v1/payments/payouts` — Send money to a phone number

**Purpose:** The shop sends money to a phone number, for example a supplier
payment. Replaces `POST /api/merchant/make-payment`. Needs the `pos` permission
**and** a fresh PIN confirmation.

The legacy endpoint was **unauthenticated** and took a bare `phoneNumber` plus
`transactionAmount`. An endpoint that moves money out of a shop's balance must
require a signed-in user, the right permission and the PIN, so v1 does.

**Headers:** `X-EXELO-Confirmation: <token>` from
[`POST /auth/pin/verify`](auth.md) with `scope: "payments.payout"`. It is
single-use and expires after 5 minutes.

**Request**

```json
{
  "phone_number": "+252634110101",
  "amount": { "amount": 5000, "currency": "SLSH" },
  "rail": "edahab",
  "note": "Supplier payment",
  "idempotency_key": "a0f7b3d1-6c44-4e0a-8b21-3f5d90c7e112"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `phone_number` | string | yes | Recipient; must belong to `rail` |
| `amount` | Money | yes | `USD` or `SLSH` |
| `rail` | enum | yes | `zaad` \| `edahab` |
| `note` | string | no | Up to 100 characters, kept on the payout record |
| `idempotency_key` | string | yes | UUID, one per attempt |

**Response `200`**

```json
{
  "success": true,
  "message": "5,000 SLSH sent to +252 63 411 0101",
  "data": {
    "payout_id": "pay_01JBXTB7W2",
    "status": "sent",
    "amount": { "amount": 5000, "currency": "SLSH", "display": "5,000 SLSH" },
    "phone_number": "+252634110101",
    "provider_ref": "ED-771230",
    "sent_at": "2026-09-20T09:20:03Z"
  }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.confirmation_required` | The PIN confirmation is missing, used or expired: ask for the PIN again |
| `402` | `payment.declined` | The provider refused (`error.details.reason`) |
| `403` | `auth.permission_denied` | No `pos` permission |
| `409` | `idempotency.key_reused` | Same key, different body |
| `422` | `payment.wallet_invalid` | The number does not belong to that rail |
| `502` | `payment.provider_unavailable` | The provider is down; check before retrying with a new key |

A refused request does not spend the PIN confirmation, so the shopkeeper can fix
the number and resubmit.

## 8. POST `/api/v1/payments/card/session` — Start a card payment

**Purpose:** Returns a Braintree client token so the app can tokenise a card.
Replaces the `generateClientToken` Cloud Function. Needs the `pos` permission.

**Request:** no body.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "provider": "braintree",
    "client_token": "eyJ2ZXJzaW9uIjoyLCJhdXRob3...",
    "environment": "production",
    "expires_at": "2026-09-20T09:36:11Z"
  }
}
```

The app tokenises the card with this token, then sends the resulting nonce as
`payment_nonce` to [`POST /payments/charges`](#3-post-apiv1paymentscharges--start-a-payment)
with `rail: "card"`. The `processPayment` Cloud Function is retired.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `422` | `payment.rail_unavailable` | Card payments are not enabled for this shop |
| `502` | `payment.provider_unavailable` | Braintree is unreachable |

> **Migration note.** Braintree credentials are currently committed to the repo as
> literal fallbacks in `functions/index.js` and in `.env`. Every one must be rotated
> when card payments move behind this endpoint, and the Sandbox or Production
> decision made explicitly.

## 9. POST `/api/v1/webhooks/payments/{provider}` — Provider callbacks

**Purpose:** Lets a wallet provider tell the server a payment settled, so the app
does not have to poll aggressively. **Not called by the app.**

`{provider}` is `zaad`, `edahab` or `braintree`.

**Headers**

| Header | Notes |
| --- | --- |
| `X-EXELO-Signature` | HMAC-SHA256 of the raw body. Reject on mismatch. |
| `X-EXELO-Timestamp` | Reject if older than 5 minutes (replay protection) |

**Request:** provider-shaped, normalised internally to

```json
{
  "event": "charge.paid",
  "charge_id": "chg_01JBXT5P3R",
  "provider_ref": { "transaction_id": "ZD-88213441" },
  "status": "paid",
  "occurred_at": "2026-09-20T09:01:52Z"
}
```

**Response `200`**

```json
{ "success": true }
```

A non-2xx response makes the provider retry.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `webhook.signature_invalid` | Bad signature, or a timestamp older than 5 minutes |
| `404` | `not_found` | Unknown `{provider}` |

Webhook handling is idempotent: the same event may arrive more than once, and it
races the app's polling. Whichever arrives first settles the charge; the second is
a no-op. Unknown `charge_id`s are acknowledged with `200` so the provider stops
retrying.

---

## Step by step: taking a Zaad payment at the till

```
GET  /payments/methods                  → "zaad" is in accepts
POST /payments/quote                    → customer_charge, fees, expires_at
POST /payments/charges  { rail: "zaad", customer.wallet_number, quote_id, cart_id, idempotency_key }
                                        → 202, status "pending", poll_after: 3
GET  /payments/charges/{charge_id}      → repeat every poll_after seconds
                                        → status "paid", order + receipt        (done)
                                        → status "failed", failure.code         (offer another rail)
POST /payments/charges/{charge_id}/cancel → shopkeeper backs out; cart is kept
```

## Step by step: cash sale

```
POST /payments/charges  { rail: "cash", purpose: "pos_sale", cart_id, amount, idempotency_key }
                                        → 200, status "paid", order + receipt   (done)
```

## Step by step: paying a supplier

```
POST /auth/pin/verify   { pin, scope: "payments.payout" }  → confirmation_token
POST /payments/payouts  X-EXELO-Confirmation: <token>       → 200, status "sent"
```

| State | Client does |
| --- | --- |
| `202 pending` | Show the waiting screen and poll |
| `paid` | Show the receipt, clear the local cart |
| `failed` | Show `failure.message`, offer another rail |
| `expired` | Offer to try again |
| `409 payment.cart_changed` | Re-quote, then charge again |
| `410 quote.expired` | Re-quote, then charge again |
| `422 payment.rail_unavailable` | Remove that rail from the picker and refresh [`/payments/methods`](#1-get-apiv1paymentsmethods--which-payment-methods-the-shop-accepts) |
| `502 payment.provider_unavailable` | Nothing was charged; offer cash or retry |
| No network | Keep the cart locally; payment stays blocked until online |

---

## Postman / curl quick start

```bash
BASE=https://your-host/api/v1

curl $BASE/payments/methods -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X POST $BASE/payments/quote -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"amount":{"amount":3774,"currency":"USD"},"rail":"zaad","purpose":"pos_sale"}'

curl -X POST $BASE/payments/charges -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"rail":"cash","purpose":"pos_sale","amount":{"amount":3774,"currency":"USD"},"cart_id":4471,"idempotency_key":"7d4e1b90-5c22-4a63-b8f1-92e0a7c45d38"}'

curl $BASE/payments/charges/chg_01JBXT5P3R -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X POST $BASE/payments/charges/chg_01JBXT5P3R/cancel -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X POST $BASE/payments/payouts -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -H "X-EXELO-Confirmation: $CONFIRMATION" \
  -d '{"phone_number":"+252651110101","amount":{"amount":5000,"currency":"SLSH"},"rail":"edahab","idempotency_key":"a0f7b3d1-6c44-4e0a-8b21-3f5d90c7e112"}'
```

To test a wallet payment on a local machine without a real handset, set
`EXELO_SIMULATE_PAYMENTS=true` (with `APP_ENV=local`). The existing
`POST /registration/invoices/{id}/simulate-payment` helper already works this way
for subscription and registration payments, and the same switch is planned for
sale charges.

---

## Implementation notes

Endpoints 1, 2, 3 and 4 are built. Endpoints 5 to 9 (`confirm`, `cancel`, payouts,
card session and webhooks) are not. How the built part works, and what is open:

- **A sale charge is an `invoices` row** of type `Sale`, the same table that holds
  registration, verification and subscription payments. A migration adds
  `purpose`, `cart_id`, `cart_version` and `meta` (customer, the ticket lines, and
  the USD amounts). Sale ids start with `chg_`, subscription ids with `inv_`, and
  both resolve through `GET /payments/charges/{charge_id}`, which only returns
  payments of the caller's own shop.
- **What a charge does.** `POST /payments/charges` (or `/cart/pay`,
  `/orders/{id}/pay`, which call it) checks the rail against
  [`accepts`](#1-get-apiv1paymentsmethods--which-payment-methods-the-shop-accepts),
  checks that `amount` (USD) equals the sale total, and works out the fee. For
  **cash** it records the charge as paid at once. For **Zaad and eDahab** it asks the
  provider for a payment and returns `202`; the sale completes when a poll finds it
  paid.
- **On `paid`** (cash immediately, wallets on the poll that sees it) one
  transaction creates the order (`pos_sale`) or settles it (`order_settlement`),
  takes the stock off the shelf and clears the ticket. It runs once however many
  times the charge is polled. The ticket is only cleared if it has not changed since
  the charge started, so a new sale started meanwhile is never wiped.
- **Currency.** The order and its total are always USD. A **wallet** charge is billed
  in **SLSH** at the shop's exchange rate (`customer_charge` in cents × rate ÷ 100,
  in whole shillings), as registration and subscription payments already are; a
  **cash** charge is recorded in USD. So `amount.currency` on a polled charge is
  `SLSH` for wallets and `USD` for cash, and `customer_charge` (USD) is returned
  when the charge is created.
- **The fee** is the quote's: one 2.85% wallet fee, added to what the customer pays
  on Gold and taken from the shop otherwise; none on cash. Sending `quote_id` locks
  the quoted fee and must match the rail, purpose and amount (else `410
  quote.expired` or `422`).
- **One open payment per sale.** While a wallet payment for the same ticket or order
  is waiting for the customer, another returns `409 payment.charge_pending` with the
  waiting `charge_id`. An expired one no longer blocks.
- **Known gap: cancelling a wallet payment** is not built. Until the providers
  offer a cancel call, a shopkeeper who abandons a waiting payment must let it
  expire (10 minutes) rather than start a second one.
- **Built code:** `PaymentService` (methods, quote, fee), `ChargeService` (charges,
  order creation on paid), `V1\PaymentController`, `FinalizeSaleOnPaid` (the
  `InvoicePaid` listener), and the `payments` settings in `config/exelo.php`
  (`wallet_fee_rate`, card and NFC switches).
- **Card and NFC are off** until their modules exist. Even if switched on they are
  refused by charges (`422 payment.rail_unavailable`) for now.
- **Idempotency** uses the same cache-lock approach as `subscription/change`, with
  the response stored for 24 hours.
- **Payout confirmation** reuses the single-use PIN confirmation, adding the scope
  `payments.payout`. It is the same mechanism as `employees.create`.
- **Webhooks** need a shared secret per provider in `config/exelo.php`. Whether
  Zaad and eDahab can actually call a webhook (both are polled today) must be
  confirmed with the providers; if not, polling stays the only path and endpoint 9
  can wait.
- **Card** depends on the Braintree Sandbox or Production decision and on rotating
  the credentials listed in the migration note, so it is the last rail to build.
- **Error codes** are in [errors.md](errors.md). Added for the built endpoints:
  `payment.charge_pending`, `payment.tender_too_low`, `payment.cart_changed`,
  `quote.expired`, `cart.not_found`. Still to add with the unbuilt endpoints:
  `payment.already_settled`, `payment.code_invalid`, `webhook.signature_invalid`.
- **Legacy routes** (`POST /api/merchant/transaction/process`,
  `POST /api/merchant/invoice/status`, `POST /api/zaad/commit`,
  `POST /api/merchant/make-payment`) keep working alongside until the app moves.
