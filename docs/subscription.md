# Subscription & Plans

EXELO ships two tiers. Gold is the full POS; Silver is the reduced dashboard.
The app currently decides which UI to show by comparing
`subscription_plan_id == "2"` — a magic string compiled into the client. v1
returns an explicit plan key and a feature list so a third tier never requires
an app release.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/plans`](#get-plans) | — |
| GET | [`/subscription`](#get-subscription) | Bearer |
| POST | [`/subscription/change`](#post-subscriptionchange) | Bearer |
| POST | [`/subscription/cancel`](#post-subscriptioncancel) | Bearer |

---

## GET /plans

The plan catalogue, for the upgrade screen.

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
        "features": [
          "dashboard.basic",
          "inventory.read",
          "payments.request"
        ],
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
          "dashboard.full",
          "pos.register",
          "pos.scanning",
          "inventory.write",
          "inventory.transfers",
          "employees.manage",
          "reports.full",
          "nfc.payments",
          "offline.mode"
        ],
        "is_default": false
      }
    ]
  }
}
```

Clients gate UI on `features`, not on `id` or `name`.

---

## GET /subscription

The merchant's current plan. Replaces
`GET /api/merchants/subscriptions/current`.

This is fetched at splash in the legacy app to decide Gold vs Silver routing.
In v1 the same information already arrives in the login response and in
[`GET /auth/session`](auth.md#get-authsession), so this endpoint exists for
refresh rather than boot — one less blocking call on a slow start.

**Response `200`**

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
    "started_at": "2026-09-01T00:00:00Z",
    "expires_at": "2026-12-31T23:59:59Z",
    "renews_automatically": true,
    "can_upgrade": false,
    "can_downgrade": true,
    "resubscribe_eligible": true,
    "grace": null
  }
}
```

| `status` | Meaning | Client behaviour |
| --- | --- | --- |
| `active` | Paid and current | Full access for the plan |
| `grace` | Expired, still usable | Show a banner with `grace.ends_at` |
| `past_due` | Payment failed | Prompt to pay; keep read access |
| `expired` | Lapsed | Drop to Silver features |
| `cancelled` | Cancelled by merchant | Drop to Silver at `expires_at` |

The `grace` field, when present, carries the deadline:

```json
{ "grace": { "ends_at": "2026-10-07T23:59:59Z", "days_remaining": 5 } }
```

Grace matters here specifically because a shop can be offline for days; a plan
should not hard-expire while the merchant has no way to pay.

**Offline behaviour.** The client caches the last known plan and keeps using it
when this call fails. A shop must never be downgraded mid-sale because the link
dropped.

---

## POST /subscription/change

Upgrade or downgrade. Replaces `POST /api/merchants/subscriptions`.

An upgrade returns an invoice to pay; the plan changes when the payment lands.
A downgrade applies at the end of the current period, not immediately.

**Request**

```json
{
  "plan_id": 1,
  "rail": "zaad",
  "wallet_number": "+252632222222",
  "idempotency_key": "f92d1c07-3b4a-4f88-8a21-6d5e9c1b4477"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `plan_id` | int | yes | Target plan |
| `rail` | enum | On upgrade | `zaad` \| `edahab` \| `card` |
| `wallet_number` | string | On wallet rails | Which number to bill |
| `idempotency_key` | string | yes | Prevents double-charging an upgrade |

**Response `202` — upgrade, payment required**

```json
{
  "success": true,
  "message": "Approve the payment on your phone to start Gold",
  "data": {
    "status": "payment_required",
    "charge_id": "chg_01JBXS4M8P",
    "amount": { "amount": 92000, "currency": "SLSH", "display": "92,000 SLSH" },
    "poll": "/api/v1/payments/charges/chg_01JBXS4M8P",
    "applies_on_payment": true
  }
}
```

The client then polls
[`GET /payments/charges/{id}`](payments.md#get-paymentschargesid). On `paid`,
re-fetch `GET /subscription` — the plan will have flipped.

**Response `200` — downgrade, scheduled**

```json
{
  "success": true,
  "message": "You will move to Silver on 31 December",
  "data": {
    "status": "scheduled",
    "current_plan": "gold",
    "next_plan": "silver",
    "effective_at": "2026-12-31T23:59:59Z"
  }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `409` | `subscription.already_on_plan` | Nothing to change |
| `409` | `subscription.change_pending` | An earlier change is still settling |
| `402` | `payment.failed` | Wallet declined |
| `422` | `subscription.plan_unavailable` | Plan not offered to this merchant |

---

## POST /subscription/cancel

Cancels at period end. The merchant keeps Gold until `expires_at`, then falls
back to Silver.

This existed in the legacy codebase only as a commented-out
`GET /api/merchants/subscriptions/1/cancel` — a GET that mutated state. It is
specified properly here; **confirm whether self-service cancellation should
actually be exposed in the app.**

**Request**

```json
{ "reason": "too_expensive", "comment": "Will come back next season" }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `reason` | enum | no | `too_expensive` \| `not_using` \| `missing_feature` \| `other` |
| `comment` | string | no | Free text |

**Response `200`**

```json
{
  "success": true,
  "message": "Cancelled. You keep Gold until 31 December.",
  "data": {
    "status": "cancelled",
    "access_until": "2026-12-31T23:59:59Z",
    "reverts_to": "silver",
    "resubscribe_eligible": true
  }
}
```
