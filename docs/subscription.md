# Subscription & Plans

EXELO ships two tiers. **Gold** is the full POS; **Silver** is the free reduced
dashboard and the default plan. The app used to decide which UI to show by
comparing `subscription_plan_id == "2"` — a magic string compiled into the
client. v1 returns a stable plan `key` and a `features` list instead, so a third
tier never requires an app release. **Gate the UI on `features`, not on `id` or
`name`.**

| Method | Path | Auth |
| --- | --- | --- |
| GET | `/plans` | — |
| GET | `/subscription` | Bearer |
| POST | `/subscription/change` | Bearer, shop owner |
| POST | `/subscription/cancel` | Bearer, shop owner |
| GET | `/payments/charges/{charge_id}` | Bearer |

---

## Complete endpoint list

Full URL = `{BASE_URL}/api/v1` + path.

**Headers**

| Header | Sent on | Value |
| --- | --- | --- |
| `Accept` | Every request | `application/json` |
| `Content-Type` | Requests with a body | `application/json` |
| `Authorization` | Every row except `GET /plans` | `Bearer <token>` from [`POST /auth/pin/login`](auth.md#post-authpinlogin) |
| `Idempotency-Key` | `POST /subscription/change` (alternative to the body field) | UUID generated once per Pay tap |

| # | Method | Full path | Purpose | Auth | Body / query | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | GET | `/api/v1/plans` | List the plans | — | — | `200` | — |
| 2 | GET | `/api/v1/subscription` | Get the current plan | Bearer (owner or employee) | — | `200` | `401`, `404 merchant.not_found` |
| 3 | POST | `/api/v1/subscription/change` | Upgrade, renew or downgrade | Bearer, owner only | `plan_id`, `idempotency_key`; on upgrade: `rail`, `wallet_number` | `202` (payment needed) or `200` (downgrade scheduled) | `403 auth.merchant_only`, `409 subscription.already_on_plan`, `409 subscription.change_pending`, `409 subscription.charge_closed`, `422 subscription.plan_unavailable`, `422 payment.wallet_invalid`, `422 payment.rail_unavailable`, `422 validation.failed`, `502 payment.provider_unavailable` |
| 4 | POST | `/api/v1/subscription/cancel` | Cancel at period end | Bearer, owner only | optional `reason`, `comment` | `200` | `403 auth.merchant_only`, `409 subscription.already_cancelled`, `409 subscription.nothing_to_cancel`, `422 validation.failed` |
| 5 | GET | `/api/v1/payments/charges/{charge_id}` | Check a subscription payment | Bearer | — | `200` | `404 charge.not_found` |

**Shared status codes**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.token_invalid` | Missing, revoked or expired token — clear the session |
| `403` | `auth.merchant_only` | An employee tried to change or cancel the plan |
| `422` | `validation.failed` | Per-field messages in `error.details` |
| `429` | `rate_limited` | 30 requests per minute per IP on these endpoints |

**Response envelope.** Every response carries `success`, `message`, `data` (or
`error`) and `meta.request_id` / `meta.server_time`. Branch on `error.code`,
never on `message`. See [errors.md](errors.md).

---

## Concepts

### Status

| `status` | Meaning | Client behaviour |
| --- | --- | --- |
| `active` | Paid and current (the default plan is always `active`) | Full access for the plan |
| `grace` | Expired, still usable for `SUBSCRIPTION_GRACE_DAYS` (default 5) | Show a banner with `grace.ends_at` and offer to renew |
| `cancelled` | Cancelled by the owner, still valid until `expires_at` | Keep access, then drop to Silver at `expires_at` |
| `expired` | Lapsed | `features` are Silver's; `plan` still names the lapsed plan so the app can offer to renew |

`past_due` and automatic renewal are not produced: a wallet payment needs the
customer's approval, so `renews_automatically` is always `false`.

Grace matters because a shop can be offline for days; a plan should not
hard-expire while the merchant has no way to pay.

### Rails

| `rail` | Behaviour |
| --- | --- |
| `zaad`, `edahab` | A payment prompt is sent to `wallet_number`; the customer approves on their phone. The wallet number must belong to that rail. |
| `cash` | No wallet is contacted. The shop pays EXELO staff, and staff confirm receipt in the admin panel. See [Paying by cash](#paying-by-cash). |
| `card` | Returns `422 payment.rail_unavailable` until card payments exist. |

### Upgrade, renewal and downgrade

- **Upgrade** (Silver → Gold) returns a payment to settle. The plan starts when
  the payment is **paid**, not before.
- **Renewal** is sending the shop's current paid plan again. It is allowed within
  7 days of expiry, during grace, after expiry, or after cancelling; earlier than
  that it returns `409 subscription.already_on_plan`. Renewing before expiry
  continues from the old end date.
- **Downgrade** (Gold → Silver) never charges. It applies at the end of the
  current period, not immediately.

---

## 1. GET `/api/v1/plans` — List the plans

**Purpose:** The plan catalogue for the upgrade screen. Public, so it can be
shown before sign-in.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "plans": [
      {
        "id": 2,
        "key": "silver",
        "name": "Silver",
        "price": { "amount": 0, "currency": "SLSH", "display": "Free" },
        "billing_period": "monthly",
        "features": ["dashboard.basic", "inventory.read", "payments.request"],
        "is_default": true
      },
      {
        "id": 1,
        "key": "gold",
        "name": "Gold",
        "price": { "amount": 92000, "currency": "SLSH", "display": "92,000 SLSH" },
        "price_alt": { "amount": 1150, "currency": "USD", "display": "$11.50" },
        "billing_period": "monthly",
        "features": [
          "dashboard.full", "pos.register", "pos.scanning", "inventory.write",
          "inventory.transfers", "employees.manage", "reports.full",
          "nfc.payments", "offline.mode"
        ],
        "is_default": false
      }
    ]
  },
  "meta": { "request_id": "req_01M2WP34JD07E0F5P9F284GFZZ", "server_time": "2026-09-19T11:16:14Z" }
}
```

Plans are ordered cheapest first. `price_alt` (USD, in cents) is absent on a free
plan.

## 2. GET `/api/v1/subscription` — Get the current plan

**Purpose:** The shop's current plan, status and features. It is also returned
inside the login response and [`GET /auth/session`](auth.md#get-authsession), so
this endpoint exists to **refresh**, not to boot. Employees see the shop's plan.
Reading never creates or changes anything: a shop with no plan row is simply on
Silver.

**Headers:** `Authorization: Bearer <token>`

**Response `200` — Silver (default plan)**

```json
{
  "success": true,
  "message": "You are currently on the Silver package.",
  "data": {
    "plan_id": 2,
    "plan": "silver",
    "plan_name": "Silver",
    "status": "active",
    "features": ["dashboard.basic", "inventory.read", "payments.request"],
    "started_at": "2026-09-19T00:00:00Z",
    "expires_at": null,
    "renews_automatically": false,
    "can_upgrade": true,
    "can_downgrade": false,
    "resubscribe_eligible": false,
    "grace": null
  }
}
```

**Response `200` — Gold, active**

```json
{
  "success": true,
  "message": "You are currently on the Gold package.",
  "data": {
    "plan_id": 1,
    "plan": "gold",
    "plan_name": "Gold",
    "status": "active",
    "features": [
      "dashboard.full", "pos.register", "pos.scanning", "inventory.write",
      "inventory.transfers", "employees.manage", "reports.full",
      "nfc.payments", "offline.mode"
    ],
    "started_at": "2026-09-19T00:00:00Z",
    "expires_at": "2026-10-19T23:59:59Z",
    "renews_automatically": false,
    "can_upgrade": false,
    "can_downgrade": true,
    "resubscribe_eligible": false,
    "grace": null
  }
}
```

**Response `200` — Gold, cancelled (still valid)**

```json
{
  "success": true,
  "message": "Cancelled. You keep Gold until 22 October.",
  "data": {
    "plan_id": 1,
    "plan": "gold",
    "plan_name": "Gold",
    "status": "cancelled",
    "features": ["dashboard.full", "pos.register", "…"],
    "started_at": "2026-09-19T00:00:00Z",
    "expires_at": "2026-10-22T23:59:59Z",
    "renews_automatically": false,
    "can_upgrade": false,
    "can_downgrade": false,
    "resubscribe_eligible": true,
    "grace": null
  }
}
```

**During grace**, `status` is `grace` and `grace` carries the deadline:

```json
{ "grace": { "ends_at": "2026-10-07T23:59:59Z", "days_remaining": 3 } }
```

| Field | Notes |
| --- | --- |
| `features` | Features that apply **now**. After `expired` these are the default plan's. |
| `can_upgrade` / `can_downgrade` | Whether a higher or lower plan is available. `can_downgrade` is `false` once a downgrade is scheduled or the plan is cancelled. |
| `resubscribe_eligible` | `true` when renewing is allowed right now (see [Renewal](#upgrade-renewal-and-downgrade)). |

**Offline behaviour.** The client caches the last known plan and keeps using it
when this call fails. A shop must never be downgraded mid-sale because the link
dropped. Only a `401` invalidates the cache.

## 3. POST `/api/v1/subscription/change` — Upgrade, renew or downgrade

**Purpose:** Change what the shop pays for. An upgrade or renewal returns a
payment to settle (`202`); a downgrade is scheduled for the end of the period
(`200`). Replaces `POST /api/merchants/subscriptions`, which granted a plan
without any payment.

**Headers:** `Authorization: Bearer <token>` (shop owner only)

**Request — upgrade paid from a wallet**

```json
{
  "plan_id": 1,
  "rail": "edahab",
  "wallet_number": "+252654990001",
  "idempotency_key": "f92d1c07-3b4a-4f88-8a21-6d5e9c1b4477"
}
```

**Request — upgrade paid in cash**

```json
{ "plan_id": 1, "rail": "cash", "idempotency_key": "c2a91f70-5b3d-4e0a-9c11-7d2e8b4f1a55" }
```

**Request — downgrade**

```json
{ "plan_id": 2, "idempotency_key": "9d4e1b90-5c22-4a63-b8f1-92e0a7c45d38" }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `plan_id` | int | yes | Target plan, from `GET /plans` |
| `rail` | enum | On upgrade or renewal | `zaad` \| `edahab` \| `cash` \| `card` |
| `wallet_number` | string | On `zaad` and `edahab` | Which number to bill. Not used for `cash`. |
| `idempotency_key` | string | yes | Generate once per tap and reuse on retries. Also accepted as the `Idempotency-Key` header. |

**Response `202` — upgrade or renewal, wallet payment required**

```json
{
  "success": true,
  "message": "Approve the payment on your phone to start Gold",
  "data": {
    "status": "payment_required",
    "charge_id": "inv_01M2WP34K5G80C669C3FG1WPMR",
    "rail": "edahab",
    "amount": { "amount": 92000, "currency": "SLSH", "display": "92,000 SLSH" },
    "next_action": "await_customer_approval",
    "poll": "/api/v1/payments/charges/inv_01M2WP34K5G80C669C3FG1WPMR",
    "poll_after": 3,
    "expires_at": "2026-09-19T11:26:14Z",
    "applies_on_payment": true
  }
}
```

The client then polls
[`GET /payments/charges/{id}`](#5-get-apiv1paymentschargescharge_id--check-a-subscription-payment).
On `paid`, re-fetch `GET /subscription` — the plan has flipped. Repeating the
request with the same `idempotency_key` returns the same `charge_id` instead of
sending a second prompt.

**Response `202` — cash**

```json
{
  "success": true,
  "message": "Pay 92,000 SLSH in cash to an EXELO agent. Gold starts once they confirm.",
  "data": {
    "status": "payment_required",
    "charge_id": "inv_01M2WP34NN7RCAC0DXB4BCTPDX",
    "rail": "cash",
    "amount": { "amount": 92000, "currency": "SLSH", "display": "92,000 SLSH" },
    "next_action": "await_cash_confirmation",
    "poll": "/api/v1/payments/charges/inv_01M2WP34NN7RCAC0DXB4BCTPDX",
    "poll_after": 3,
    "expires_at": "2026-09-22T11:16:14Z",
    "applies_on_payment": true
  }
}
```

**Response `200` — retry of a payment that is already paid**

Sending the same `idempotency_key` again returns where that payment stands now.
If it was already settled, the plan is active and the response says so instead of
asking for the payment again:

```json
{
  "success": true,
  "message": "This payment was already received. Gold is active.",
  "data": {
    "status": "paid",
    "charge_id": "inv_01M2WPXR1VTEYGVRS9ECF7TNWF",
    "rail": "edahab",
    "amount": { "amount": 92000, "currency": "SLSH", "display": "92,000 SLSH" },
    "paid_at": "2026-09-19T11:31:08Z",
    "applies_on_payment": false
  }
}
```

A retry while the payment is still open returns the original `202` (same
`charge_id`); the app should keep polling. Re-fetch `GET /subscription` to see
the plan.

**Response `200` — downgrade, scheduled**

```json
{
  "success": true,
  "message": "You will move to Silver on 19 October",
  "data": {
    "status": "scheduled",
    "current_plan": "gold",
    "next_plan": "silver",
    "effective_at": "2026-10-19T23:59:59Z"
  }
}
```

The shop keeps Gold until `effective_at`. Repeating the request is harmless.
The switch is applied on the next read, or by a daily job, whichever comes first.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.merchant_only` | Employees cannot change the plan |
| `409` | `subscription.already_on_plan` | Nothing to change (and not yet renewable) |
| `409` | `subscription.change_pending` | An earlier payment is unpaid, or the plan is already set to end. `details.charge_id` names the outstanding payment. |
| `409` | `subscription.charge_closed` | The `idempotency_key` belongs to a payment that failed, expired or was cancelled. Start again with a **new** key. `details` has `charge_id` and `status`. |
| `422` | `subscription.plan_unavailable` | Plan not offered |
| `422` | `payment.wallet_invalid` | Wallet number does not belong to that rail |
| `422` | `payment.rail_unavailable` | `card` is not available yet |
| `422` | `validation.failed` | Missing `rail`, `wallet_number` or `idempotency_key` |
| `502` | `payment.provider_unavailable` | Wallet provider down — retry with the same key |

```json
{
  "success": false,
  "message": "You are already on the Gold package",
  "error": { "code": "subscription.already_on_plan" }
}
```

```json
{
  "success": false,
  "message": "An earlier plan change is still waiting for payment",
  "error": {
    "code": "subscription.change_pending",
    "details": { "charge_id": "inv_01M2WP34K5G80C669C3FG1WPMR" }
  }
}
```

```json
{
  "success": false,
  "message": "That plan is not available",
  "error": { "code": "subscription.plan_unavailable", "field": "plan_id" }
}
```

## 4. POST `/api/v1/subscription/cancel` — Cancel at period end

**Purpose:** Cancels the paid plan at period end. The merchant keeps it until
`expires_at`, then falls back to Silver. Replaces the commented-out
`GET /api/merchants/subscriptions/1/cancel`, which was a GET that changed data.
**Confirm whether self-service cancellation should be shown in the app.**

**Headers:** `Authorization: Bearer <token>` (shop owner only)

**Request** — body is optional

```json
{ "reason": "too_expensive", "comment": "Will come back next season" }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `reason` | enum | no | `too_expensive` \| `not_using` \| `missing_feature` \| `other` |
| `comment` | string | no | Free text, up to 500 characters |

**Response `200`**

```json
{
  "success": true,
  "message": "Cancelled. You keep Gold until 22 October.",
  "data": {
    "status": "cancelled",
    "access_until": "2026-10-22T23:59:59Z",
    "reverts_to": "silver",
    "resubscribe_eligible": true
  }
}
```

Cancelling replaces a scheduled downgrade. The shop can renew afterwards.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.merchant_only` | Employees cannot cancel |
| `409` | `subscription.already_cancelled` | The plan is already set to end |
| `409` | `subscription.nothing_to_cancel` | On the default plan, or the paid plan has already lapsed |
| `422` | `validation.failed` | Unknown `reason` |

```json
{
  "success": false,
  "message": "Your plan is already cancelled",
  "error": { "code": "subscription.already_cancelled" }
}
```

## 5. GET `/api/v1/payments/charges/{charge_id}` — Check a subscription payment

**Purpose:** Polled by the app after `POST /subscription/change` until the
status is final. Each call checks the wallet provider once. Only subscription
charges belonging to the caller's shop are visible; anything else returns
`404 charge.not_found`. This is the start of the general charge endpoint in
[payments.md](payments.md).

**Headers:** `Authorization: Bearer <token>`

**Response `200` — waiting**

```json
{
  "success": true,
  "data": {
    "charge_id": "inv_01M2WP34K5G80C669C3FG1WPMR",
    "status": "pending",
    "rail": "edahab",
    "purpose": "subscription",
    "amount": { "amount": 92000, "currency": "SLSH", "display": "92,000 SLSH" },
    "customer": { "wallet_number": "+252654990001" },
    "created_at": "2026-09-19T11:16:14Z",
    "next_action": "await_customer_approval",
    "poll_after": 3,
    "expires_at": "2026-09-19T11:26:14Z"
  }
}
```

On eDahab, if the customer turned the payment prompt down, this response (and the
`202` from `POST /subscription/change`) also carries `"prompt": "declined"`. The
payment stays `pending`, because eDahab keeps the invoice open. A new plan change
is still refused while it is pending, until it expires; see
[payments.md](payments.md#3-post-apiv1paymentscharges--start-a-payment).

**Response `200` — paid** (the plan is now active)

```json
{
  "success": true,
  "data": {
    "charge_id": "inv_01M2WP34K5G80C669C3FG1WPMR",
    "status": "paid",
    "rail": "edahab",
    "purpose": "subscription",
    "amount": { "amount": 92000, "currency": "SLSH", "display": "92,000 SLSH" },
    "customer": { "wallet_number": "+252654990001" },
    "created_at": "2026-09-19T11:16:14Z",
    "paid_at": "2026-09-19T11:16:14Z"
  }
}
```

| `status` | Meaning |
| --- | --- |
| `pending` | Waiting for the customer (wallet) or for staff (cash) |
| `paid` | Settled; the plan has started |
| `failed`, `expired`, `cancelled` | Closed. `failure.message` explains. Start again with a **new** `idempotency_key`. |

A wallet charge expires after 10 minutes; a cash charge after 72 hours.
Polling a paid charge again never starts a second period.

**Response `404`**

```json
{
  "success": false,
  "message": "We could not find that payment",
  "error": { "code": "charge.not_found" }
}
```

---

## Paying by cash

`rail: "cash"` needs no wallet number and sends nothing to a wallet provider.

1. `POST /subscription/change` with `"rail": "cash"` returns `202` with
   `next_action: "await_cash_confirmation"`.
2. The shop pays EXELO staff in cash.
3. Staff open **Admin → Invoices** and press **Confirm cash received** on that
   row (needs the `edit-invoice` permission). The payment becomes `paid` and the
   plan starts.
4. The app, still polling `GET /payments/charges/{id}`, sees `paid`, and
   re-fetches `GET /subscription`.

A cash payment never confirms itself, so the app cannot upgrade a shop without
staff seeing the money. While one is outstanding, a second change returns
`409 subscription.change_pending` with the `charge_id`.

## Step by step: upgrading to Gold

```
GET  /plans                              → show Silver and Gold, price, features
POST /subscription/change                → 202 charge_id (wallet or cash)
GET  /payments/charges/{charge_id}       → poll every poll_after seconds until paid
GET  /subscription                       → plan is now gold, features updated
```

| State | Client does |
| --- | --- |
| `202` wallet | Show "Approve the payment on your phone" and poll |
| `202` cash | Show the message ("Pay … in cash to an EXELO agent") and keep polling, or check back later |
| `409 subscription.change_pending` | Resume polling `error.details.charge_id` |
| charge `failed` / `expired` / `cancelled` | Offer "Try again" with a new `idempotency_key` |

---

## Postman / curl quick start

```bash
BASE=https://your-host/api/v1

curl $BASE/plans -H 'Accept: application/json'

curl $BASE/subscription -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# upgrade from a wallet
curl -X POST $BASE/subscription/change -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"plan_id":1,"rail":"edahab","wallet_number":"+252654990001","idempotency_key":"<uuid>"}'

# upgrade in cash
curl -X POST $BASE/subscription/change -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"plan_id":1,"rail":"cash","idempotency_key":"<uuid>"}'

# poll the payment
curl $BASE/payments/charges/<charge_id> -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

# downgrade, then cancel
curl -X POST $BASE/subscription/change -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"plan_id":2,"idempotency_key":"<uuid>"}'
curl -X POST $BASE/subscription/cancel -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"reason":"too_expensive"}'
```

### Local testing

With `APP_ENV=local` and `EXELO_SIMULATE_PAYMENTS=true`, mark a pending charge as
paid without a wallet:

```bash
curl -X POST http://localhost/api/v1/registration/invoices/<charge_id>/simulate-payment \
  -H 'Accept: application/json'
```

See [registration.md](registration.md) (endpoint 5b). For cash, use the admin
**Confirm cash received** button, or simulate.

---

## Implementation notes

Implemented in `SubscriptionController`, `SubscriptionService` and
`InvoicePaymentService`. The legacy `/api/merchants/subscriptions/*` routes are
unchanged, including `POST /api/merchants/subscriptions`, which still grants a
plan without payment.

- **Plans.** `subscription_plans` gained `key`, `features`, `is_default` and
  `price_slsh`. `PlanCatalogueSeeder` (part of `DatabaseSeeder`, safe to re-run)
  loads the starting catalogue: Silver is free and the default plan; Gold is
  92,000 SLSH / $11.50.
- **Admins manage plans** in the admin panel, **Subscription Plans**
  (`/admin/subscription-plans`, permissions `view`/`create`/`edit`/`delete-subscription`;
  code `Admin\SubscriptionPlanController`, `SubscriptionPlanService`). Changes show
  in `GET /plans` at once. Rules:
  - The **key** is set on create and can't change, because the app gates on it.
    A key is never reused, not even from a deleted plan.
  - **Features** are picked from `SubscriptionPlan::FEATURES`.
  - **Exactly one default plan.** Making a plan the default moves it there; the
    default can't be unticked or deleted.
  - **Billing is monthly** for every plan (`SubscriptionPlan::DURATION`).
  - A **price change** applies to the next payment; merchants who already paid keep
    their period. A **removed feature** is taken away at once.
  - A plan can't be **deleted** while a merchant is on it, has it scheduled, or has a
    payment for it pending. Deleting is a soft delete, so history keeps the name.
- **The default plan never expires.** Its row has no `end_date`. A shop with no
  row is on the default plan; `GET /subscription` never creates one (the legacy
  `current` endpoint did).
- **History is kept.** Every period is its own row, linked to the invoice that
  paid for it.
- **Scheduled downgrades** are stored on the current row and applied when the
  period ends, on the next read or by the daily
  `subscriptions:apply-scheduled` command. Run the Laravel scheduler in
  production.
- **Config.** `SUBSCRIPTION_GRACE_DAYS` (5), the 7-day renewal window and the
  72-hour cash window live in `config/exelo.php`.
- **Only the owner can change or cancel.** Employees can read the plan.
