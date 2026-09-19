# Payments & Wallets

One resource — a **charge** — covers every way money moves into the shop: Zaad,
eDahab, cash, card and NFC. The rail is a field, not a different endpoint.

This replaces the largest duplication in the legacy API. Today there are
parallel near-identical flows for each wallet, split again between Gold
(`payments.dart`) and Silver (`paymentsforsmall.dart`), with two endpoints that
differ only by a `/zaad` path segment, plus `transaction/process` reused for the
signup fee, the PIN-reset fee, subscription upgrades and POS sales with only a
`type` string to tell them apart.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/payments/methods`](#get-paymentsmethods) | Bearer |
| POST | [`/payments/quote`](#post-paymentsquote) | Bearer |
| POST | [`/payments/charges`](#post-paymentscharges) | Bearer |
| GET | [`/payments/charges/{id}`](#get-paymentschargesid) | Bearer |
| POST | [`/payments/charges/{id}/confirm`](#post-paymentschargesidconfirm) | Bearer |
| POST | [`/payments/charges/{id}/cancel`](#post-paymentschargesidcancel) | Bearer |
| POST | [`/payments/payouts`](#post-paymentspayouts) | Bearer |
| POST | [`/payments/card/session`](#post-paymentscardsession) | Bearer |
| POST | [`/webhooks/payments/{provider}`](#post-webhookspaymentsprovider) | Signature |

---

## Concepts

**Rail** — how the money moves.

| Rail | Flow | Notes |
| --- | --- | --- |
| `zaad` | Two-step: issue then commit | Server owns the commit; client only polls |
| `edahab` | Push prompt to handset | Customer approves on their phone |
| `cash` | Recorded, not routed | Settles instantly |
| `card` | Braintree | Replaces the direct Cloud Function calls |
| `nfc` | Tag read, then routed | See [NFC](nfc.md) |

**Purpose** — what the money is for. Determines fee treatment and what the
charge settles against.

| Purpose | Used by |
| --- | --- |
| `pos_sale` | Register checkout |
| `order_settlement` | Paying an existing pending order |
| `registration` | Signup fee (unauthenticated — see [Registration](registration.md)) |
| `verification` | Wallet verification fee |
| `subscription` | Plan upgrade |
| `pin_reset` | PIN reset fee, if retained |

**Status lifecycle**

```
pending ──► authorized ──► paid
   │                        │
   ├──► failed              └──► refunded
   ├──► expired
   └──► cancelled
```

---

## GET /payments/methods

Which wallets the merchant can be paid on, and whether each is verified.
Replaces `GET /api/merchants/getPhoneNumbersStatus`.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "wallets": [
      { "rail": "zaad",   "number": "+252632222222", "status": "verified", "label": "Zaad" },
      { "rail": "edahab", "number": "+252631111111", "status": "verified", "label": "eDahab" },
      { "rail": "golis",  "number": null, "status": "not_set", "label": "Golis" },
      { "rail": "evc",    "number": null, "status": "not_set", "label": "EVC" }
    ],
    "accepts": ["zaad", "edahab", "cash", "card", "nfc"],
    "card": { "enabled": true, "provider": "braintree", "environment": "production" }
  }
}
```

`accepts` is the definitive list for the payment-method picker. The legacy
response gave only a per-wallet `status` string and the client inferred the
rest.

> `card.environment` is exposed deliberately. The current Braintree integration
> is pinned to **Sandbox** in `functions/index.js`, which means card payments
> may not be taking real money today. Surfacing it makes that impossible to
> miss.

---

## POST /payments/quote

What a given amount will cost the customer and yield the merchant, before
committing to a charge. Replaces
`POST /api/merchant/transaction/process`.

**Request**

```json
{
  "amount": { "amount": 3774, "currency": "USD" },
  "rail": "zaad",
  "purpose": "pos_sale"
}
```

**Response `200`**

```json
{
  "success": true,
  "data": {
    "quote_id": "qte_01JBXT2N9K",
    "amount":           { "amount": 3774, "currency": "USD", "display": "$37.74" },
    "customer_charge":  { "amount": 3850, "currency": "USD", "display": "$38.50" },
    "merchant_receives":{ "amount": 3700, "currency": "USD", "display": "$37.00" },
    "fees": {
      "platform": { "amount": 74, "currency": "USD", "display": "$0.74" },
      "rail":     { "amount": 76, "currency": "USD", "display": "$0.76" }
    },
    "amount_alt": { "amount": 301920, "currency": "SLSH", "display": "301,920 SLSH" },
    "exchange_rate": 8000,
    "expires_at": "2026-09-18T14:21:11Z"
  }
}
```

The legacy response returned three loose strings — `total_customer_charge`,
`amount_sent_to_merchant`, `amount_in_dollars` — with no fee breakdown, so the
app could not show a shopper why the total differed from the cart.

---

## POST /payments/charges

Starts a charge on any rail. **Idempotent — key required.**

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
| `purpose` | enum | yes | See [Concepts](#concepts) |
| `amount` | Money | yes | Must match `quote_id` if given |
| `quote_id` | string | no | Locks the quoted fees |
| `customer.wallet_number` | string | On wallet rails | Number to bill |
| `customer.name` | string | no | Recorded on the receipt |
| `cart_id` | int | For `pos_sale` | Cart being settled |
| `order_id` | int | For `order_settlement` | Order being settled |
| `payment_nonce` | string | For `card` | Braintree nonce from the client SDK |
| `tag_payload` | string | For `nfc` | Encrypted tag read — see [NFC](nfc.md) |
| `idempotency_key` | string | yes | |

**Response `202` — wallet rail, awaiting the customer**

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
    "expires_at": "2026-09-18T14:26:11Z"
  }
}
```

**Response `200` — cash, settled immediately**

```json
{
  "success": true,
  "message": "Sale complete",
  "data": {
    "charge_id": "chg_01JBXT6Q4S",
    "status": "paid",
    "rail": "cash",
    "paid_at": "2026-09-18T14:16:44Z",
    "order": { "id": 10246, "order_status": "Complete" },
    "receipt": { "invoice_no": "INV-10246", "url": "/api/v1/orders/10246/receipt" }
  }
}
```

When a `pos_sale` charge reaches `paid`, the server **creates the order and
clears the cart atomically**. The legacy client did this in three separate
calls (`transactionByCash`, then `placeOrder`, then a cart refresh), any of
which could fail independently and leave a paid sale with no order attached.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `402` | `payment.declined` | `error.details.reason`: `insufficient_funds`, `wallet_blocked`, … |
| `409` | `payment.cart_changed` | Cart `version` moved since the quote — re-quote |
| `409` | `idempotency.key_reused` | Same key, different body |
| `410` | `quote.expired` | |
| `422` | `payment.rail_unavailable` | Merchant has no verified wallet on that rail |
| `502` | `payment.provider_unavailable` | |

### Offline

There is **no offline charge.** A charge requires the server. When the link is
down the register holds lines locally (see [Cart](cart.md#post-cartsync)) and
payment is blocked until connectivity returns. Nothing in this API allows a
client to mark a sale paid on its own authority.

---

## GET /payments/charges/{id}

Polls a charge. Replaces `POST /api/merchant/invoice/status`.

**Response `200`**

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
    "created_at": "2026-09-18T14:16:11Z",
    "paid_at": "2026-09-18T14:16:52Z",
    "order": { "id": 10246, "order_status": "Complete" },
    "receipt": { "invoice_no": "INV-10246", "url": "/api/v1/orders/10246/receipt" }
  }
}
```

Failed charges carry the reason:

```json
{
  "data": {
    "charge_id": "chg_01JBXT5P3R",
    "status": "failed",
    "failure": { "code": "insufficient_funds", "message": "The customer's Zaad balance is too low" }
  }
}
```

`poll_after` is returned while pending. Honour it — a POS on a weak link
polling every 500 ms makes its own connection worse.

---

## POST /payments/charges/{id}/confirm

Confirms a charge that reported `next_action: "confirm"`. Replaces
`POST /api/zaad/commit`.

In v1 the server performs the Zaad commit itself, so this is needed only for
rails that genuinely require a second customer-side step. It is specified so
such a rail can be added without a new endpoint.

**Request**

```json
{ "confirmation_code": "882134", "idempotency_key": "2c81f0a6-..." }
```

**Response `200`** — same body as
[`GET /payments/charges/{id}`](#get-paymentschargesid).

---

## POST /payments/charges/{id}/cancel

Abandons a pending charge — the shopkeeper backed out, or the customer walked
away.

**Response `200`**

```json
{
  "success": true,
  "message": "Payment cancelled",
  "data": { "charge_id": "chg_01JBXT5P3R", "status": "cancelled" }
}
```

Returns `409 payment.already_settled` if it paid in the meantime. The cart is
left intact so the shopkeeper can retry on another rail.

---

## POST /payments/payouts

Merchant sends money to a phone number. Replaces
`POST /api/merchant/make-payment`.

**Request**

```json
{
  "phone_number": "+252634110101",
  "amount": { "amount": 5000, "currency": "SLSH" },
  "rail": "edahab",
  "note": "Supplier payment",
  "idempotency_key": "a0f7b3d1-..."
}
```

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
    "sent_at": "2026-09-18T14:20:03Z"
  }
}
```

The legacy endpoint was **unauthenticated** and took a bare `phoneNumber` plus
`transactionAmount`. An endpoint that moves money out of a merchant's balance
must require a Bearer token and the `pos` permission; v1 does.

---

## POST /payments/card/session

Returns a Braintree client token so the app can tokenise a card. Replaces the
`generateClientToken` Cloud Function.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "provider": "braintree",
    "client_token": "eyJ2ZXJzaW9uIjoyLCJhdXRob3...",
    "environment": "production",
    "expires_at": "2026-09-18T14:36:11Z"
  }
}
```

The app tokenises with this, then sends the resulting nonce as `payment_nonce`
to [`POST /payments/charges`](#post-paymentscharges) with `rail: "card"`. The
`processPayment` Cloud Function is retired.

> **Migration note.** Braintree credentials are currently committed to the repo
> as literal fallbacks in `functions/index.js` and in `.env`. Every one must be
> rotated when card payments move behind this endpoint, and the Sandbox/
> Production decision made explicitly.

---

## POST /webhooks/payments/{provider}

Provider callbacks. Not called by the app.

`{provider}` is `zaad`, `edahab` or `braintree`.

**Headers**

| Header | Notes |
| --- | --- |
| `X-EXELO-Signature` | HMAC-SHA256 of the raw body. Reject on mismatch. |
| `X-EXELO-Timestamp` | Reject if older than 5 minutes (replay protection) |

**Request** — provider-shaped, normalised internally to:

```json
{
  "event": "charge.paid",
  "charge_id": "chg_01JBXT5P3R",
  "provider_ref": { "transaction_id": "ZD-88213441" },
  "status": "paid",
  "occurred_at": "2026-09-18T14:16:52Z"
}
```

**Response `200`** — `{ "success": true }`. Non-2xx makes the provider retry.

Webhook handling must be idempotent: the same event may arrive more than once,
and it races the client's polling. Whichever arrives first settles the charge;
the second is a no-op.

Adding webhooks is what lets the client stop polling aggressively, which
matters more here than on a fast network.
