# Registration & Onboarding

Creating a new merchant. The signup fee is paid **before** the account exists,
so most of this module is unauthenticated and keyed on the phone number.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/geo/states`](#get-geostates) | — |
| GET | [`/registration/quote`](#get-registrationquote) | — |
| POST | [`/registration/phone/check`](#post-registrationphonecheck) | — |
| POST | [`/registration/invoices`](#post-registrationinvoices) | — |
| GET | [`/registration/invoices/{invoice_id}`](#get-registrationinvoicesinvoice_id) | — |
| POST | [`/merchants`](#post-merchants) | — |
| POST | [`/merchants/{id}/verification/complete`](#post-merchantsidverificationcomplete) | Bearer |

---

## Complete endpoint list (registration + login)

Registration ends in login, so this lists every endpoint the app calls from a
brand-new install to a signed-in shop. Full URL = `{BASE_URL}/api/v1` + path.

**Headers**

| Header | Sent on | Value |
| --- | --- | --- |
| `Accept` | Every request | `application/json` |
| `Content-Type` | Requests with a body | `application/json` |
| `X-EXELO-Device-Id` | `POST /auth/pin`, `POST /auth/pin/login`, `POST /auth/pin/reset` (required) | Stable UUID per install |
| `Authorization` | Rows marked Bearer | `Bearer <token>` from `POST /auth/pin` or `POST /auth/pin/login` |
| `Idempotency-Key` | `POST /registration/invoices` (alternative to the body field) | UUID generated once per Pay tap |

### A. Registration

| # | Method | Full path | Purpose | Auth | Body / query | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | GET | `/api/v1/geo/states` | Load the state dropdown | — | — | `200` | — |
| 2 | POST | `/api/v1/registration/phone/check` | Check whether a phone number can register | — | `phone_number` | `200` | `422 validation.failed` |
| 3 | GET | `/api/v1/registration/quote` | Get the signup fee | — | `?purpose=registration` (or `verification`) | `200` | `422 validation.failed` |
| 4 | POST | `/api/v1/registration/invoices` | Request the signup payment | — | `phone_number`, `wallet_number`, `rail` (`zaad`\|`edahab`), `purpose`, `quote_id`, `idempotency_key` | `202` | `409 registration.phone_taken`, `410 quote.expired`, `422 payment.wallet_invalid`, `502 payment.provider_unavailable` |
| 5 | GET | `/api/v1/registration/invoices/{invoice_id}` | Check the payment status | — | — | `200` (`status`: `pending`, `paid`, `failed`, `expired`, `cancelled`) | `404 invoice.not_found`, `502 payment.provider_unavailable` |
| 6 | POST | `/api/v1/merchants` | Create the merchant account | — | `invoice_id`, `first_name`, `last_name`, `dob` (`YYYY-MM-DD`), `phone_number`, `business_name`, `state` (code), `city`; optional `email`, `merchant_code`, `other_merchant_code` | `201` | `402 registration.invoice_unpaid`, `409 registration.invoice_consumed`, `409 registration.phone_taken`, `422 validation.failed` |
| 7 | POST | `/api/v1/auth/pin` | Set the first PIN and sign in | — + `X-EXELO-Device-Id` | `phone_number`, `pin` (4 digits), `pin_confirmation` | `201` + token | `409 auth.pin_already_set`, `422 auth.pin_too_weak`, `422 validation.failed`, `400 request.device_id_missing` |
| 8 | POST | `/api/v1/merchants/{id}/verification/complete` | Confirm payout wallets | Bearer | optional `invoice_id` | `200` | `401`, `402 registration.invoice_unpaid`, `403 auth.merchant_only`, `404 merchant.not_found`, `409 registration.invoice_consumed` |

### B. Login and session

| # | Method | Full path | Purpose | Auth | Body | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 9 | POST | `/api/v1/auth/lookup` | Identify a phone number | — | `phone_number` | `200` (`exists`, `user_type`, `has_pin`, `registration`) | `422 validation.failed` |
| 10 | POST | `/api/v1/auth/pin/login` | Sign in with phone and PIN | — + `X-EXELO-Device-Id` | `phone_number`, `pin` | `200` + token | `401 auth.invalid_credentials`, `403 auth.registration_incomplete`, `403 auth.employee_disabled`, `409 auth.pin_not_set`, `423 auth.locked` |
| 11 | GET | `/api/v1/auth/session` | Get the current session | Bearer | — | `200` | `401 auth.token_invalid` |
| 12 | POST | `/api/v1/auth/logout` | Sign out | Bearer | optional `all_devices` (bool) | `200` (`revoked_devices`) | `401` |

### C. PIN management

| # | Method | Full path | Purpose | Auth | Body | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 13 | PATCH | `/api/v1/auth/pin` | Change the PIN | Bearer | `current_pin`, `pin`, `pin_confirmation` | `200` | `401 auth.invalid_credentials`, `422 auth.pin_too_weak`, `423 auth.locked` |
| 14 | POST | `/api/v1/auth/pin/verify` | Re-confirm the PIN for a sensitive action | Bearer | `pin`, optional `scope` | `200` (`confirmation_token`) | `401 auth.invalid_credentials`, `423 auth.locked` |
| 15 | POST | `/api/v1/auth/pin/reset/request` | Start a forgotten-PIN reset | — | `phone_number` | `200` (`masked_phone`, `expires_in`, `resend_after`) | `429 auth.otp_throttled` |
| 16 | POST | `/api/v1/auth/pin/reset/verify` | Verify the reset code | — | `phone_number`, `otp` | `200` (`reset_token`) | `410 auth.otp_expired`, `422 auth.otp_invalid` |
| 17 | POST | `/api/v1/auth/pin/reset` | Set a new PIN after a reset | — + `X-EXELO-Device-Id` | `reset_token`, `pin`, `pin_confirmation` | `200` + token | `401 auth.token_invalid`, `422 auth.pin_too_weak` |

**Status codes shared by every endpoint**

| Status | Code | Meaning |
| --- | --- | --- |
| `400` | `request.device_id_missing` | `X-EXELO-Device-Id` header missing where required |
| `401` | `auth.token_invalid` | Missing, revoked or expired Bearer token — clear the session |
| `422` | `validation.failed` | Per-field messages in `error.details` |
| `429` | `rate_limited` | Slow down; honour `Retry-After` / `error.details.retry_after` |

**Rate limits (per IP, per minute):** `lookup` 30 · login, PIN create and PIN
reset 20 · OTP request and verify 10 · `/registration/*` 60 · `POST /merchants` 20.

**Response envelope.** Every response carries `success`, `message`, `data` (or
`error`) and `meta.request_id` / `meta.server_time`. Branch on `error.code`,
never on `message`. See [errors.md](errors.md).

### Postman / curl quick start

```bash
BASE=https://your-host/api/v1

# 2. check the number
curl -X POST $BASE/registration/phone/check -H 'Accept: application/json' \
  -H 'Content-Type: application/json' -d '{"phone_number":"+252654110101"}'

# 3. quote
curl $BASE/registration/quote -H 'Accept: application/json'

# 4. request payment
curl -X POST $BASE/registration/invoices -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"phone_number":"+252654110101","wallet_number":"+252654110101","rail":"edahab","purpose":"registration","quote_id":"<from step 3>","idempotency_key":"<uuid>"}'

# 5. poll
curl $BASE/registration/invoices/<invoice_id> -H 'Accept: application/json'

# 6. create the merchant
curl -X POST $BASE/merchants -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d '{"invoice_id":"<invoice_id>","first_name":"Kalid","last_name":"Ahmed","dob":"1990-01-01","phone_number":"+252654110101","business_name":"Exelo Retail","state":"maroodi_jeex","city":"Hargeisa"}'

# 7. set the PIN and receive the token
curl -X POST $BASE/auth/pin -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'X-EXELO-Device-Id: my-device-1' \
  -d '{"phone_number":"+252654110101","pin":"2580","pin_confirmation":"2580"}'

# later: sign in, session, sign out
curl -X POST $BASE/auth/pin/login -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H 'X-EXELO-Device-Id: my-device-1' -d '{"phone_number":"+252654110101","pin":"2580"}'
curl $BASE/auth/session -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
curl -X POST $BASE/auth/logout -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

---

## Request and response reference

Every example below is real output from the running API (tokens shortened,
`…`). All responses share this envelope; `error` replaces `data` on failure.

```json
{
  "success": true,
  "message": "Human readable, safe to show in the UI",
  "data": {},
  "meta": { "request_id": "req_01M2W762TN9MT1YW4523F3492Y", "server_time": "2026-09-19T06:55:42Z" }
}
```

```json
{
  "success": false,
  "message": "Human readable, safe to show in the UI",
  "error": { "code": "domain.reason", "field": "optional_field", "details": {} },
  "meta": { "request_id": "req_01M2W762X8028N8H48S93EBY7E", "server_time": "2026-09-19T06:55:42Z" }
}
```

### 1. GET `/api/v1/geo/states` — Load the state dropdown

**Purpose:** Returns the list of states for the signup form. Send the `code` back when you create the merchant and show the `name` to the user.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "country": "SO",
    "states": [
      { "code": "awdal", "name": "Awdal" },
      { "code": "maroodi_jeex", "name": "Maroodi Jeex" },
      { "code": "togdheer", "name": "Togdheer" },
      { "code": "sahil", "name": "Sahil" },
      { "code": "sool", "name": "Sool" }
    ]
  },
  "meta": { "request_id": "req_01M2W762TN9MT1YW4523F3492Y", "server_time": "2026-09-19T06:55:42Z" }
}
```

### 2. POST `/api/v1/registration/phone/check` — Check whether a phone number can register

**Purpose:** First call of registration. Tells the app if the number is new, already registered, or has already paid the fee but never finished, so the user is never charged twice.

**Request**

```json
{ "phone_number": "+252654110101" }
```

**Response `200` — number is new**

```json
{
  "success": true,
  "message": "Number available",
  "data": {
    "available": true,
    "registration_complete": false,
    "invoice_required": true,
    "pending_invoice": null
  }
}
```

**Response `200` — fee already paid, account not created**

```json
{
  "success": true,
  "message": "You already paid. Continue where you left off.",
  "data": {
    "available": true,
    "registration_complete": false,
    "invoice_required": false,
    "pending_invoice": {
      "invoice_id": "inv_01M2W762W927DAYTDR5DSJYS7G",
      "status": "paid",
      "paid_at": "2026-09-19T06:55:42Z"
    }
  }
}
```

**Response `200` — already registered**

```json
{
  "success": true,
  "message": "This number already has an EXELO account",
  "data": {
    "available": false,
    "registration_complete": true,
    "invoice_required": false,
    "pending_invoice": null
  }
}
```

### 3. GET `/api/v1/registration/quote` — Get the signup fee

**Purpose:** Returns the price the customer will be charged. The returned `quote_id` locks that price for 15 minutes and is sent when requesting the payment.

Optional query: `?purpose=registration` (default) or `verification`.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "purpose": "registration",
    "base": {
      "slsh": { "amount": 500, "currency": "SLSH", "display": "500 SLSH" },
      "usd": { "amount": 5, "currency": "USD", "display": "$0.05" }
    },
    "exelo_fee": {
      "slsh": { "amount": 50, "currency": "SLSH", "display": "50 SLSH" },
      "usd": { "amount": 0, "currency": "USD", "display": "$0.00" }
    },
    "total": {
      "slsh": { "amount": 550, "currency": "SLSH", "display": "550 SLSH" },
      "usd": { "amount": 5, "currency": "USD", "display": "$0.05" }
    },
    "quote_id": "qte_0G0JFHNLJF",
    "expires_at": "2026-09-19T07:10:42Z"
  }
}
```

| Field | Meaning |
| --- | --- |
| `base` | The registration (or verification) price, set by an admin |
| `exelo_fee` | EXELO's fee on top, set by an admin |
| `total` | What the wallet is billed: `base` + `exelo_fee`. Show this as the price |
| `*.slsh` | The amount in SLSH, whole shillings. **The wallet is billed in SLSH** |
| `*.usd` | The same amount in USD, in cents, at the configured conversion rate. For display only |

Each USD amount is converted from its own SLSH amount, so `total.usd` can differ
from `base.usd` + `exelo_fee.usd` by a cent. The same fields come back for
`?purpose=verification`.

> **Changed 2026-09-25.** Replaces the flat `base`, `fee`, `customer_charge` and
> `amount_in_usd` fields. Read `base.slsh`, `exelo_fee.slsh` and `total.slsh`
> (was `customer_charge`) instead.

### 4. POST `/api/v1/registration/invoices` — Request the signup payment

**Purpose:** Sends a payment prompt to the customer's Zaad or eDahab wallet. The customer approves it on their phone. Safe to retry with the same `idempotency_key`.

**Request**

```json
{
  "phone_number": "+252654110101",
  "wallet_number": "+252654110101",
  "rail": "edahab",
  "purpose": "registration",
  "quote_id": "qte_0G0JFHNLJF",
  "idempotency_key": "b4f1c8de-92a7-4f10-9c33-0a5e7d2b6f81"
}
```

**Response `202`**

```json
{
  "success": true,
  "message": "Approve the payment on your phone",
  "data": {
    "invoice_id": "inv_01M2W762W927DAYTDR5DSJYS7G",
    "status": "pending",
    "rail": "edahab",
    "amount": { "amount": 550, "currency": "SLSH", "display": "550 SLSH" },
    "next_action": "await_customer_approval",
    "poll_after": 3,
    "expires_at": "2026-09-19T07:05:42Z"
  }
}
```

**Response `410` — quote expired**

```json
{
  "success": false,
  "message": "The price has expired. Get a new quote.",
  "error": { "code": "quote.expired" }
}
```

**Response `422` — wallet number does not match the rail**

```json
{
  "success": false,
  "message": "That wallet number does not belong to Edahab",
  "error": { "code": "payment.wallet_invalid", "field": "wallet_number" }
}
```

### 5. GET `/api/v1/registration/invoices/{invoice_id}` — Check the payment status

**Purpose:** Polled by the app every few seconds after the payment request, until the status is `paid`. A `paid` invoice is required to create the merchant.

**Response `200` — still waiting**

```json
{
  "success": true,
  "data": {
    "invoice_id": "inv_01M2W762W927DAYTDR5DSJYS7G",
    "status": "pending",
    "poll_after": 3,
    "expires_at": "2026-09-19T07:05:42Z"
  }
}
```

**`prompt: "declined"`**: on eDahab, when the customer turned down the payment
prompt on their phone, the pending response (here and on
`POST /registration/invoices`) also carries `"prompt": "declined"`. The payment
**stays `pending`**, because eDahab keeps the invoice open and it could still be
paid. Tell the user the prompt was declined, so they aren't left watching a
spinner. The field is absent otherwise.

**Response `200` — paid**

```json
{
  "success": true,
  "message": "Payment received",
  "data": {
    "invoice_id": "inv_01M2W762W927DAYTDR5DSJYS7G",
    "status": "paid",
    "paid_at": "2026-09-19T06:55:42Z",
    "amount": { "amount": 550, "currency": "SLSH", "display": "550 SLSH" },
    "receipt_no": "EXL-RCP-00024"
  }
}
```

**Response `200` — `failed`, `expired` or `cancelled`**

```json
{
  "success": true,
  "data": { "invoice_id": "inv_01M2W762W927DAYTDR5DSJYS7G", "status": "expired", "error_reason": null }
}
```

**Response `404`** — `{ "error": { "code": "invoice.not_found" } }`

### 5b. POST `/api/v1/registration/invoices/{invoice_id}/simulate-payment` — Simulate a payment (local testing only)

**Purpose:** Marks a pending invoice as paid so registration can be finished on localhost without a real wallet.

Marks a pending invoice as paid without a wallet prompt, so you can finish
registration on localhost. It only works when **both** are true, and returns
`404 not_found` otherwise (staging and production never enable it):

- `APP_ENV=local`
- `EXELO_SIMULATE_PAYMENTS=true` in `.env`

**Request** — body is optional. `outcome` is `paid` (default), `failed`,
`cancelled` or `expired`.

```json
{ "outcome": "paid" }
```

**Response `200`** — same body as the paid response of endpoint 5.

```json
{
  "success": true,
  "message": "Payment received",
  "data": {
    "invoice_id": "inv_01M2W762W927DAYTDR5DSJYS7G",
    "status": "paid",
    "paid_at": "2026-09-19T06:55:42Z",
    "amount": { "amount": 550, "currency": "SLSH", "display": "550 SLSH" },
    "receipt_no": "EXL-RCP-00024"
  }
}
```

`409 invoice.not_pending` when the invoice is already paid or closed.

```bash
curl -X POST http://localhost/api/v1/registration/invoices/<invoice_id>/simulate-payment \
  -H 'Accept: application/json'
```

### 6. POST `/api/v1/merchants` — Create the merchant account

**Purpose:** Creates the shop owner's account (user, merchant profile and Silver subscription) once the signup fee is paid. It does not sign the user in; the next step is to set a PIN.

**Request**

```json
{
  "invoice_id": "inv_01M2W762W927DAYTDR5DSJYS7G",
  "first_name": "Kalid",
  "last_name": "Ahmed",
  "dob": "1990-01-01",
  "email": "kalid@exelo.co",
  "phone_number": "+252654110101",
  "business_name": "Exelo Retail",
  "state": "maroodi_jeex",
  "city": "Hargeisa",
  "merchant_code": "EXL-102",
  "other_merchant_code": "ZAAD-88"
}
```

`email`, `merchant_code` and `other_merchant_code` are optional.

**Response `201`**

```json
{
  "success": true,
  "message": "Account created. Set your PIN to continue.",
  "data": {
    "merchant": {
      "id": 94,
      "business_name": "Exelo Retail",
      "merchant_code": "EXL-102",
      "state": "Maroodi Jeex",
      "state_code": "maroodi_jeex",
      "city": "Hargeisa",
      "location": "Hargeisa, Maroodi Jeex",
      "created_at": "2026-09-19T06:55:42Z"
    },
    "user": { "id": 109, "type": "merchant", "has_pin": false },
    "subscription": { "plan_id": 2, "plan": "silver", "status": "active" },
    "next_step": "set_pin"
  }
}
```

**Response `402` — fee not paid yet**

```json
{
  "success": false,
  "message": "The signup fee has not been paid",
  "error": { "code": "registration.invoice_unpaid" }
}
```

**Response `409` — invoice already created an account**

```json
{
  "success": false,
  "message": "That payment already created an account",
  "error": { "code": "registration.invoice_consumed" }
}
```

**Response `422` — validation**

```json
{
  "success": false,
  "message": "Please check the form",
  "error": {
    "code": "validation.failed",
    "details": {
      "dob": ["Enter a valid date of birth"],
      "state": ["Choose a state"]
    }
  }
}
```

### 7. POST `/api/v1/auth/pin` — Set the first PIN and sign in

**Purpose:** Last step of registration. The new merchant (or a newly added employee) chooses a 4-digit PIN and receives the token, shop, plan and permissions needed to open the app.

**Headers:** `X-EXELO-Device-Id: a3f1c2d4-7b8e-4a5f-9c10-2d6e8b4f1a77`

**Request**

```json
{ "phone_number": "+252654110101", "pin": "2580", "pin_confirmation": "2580" }
```

**Response `201`** — the user is signed in. `login` and `reset` return this
same `data` shape.

```json
{
  "success": true,
  "message": "PIN created",
  "data": {
    "token": "18|vJ2kQx9fR7pLmN3sT6wY0aB4cD8eF1gH",
    "expires_at": "2027-03-19T06:55:42Z",
    "user": {
      "id": 109,
      "type": "merchant",
      "first_name": "Kalid",
      "last_name": "Ahmed",
      "short_name": "KA",
      "email": "kalid@exelo.co",
      "phone_number": "+252654110101"
    },
    "merchant": {
      "id": 94,
      "business_name": "Exelo Retail",
      "merchant_code": "EXL-102",
      "state": "Maroodi Jeex",
      "city": "Hargeisa",
      "location": "Hargeisa, Maroodi Jeex",
      "currency": "USD",
      "alt_currency": "SLSH",
      "exchange_rate": 10500
    },
    "subscription": {
      "plan_id": 2,
      "plan": "silver",
      "status": "active",
      "expires_at": "2026-10-19T23:59:59Z"
    },
    "permissions": [
      { "key": "pos", "name": "POS" },
      { "key": "inventory", "name": "Inventory" },
      { "key": "transactions", "name": "Transactions" },
      { "key": "reports", "name": "Reports" },
      { "key": "employees", "name": "Employee Management" }
    ]
  }
}
```

**Response `400` — header missing**

```json
{
  "success": false,
  "message": "X-EXELO-Device-Id header is required",
  "error": { "code": "request.device_id_missing" }
}
```

**Response `409` — PIN already exists**

```json
{
  "success": false,
  "message": "A PIN is already set. Reset it instead.",
  "error": { "code": "auth.pin_already_set" }
}
```

**Response `422` — weak PIN**

```json
{
  "success": false,
  "message": "Choose a PIN that is harder to guess",
  "error": { "code": "auth.pin_too_weak", "field": "pin" }
}
```

### 8. POST `/api/v1/merchants/{id}/verification/complete` — Confirm payout wallets

**Purpose:** Completes wallet verification with a paid **verification** invoice, and reports the shop's payout wallets. Verification is a separate payment from registration, usually made from the number the merchant registered with. **The wallet that paid becomes the shop's verified payout number** on its network (`edahab_number` or `zaad_number`), replacing any number there; it also becomes the default payout network if none is set. Other `pending` numbers stay pending until a verification payment is made from each. The payment is taken with `POST /registration/invoices` and `purpose: "verification"`, `phone_number` = the shop's number; a wallet that already belongs to another shop is refused there (`409 wallet.number_taken`) before anything is charged. The full flow is in [merchant-onboarding.md, part B](merchant-onboarding.md#b-verify-the-payout-wallet).

**Headers:** `Authorization: Bearer <token>`

**Path `{id}` is a shop id** (the route name predates shops): `shops[].id` from
`GET /account` or `GET /shops`, **not** the merchant's own id. It can be **any shop the
merchant owns**, not only the one the session is working in, so another shop's wallet
can be verified without switching.

**Request** — body is optional: `{ "invoice_id": "inv_…" }` when verification
carries a fee. The invoice must have been issued for **that shop's** number
(`phone_number` in `POST /registration/invoices`).

**Response `200`**

```json
{
  "success": true,
  "message": "Your payment numbers are verified",
  "data": {
    "verified": false,
    "wallets": {
      "zaad_number": { "number": null, "status": "not_set" },
      "edahab_number": { "number": null, "status": "not_set" },
      "golis_number": { "number": null, "status": "not_set" },
      "evc_number": { "number": null, "status": "not_set" }
    }
  }
}
```

`status` per network: `verified` (paid for), `pending` (saved with
`PATCH /merchant/wallets`, not yet paid for) or `not_set`. `verified` is `true`
when at least one network is verified. Without `invoice_id`, the call only reports
the wallets.
`403 auth.merchant_only` for employees. `404 merchant.not_found` when `{id}` is not one
of the caller's shops; `error.details.your_shop_ids` lists the ids that are:

```json
{
  "success": false,
  "message": "We could not find that shop among yours",
  "error": { "code": "merchant.not_found", "details": { "your_shop_ids": [1, 2] } }
}
```

### 9. POST `/api/v1/auth/lookup` — Identify a phone number

**Purpose:** First call of login. Tells the app whether the number belongs to a merchant or an employee and whether a PIN exists, so it shows the right screen (create PIN or enter PIN).

**Request**

```json
{ "phone_number": "+252654110101" }
```

**Response `200` — known number**

```json
{
  "success": true,
  "message": "Enter your PIN",
  "data": {
    "exists": true,
    "user_type": "merchant",
    "has_pin": false,
    "display_name": "Kalid Ahmed",
    "business_name": "Exelo Retail",
    "registration": { "complete": true, "invoice_required": false }
  }
}
```

**Response `200` — unknown number**

```json
{
  "success": true,
  "message": "Create an account to continue",
  "data": { "exists": false, "user_type": null, "has_pin": false }
}
```

`has_pin: false` means send the user to `POST /auth/pin`; `true` to `pin/login`.

### 10. POST `/api/v1/auth/pin/login` — Sign in with phone and PIN

**Purpose:** Exchanges phone number and PIN for a session token. Locks the account for 15 minutes after 5 wrong PINs.

**Headers:** `X-EXELO-Device-Id: a3f1c2d4-7b8e-4a5f-9c10-2d6e8b4f1a77`

**Request**

```json
{ "phone_number": "+252654110101", "pin": "2580" }
```

**Response `200`** — `message` is `"Welcome back, Kalid"`; `data` has the same
shape as endpoint 7 (`POST /auth/pin`).

**Response `401` — wrong PIN**

```json
{
  "success": false,
  "message": "That PIN is not correct. 4 attempts left.",
  "error": { "code": "auth.invalid_credentials", "details": { "attempts_remaining": 4 } }
}
```

**Response `423` — locked after 5 wrong PINs**

```json
{
  "success": false,
  "message": "Too many wrong PINs. Try again later.",
  "error": { "code": "auth.locked", "details": { "retry_after": 899 } }
}
```

Other errors: `403 auth.registration_incomplete`, `403 auth.employee_disabled`,
`409 auth.pin_not_set`.

### 11. GET `/api/v1/auth/session` — Get the current session

**Purpose:** Returns the signed-in user, shop, plan, permissions and active shift. The app caches this as its profile and refreshes it when it resumes.

**Headers:** `Authorization: Bearer <token>`

**Response `200`**

```json
{
  "success": true,
  "data": {
    "user": {
      "id": 109,
      "type": "merchant",
      "first_name": "Kalid",
      "last_name": "Ahmed",
      "short_name": "KA",
      "email": "kalid@exelo.co",
      "phone_number": "+252654110101"
    },
    "merchant": {
      "id": 94,
      "business_name": "Exelo Retail",
      "merchant_code": "EXL-102",
      "other_merchant_code": "ZAAD-88",
      "state": "Maroodi Jeex",
      "city": "Hargeisa",
      "location": "Hargeisa, Maroodi Jeex",
      "currency": "USD",
      "alt_currency": "SLSH",
      "exchange_rate": 10500,
      "wallets": { "zaad_number": null, "edahab_number": null }
    },
    "subscription": { "plan_id": 2, "plan": "silver", "status": "active" },
    "permissions": [
      { "key": "pos", "name": "POS" },
      { "key": "inventory", "name": "Inventory" },
      { "key": "transactions", "name": "Transactions" },
      { "key": "reports", "name": "Reports" },
      { "key": "employees", "name": "Employee Management" }
    ],
    "shift": { "active": false, "shift_id": null, "started_at": null },
    "server_time": "2026-09-19T06:55:42Z"
  }
}
```

**Response `401` — missing, revoked or expired token**

```json
{
  "success": false,
  "message": "Please sign in again",
  "error": { "code": "auth.token_invalid" }
}
```

### 12. POST `/api/v1/auth/logout` — Sign out

**Purpose:** Revokes this device's token, or every device's token with `all_devices`.

**Headers:** `Authorization: Bearer <token>`

**Request** — optional: `{ "all_devices": false }`. `true` signs out every device.

**Response `200`**

```json
{ "success": true, "message": "Signed out", "data": { "revoked_devices": 1 } }
```

### 13. PATCH `/api/v1/auth/pin` — Change the PIN

**Purpose:** Changes the signed-in user's PIN and signs out all their other devices.

**Headers:** `Authorization: Bearer <token>`

**Request**

```json
{ "current_pin": "2580", "pin": "1357", "pin_confirmation": "1357" }
```

**Response `200`**

```json
{
  "success": true,
  "message": "PIN updated",
  "data": { "changed_at": "2026-09-19T06:55:42Z", "other_devices_signed_out": false }
}
```

`other_devices_signed_out` is `true` when tokens on other devices were revoked.
The calling device keeps its token. Wrong `current_pin` returns
`401 auth.invalid_credentials`.

### 14. POST `/api/v1/auth/pin/verify` — Re-confirm the PIN for a sensitive action

**Purpose:** Asks the signed-in user to re-enter their PIN before an action such as adding staff. Returns a short-lived `confirmation_token`; it does not issue a new session.

**Headers:** `Authorization: Bearer <token>`

**Request**

```json
{ "pin": "2580", "scope": "employees.create" }
```

**Response `200`**

```json
{
  "success": true,
  "message": "Confirmed",
  "data": { "confirmation_token": "cnf_SZUWDR6KWYXG6WVLWCF5", "expires_at": "2026-09-19T07:00:42Z" }
}
```

The confirmation token is valid for five minutes.

### 15. POST `/api/v1/auth/pin/reset/request` — Start a forgotten-PIN reset

**Purpose:** Sends a one-time code by SMS to the user's phone. The code is never returned in the response.

**Request**

```json
{ "phone_number": "+252654110101" }
```

**Response `200`** — the OTP is sent by SMS and is never in the response.

```json
{
  "success": true,
  "message": "We sent a code to +252 65 *** 0101",
  "data": { "masked_phone": "+252 65 *** 0101", "expires_in": 300, "resend_after": 60 }
}
```

**Local development only.** With `APP_ENV=local` and `EXELO_EXPOSE_OTP=true`,
the response also carries the code, so you can test the reset without an SMS
provider. It is never present in any other environment.

```json
{
  "success": true,
  "message": "We sent a code to +252 65 *** 0101",
  "data": { "masked_phone": "+252 65 *** 0101", "expires_in": 300, "resend_after": 60, "otp": "017010" }
}
```

**Response `429` — requested again too soon**

```json
{
  "success": false,
  "message": "Please wait before requesting another code",
  "error": { "code": "auth.otp_throttled", "details": { "retry_after": 60 } }
}
```

### 16. POST `/api/v1/auth/pin/reset/verify` — Verify the reset code

**Purpose:** Checks the SMS code and returns a short-lived `reset_token` that authorises setting a new PIN.

**Request**

```json
{ "phone_number": "+252654110101", "otp": "017010" }
```

**Response `200`**

```json
{
  "success": true,
  "message": "Code confirmed",
  "data": { "reset_token": "rst_G3T55L6FLFXWYMPHMEBD", "expires_at": "2026-09-19T07:05:42Z" }
}
```

**Response `422` — wrong code**

```json
{
  "success": false,
  "message": "That code is not correct",
  "error": { "code": "auth.otp_invalid", "details": { "attempts_remaining": 4 } }
}
```

`410 auth.otp_expired` when the code timed out; request a new one.

### 17. POST `/api/v1/auth/pin/reset` — Set a new PIN after a reset

**Purpose:** Sets the new PIN using the `reset_token`, signs the user in on this device and signs them out everywhere else.

**Headers:** `X-EXELO-Device-Id: a3f1c2d4-7b8e-4a5f-9c10-2d6e8b4f1a77`

**Request**

```json
{ "reset_token": "rst_G3T55L6FLFXWYMPHMEBD", "pin": "8642", "pin_confirmation": "8642" }
```

**Response `200`** — `message` is `"PIN updated"`; `data` has the same shape as
endpoint 7 (`POST /auth/pin`). The user is signed in on this device
and signed out everywhere else. The reset token works once; reusing it returns
`401 auth.token_invalid`.

---

## Flow

```
GET  /registration/quote            → what the signup fee costs
POST /registration/phone/check      → is this number already taken?
POST /registration/invoices         → push a payment request to Zaad or eDahab
GET  /registration/invoices/{id}    → poll until status = paid
POST /merchants                     → create the account
POST /auth/pin                      → set the PIN, receive a token
```

Wallet verification (`/verification/complete`) happens later, from inside the
app, once the merchant adds the numbers they want to be paid on.

**Account first, shops after (2026-09-26).** `POST /merchants` now creates the
**merchant account only**: send the personal fields and no shop fields. The
merchant then sets a PIN, verifies their own phone with a separate verification
payment (`POST /account/verification/complete`), and creates their first shop,
with its **own** number and for free, via `POST /shops`. The merchant and each
shop have their own phone numbers; the only charges are the registration fee and
the phone verification fee. Sending shop fields to `POST /merchants` still works
but is deprecated.

The whole journey, with every request and response, is in one place:
[merchant-onboarding.md](merchant-onboarding.md).

---

## Step by step: registering a merchant

The full sequence for one new shop, in the order the app calls it. Every step
shows what the client sends, what it gets back and what to do next. Base URL is
`/api/v1`; every request sends `Accept: application/json`.

### Step 1 — Load the state dropdown

`GET /geo/states`

The app fills the state picker from `data.states`. Keep the `code`
(`maroodi_jeex`); show the `name`.

### Step 2 — Enter the phone number and check it

`POST /registration/phone/check` with `{ "phone_number": "+252654110101" }`.
Local formats (`0654110101`, `654110101`) are normalised to `+252…` by the server.

Branch on `data`:

| Result | Meaning | Client does |
| --- | --- | --- |
| `available: false` | Number already has an account, or belonged to a shop that was closed (see `message`) | Send the user to login (`POST /auth/lookup`), or ask for another number |
| `available: true`, `invoice_required: true` | New number, fee not paid | Go to step 3 |
| `available: true`, `invoice_required: false`, `pending_invoice` set | Fee already paid, account never created | **Skip to step 6** with `pending_invoice.invoice_id`. Do not charge again. |

### Step 3 — Show the price

`GET /registration/quote` (optionally `?purpose=registration`)

Show `total.slsh.display` (for example `550 SLSH`), optionally with `total.usd.display`
and the split into `base` and `exelo_fee`, and keep `quote_id`. The
quote is valid for 15 minutes; after that step 4 returns `410 quote.expired`
and the app fetches a new one.

### Step 4 — Ask the customer's wallet to pay

`POST /registration/invoices`

```json
{
  "phone_number": "+252654110101",
  "wallet_number": "+252654110101",
  "rail": "edahab",
  "purpose": "registration",
  "quote_id": "qte_9F3K2MXA1Q",
  "idempotency_key": "b4f1c8de-92a7-4f10-9c33-0a5e7d2b6f81"
}
```

- Generate `idempotency_key` **once** when the user taps Pay and reuse it on every
  retry. A retry returns the same invoice instead of sending a second prompt.
- `rail` must match the wallet number: `edahab` for `65`, `66`, `62`; `zaad` for
  `63`. A mismatch returns `422 payment.wallet_invalid`.

`202` response: keep `data.invoice_id` and show "Approve the payment on your
phone". `502 payment.provider_unavailable` means the wallet provider is down;
retry with the same key.

### Step 5 — Wait for the payment

`GET /registration/invoices/{invoice_id}` every `poll_after` seconds (3), and stop
polling while the app is in the background.

| `data.status` | Client does |
| --- | --- |
| `pending` | Keep polling |
| `paid` | Go to step 6. `receipt_no` and `paid_at` are available for the receipt. |
| `failed`, `cancelled`, `expired` | Show `error_reason` if present, offer "Try again" and go back to step 4 with a **new** `idempotency_key` |

An invoice expires 10 minutes after it is issued.

### Step 6 — Create the merchant

`POST /merchants`

```json
{
  "invoice_id": "inv_01K3…",
  "first_name": "Kalid",
  "last_name": "Ahmed",
  "dob": "1990-01-01",
  "email": "kalid@exelo.co",
  "phone_number": "+252654110101",
  "business_name": "Exelo Retail",
  "state": "maroodi_jeex",
  "city": "Hargeisa",
  "merchant_code": "EXL-102",
  "other_merchant_code": "ZAAD-88"
}
```

On `201` the server has, in one transaction, created the user (with no usable
password), the merchant (`location` = `"Hargeisa, Maroodi Jeex"`), the Silver
subscription, and marked the invoice as used. **No token is issued yet.**
`data.next_step` is `set_pin`.

| Status / code | Client does |
| --- | --- |
| `402 registration.invoice_unpaid` | Go back to step 5 |
| `409 registration.invoice_consumed` | The account already exists (a retry after a timeout). Go to step 7. |
| `409 registration.phone_taken` | Send the user to login |
| `422 validation.failed` | Show each message under its field (`error.details`) |

### Step 7 — Set the PIN and sign in

`POST /auth/pin` with header `X-EXELO-Device-Id: <install uuid>`

```json
{ "phone_number": "+252654110101", "pin": "2580", "pin_confirmation": "2580" }
```

`201` returns the token, `merchant`, `subscription` and `permissions` — enough to
open the dashboard without another call. Store `data.token` and send it as
`Authorization: Bearer <token>` from now on.

`422 auth.pin_too_weak` (`0000`, `1234`, repeated digits) asks for another PIN.
`409 auth.pin_already_set` means the PIN exists; use login instead.

### Step 8 — Later: verify payout wallets

From inside the app, once the merchant adds their Zaad / eDahab numbers, call
`POST /merchants/{id}/verification/complete` (Bearer token).

### If the app is closed partway

On the next launch, the phone number decides where to resume:

| State on the server | `POST /auth/lookup` says | Resume at |
| --- | --- | --- |
| Nothing paid | `exists: false` | Step 2 |
| Paid, no account | `exists: false`; `POST /registration/phone/check` then returns `pending_invoice` | Step 6 |
| Account, no PIN | `exists: true`, `has_pin: false` | Step 7 |
| Account and PIN | `exists: true`, `has_pin: true` | Login |

---

## GET /geo/states

The states offered in the registration dropdown. Server-driven so adding a
region does not require an app release — the client currently hardcodes these
five.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "country": "SO",
    "states": [
      { "code": "awdal",        "name": "Awdal" },
      { "code": "maroodi_jeex", "name": "Maroodi Jeex" },
      { "code": "togdheer",     "name": "Togdheer" },
      { "code": "sahil",        "name": "Sahil" },
      { "code": "sool",         "name": "Sool" }
    ]
  }
}
```

The client submits `state` as the `code`; the server stores both and returns
`name` for display.

---

## GET /registration/quote

What the signup fee costs, including the customer-side charge and the amount
that reaches EXELO. Replaces the registration use of
`POST /api/merchant/transaction/process`.

**Query**

| Param | Type | Required | Notes |
| --- | --- | --- | --- |
| `purpose` | enum | no | `registration` (default) \| `verification` |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "purpose": "registration",
    "base": {
      "slsh": { "amount": 500, "currency": "SLSH", "display": "500 SLSH" },
      "usd": { "amount": 5, "currency": "USD", "display": "$0.05" }
    },
    "exelo_fee": {
      "slsh": { "amount": 50, "currency": "SLSH", "display": "50 SLSH" },
      "usd": { "amount": 0, "currency": "USD", "display": "$0.00" }
    },
    "total": {
      "slsh": { "amount": 550, "currency": "SLSH", "display": "550 SLSH" },
      "usd": { "amount": 5, "currency": "USD", "display": "$0.05" }
    },
    "quote_id": "qte_01JBXR2K8M",
    "expires_at": "2026-09-18T14:18:11Z"
  }
}
```

`quote_id` is passed to [`POST /registration/invoices`](#post-registrationinvoices)
so the amount the merchant was shown is the amount they are charged, even if
the fee schedule changes mid-signup.

---

## POST /registration/phone/check

Whether a number can be registered. Replaces both
`POST /api/merchants/checkForDuplicatePhoneNumber` and
`POST /api/login/checkInvoice` — the legacy flow called them separately to
learn overlapping facts.

**Request**

```json
{ "phone_number": "+252634110101" }
```

**Response `200` — available**

```json
{
  "success": true,
  "message": "Number available",
  "data": {
    "available": true,
    "registration_complete": false,
    "invoice_required": true,
    "pending_invoice": null
  }
}
```

**Response `200` — already registered**

```json
{
  "success": true,
  "message": "This number already has an EXELO account",
  "data": {
    "available": false,
    "registration_complete": true,
    "invoice_required": false,
    "pending_invoice": null
  }
}
```

**Response `200` — signup abandoned after paying**

```json
{
  "success": true,
  "message": "You already paid. Continue where you left off.",
  "data": {
    "available": true,
    "registration_complete": false,
    "invoice_required": false,
    "pending_invoice": {
      "invoice_id": "inv_01JBXR3P7Q",
      "status": "paid",
      "paid_at": "2026-09-18T13:41:02Z"
    }
  }
}
```

This third case matters on a weak link: a merchant who paid and then lost
connectivity must not be charged twice. The client resumes with the existing
`invoice_id`.

---

## POST /registration/invoices

Pushes a payment request to the customer's mobile wallet. Replaces
`POST /api/merchant/invoice/issue` and `POST /api/zaad/issue` for the
registration and verification cases.

**Request**

```json
{
  "phone_number": "+252634110101",
  "wallet_number": "+252634110101",
  "rail": "edahab",
  "purpose": "registration",
  "quote_id": "qte_01JBXR2K8M",
  "idempotency_key": "b4f1c8de-92a7-4f10-9c33-0a5e7d2b6f81"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `phone_number` | string | yes | The account being registered |
| `wallet_number` | string | yes | Which wallet to bill; often the same number |
| `rail` | enum | yes | `zaad` \| `edahab` |
| `purpose` | enum | yes | `registration` \| `verification` |
| `quote_id` | string | yes | From `/registration/quote` |
| `idempotency_key` | string | yes | Also accepted as the `Idempotency-Key` header |

**Response `202`**

```json
{
  "success": true,
  "message": "Approve the payment on your phone",
  "data": {
    "invoice_id": "inv_01JBXR3P7Q",
    "status": "pending",
    "rail": "edahab",
    "amount": { "amount": 550, "currency": "SLSH", "display": "550 SLSH" },
    "next_action": "await_customer_approval",
    "poll_after": 3,
    "expires_at": "2026-09-18T14:23:11Z"
  }
}
```

### Rail differences

Zaad is a two-step rail: it issues a transaction that must then be committed.
In the legacy API the client did this itself via `POST /api/zaad/commit` with
`transactionId` and `referenceId`. In v1 the server owns the commit and the
client only polls. If a rail ever needs explicit customer confirmation from the
app, `next_action` will be `confirm` and
[`POST /payments/charges/{id}/confirm`](payments.md#post-paymentschargesidconfirm)
is used.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `409` | `registration.phone_taken` | Already a complete account |
| `410` | `quote.expired` | Fetch a new quote |
| `422` | `payment.wallet_invalid` | Wallet number not valid for that rail |
| `502` | `payment.provider_unavailable` | Wallet provider down — retry |

---

## GET /registration/invoices/{invoice_id}

Polls the payment. Replaces `POST /api/merchant/invoice/status`, which was a
POST despite being a read.

**Response `200` — still waiting**

```json
{
  "success": true,
  "data": {
    "invoice_id": "inv_01JBXR3P7Q",
    "status": "pending",
    "poll_after": 3,
    "expires_at": "2026-09-18T14:23:11Z"
  }
}
```

**Response `200` — paid**

```json
{
  "success": true,
  "message": "Payment received",
  "data": {
    "invoice_id": "inv_01JBXR3P7Q",
    "status": "paid",
    "paid_at": "2026-09-18T14:05:40Z",
    "amount": { "amount": 550, "currency": "SLSH", "display": "550 SLSH" },
    "receipt_no": "EXL-RCP-88412"
  }
}
```

| `status` | Meaning |
| --- | --- |
| `pending` | Waiting for the customer to approve on their handset |
| `paid` | Settled — proceed to `POST /merchants` |
| `failed` | Declined or insufficient balance; `error_reason` explains |
| `expired` | Not approved in time; issue a new invoice |
| `cancelled` | Customer rejected the prompt |

`poll_after` tells the client how many seconds to wait. On a Somaliland link
the client should honour it rather than polling on a fixed tight timer, and
should stop polling when the screen is backgrounded.

---

## POST /merchants

Creates the merchant account once the fee is paid. Replaces
`POST /api/merchants`.

**Request**

```json
{
  "invoice_id": "inv_01JBXR3P7Q",
  "first_name": "Kalid",
  "last_name": "Ahmed",
  "dob": "1990-01-01",
  "email": "kalid@exelo.co",
  "phone_number": "+252634110101",
  "business_name": "Exelo Retail",
  "state": "maroodi_jeex",
  "city": "Hargeisa",
  "merchant_code": "EXL-102",
  "other_merchant_code": "ZAAD-88"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `invoice_id` | string | yes | Must be `paid` and unconsumed |
| `first_name` | string | yes | |
| `last_name` | string | yes | |
| `dob` | date | yes | `YYYY-MM-DD` |
| `email` | string | no | |
| `phone_number` | string | yes | Must match the invoice |
| `business_name` | string | yes | |
| `state` | string | yes | A `code` from `/geo/states` |
| `city` | string | yes | Free text |
| `merchant_code` | string | no | |
| `other_merchant_code` | string | no | |

### On `state`, `city` and `location`

The legacy API had a single free-text `location`. The app now collects a state
from a dropdown and a city as text, and sends `city` in the `location` field
with `state` alongside.

v1 stores `state` and `city` as first-class columns and exposes
**`location` as a read-only computed string** (`"Hargeisa, Maroodi Jeex"`), so
receipts, profile screens and older clients that still read `location` keep
working unchanged.

**Response `201`**

```json
{
  "success": true,
  "message": "Account created. Set your PIN to continue.",
  "data": {
    "merchant": {
      "id": 12,
      "business_name": "Exelo Retail",
      "merchant_code": "EXL-102",
      "state": "Maroodi Jeex",
      "state_code": "maroodi_jeex",
      "city": "Hargeisa",
      "location": "Hargeisa, Maroodi Jeex",
      "created_at": "2026-09-18T14:06:02Z"
    },
    "user": { "id": 91, "type": "merchant", "has_pin": false },
    "subscription": { "plan_id": 2, "plan": "silver", "status": "active" },
    "next_step": "set_pin"
  }
}
```

New merchants start on Silver. No token is issued here — the client proceeds to
[`POST /auth/pin`](auth.md#post-authpin), which returns one.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `402` | `registration.invoice_unpaid` | Fee not settled |
| `409` | `registration.invoice_consumed` | That invoice already created an account |
| `409` | `registration.phone_taken` | Number registered while the form was open |
| `422` | `validation.failed` | Per-field messages in `error.details` |

```json
{
  "success": false,
  "message": "Please check the form",
  "error": {
    "code": "validation.failed",
    "details": {
      "state": ["Choose a state"],
      "dob": ["Enter a valid date of birth"]
    }
  }
}
```

---

## POST /merchants/{id}/verification/complete

Marks wallet verification finished once the merchant's payout numbers are
confirmed. Replaces `POST /api/merchants/verificationComplete`.

**Request**

```json
{ "invoice_id": "inv_01JBXR9T2K" }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `invoice_id` | string | no | Present when verification carries a fee |

**Response `200`**

```json
{
  "success": true,
  "message": "Your payment numbers are verified",
  "data": {
    "verified": true,
    "wallets": {
      "zaad_number": { "number": "+252632222222", "status": "verified" },
      "edahab_number": { "number": "+252631111111", "status": "verified" },
      "golis_number": { "number": null, "status": "not_set" },
      "evc_number": { "number": null, "status": "not_set" }
    }
  }
}
```

Wallet status values are `verified`, `pending`, `rejected` and `not_set`. The
legacy API returned the bare string `"Verified"` per wallet; v1 keeps the
number alongside so the payment screen does not need a second call.


---

## Implementation notes

Implemented in `RegistrationController` / `RegistrationService`, served under
`/api/v1`. Wallet calls live in `WalletGateway`.

- **Fees are set by an admin** in the admin panel, **Payment Fees**
  (`/admin/settings/payment-fees`, permissions `view-setting` / `edit-setting`).
  Each purpose has a **base price** and an **EXELO fee**, stored in the `settings`
  table (`registration_fee`, `registration_fee_charge`, `verification_fee`,
  `verification_fee_charge`, whole SLSH). The quote returns them separately as
  `base`, `exelo_fee` and `total` (base + EXELO fee), each in SLSH and USD. A fee
  left empty falls back
  to `config/exelo.php` (`REGISTRATION_FEE`, `REGISTRATION_FEE_CHARGE`,
  `VERIFICATION_FEE`, `VERIFICATION_FEE_CHARGE`; defaults 500 + 50 SLSH). Code:
  `PaymentSettingsService` (cached; cleared on save). A change applies to new quotes
  at once; quotes live in the cache for 15 minutes, and an issued invoice keeps the
  amount it was quoted. The invoice also records the split in
  `meta.base` / `meta.exelo_fee`.
- **Invoices.** `invoices` gained `public_id` (`inv_...`), `rail`,
  `wallet_number`, `expires_at`, `paid_at`, `consumed_at`, `error_reason`.
  Legacy paid invoices get a `public_id` the first time
  `POST /registration/phone/check` finds them.
- **Polling.** Each `GET /registration/invoices/{id}` makes one provider call
  (eDahab `checkInvoiceStatus`, Zaad `API_PREAUTHORIZE_COMMIT`). Invoices expire
  after 10 minutes. Zaad is committed by the server; the client only polls.
- **Idempotency.** `idempotency_key` (or the `Idempotency-Key` header) is scoped
  to the phone number and replays return the original invoice without a second
  provider call.
- **`POST /merchants`** runs in one transaction: it checks the invoice
  (consumed, then unpaid), the phone and the merchant codes before it creates
  the user, merchant and Silver subscription. The user gets a random password,
  so nobody can sign in before `POST /auth/pin`. Wallet numbers are **not**
  copied onto the merchant here (the legacy API did); they are set by wallet
  verification.
- **State and city.** `merchants.state`, `state_code` and `city` are new
  columns; `location` is stored as `"City, State"`.
- **Verification complete** reports each wallet as `verified` when a number is
  on file, otherwise `not_set`. `pending` and `rejected` are not produced yet
  because there is no per-wallet verification state.
