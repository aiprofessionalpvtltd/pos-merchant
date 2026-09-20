# Auth & Session

Merchants and employees authenticate identically in v1. The legacy API split
every act across two endpoint families (`/api/login/*` and `/api/employee/*`);
these are unified, and the response tells the client which kind of user signed
in.

Login is **phone number + PIN**. There are no passwords.

| Method | Path | Auth |
| --- | --- | --- |
| POST | [`/auth/lookup`](#post-authlookup) | — |
| POST | [`/auth/pin/login`](#post-authpinlogin) | — |
| POST | [`/auth/pin`](#post-authpin) | — |
| PATCH | [`/auth/pin`](#patch-authpin) | Bearer |
| POST | [`/auth/pin/verify`](#post-authpinverify) | Bearer |
| POST | [`/auth/pin/reset/request`](#post-authpinresetrequest) | — |
| POST | [`/auth/pin/reset/verify`](#post-authpinresetverify) | — |
| POST | [`/auth/pin/reset`](#post-authpinreset) | Reset token |
| GET | [`/auth/session`](#get-authsession) | Bearer |
| POST | [`/auth/logout`](#post-authlogout) | Bearer |

---

## Login flow

```
POST /auth/lookup            → who is this phone, do they have a PIN?
   │
   ├── has_pin: false  ──────→ POST /auth/pin          (create first PIN, returns token)
   └── has_pin: true   ──────→ POST /auth/pin/login    (returns token)
```

Forgotten PIN:

```
POST /auth/pin/reset/request → OTP sent by SMS
POST /auth/pin/reset/verify  → returns a short-lived reset_token
POST /auth/pin/reset         → sets the new PIN, returns a session token
```

---

## POST /auth/lookup

Identifies a phone number before asking for a PIN. Replaces `POST /api/login`,
`POST /api/employee/getMerchantDetail` and `POST /api/login/checkInvoice`.

Deliberately vague on failure: it does not reveal whether an unknown number is
unregistered, to avoid enumerating merchants.

**Request**

```json
{ "phone_number": "+252634110101" }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `phone_number` | string | yes | E.164. The server also accepts local format and normalises. |

**Response — known user**

```json
{
  "success": true,
  "message": "Enter your PIN",
  "data": {
    "exists": true,
    "user_type": "merchant",
    "has_pin": true,
    "display_name": "Kalid Ahmed",
    "business_name": "Exelo Retail",
    "registration": { "complete": true, "invoice_required": false }
  }
}
```

| Field | Type | Notes |
| --- | --- | --- |
| `exists` | bool | Whether the number is registered |
| `user_type` | enum | `merchant` \| `employee` |
| `has_pin` | bool | `false` → send to PIN creation, not PIN login |
| `display_name` | string | Shown above the PIN pad |
| `business_name` | string\|null | Shop name, for employees signing in |
| `registration.complete` | bool | `false` → registration was abandoned partway |
| `registration.invoice_required` | bool | `true` → signup fee still unpaid |

**Response — unknown number**

```json
{
  "success": true,
  "message": "Create an account to continue",
  "data": { "exists": false, "user_type": null, "has_pin": false }
}
```

---

## POST /auth/pin/login

Exchanges phone + PIN for a session token. Replaces
`POST /api/login/verifyUser` and `POST /api/employee/verifyEmployee`.

**Request**

```json
{
  "phone_number": "+252634110101",
  "pin": "1234"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `phone_number` | string | yes | |
| `pin` | string | yes | 4 digits |

`X-EXELO-Device-Id` is required; the token is bound to it.

**Response `200`**

```json
{
  "success": true,
  "message": "Welcome back, Kalid",
  "data": {
    "token": "18|vJ2kQx9fR7pLmN3sT6wY0aB4cD8eF1gH",
    "expires_at": "2027-03-18T14:03:11Z",
    "user": {
      "id": 91,
      "type": "merchant",
      "first_name": "Kalid",
      "last_name": "Ahmed",
      "short_name": "KA",
      "email": "kalid@exelo.co",
      "phone_number": "+252634110101"
    },
    "merchant": {
      "id": 12,
      "business_name": "Exelo Retail",
      "merchant_code": "EXL-102",
      "state": "Maroodi Jeex",
      "city": "Hargeisa",
      "location": "Hargeisa, Maroodi Jeex",
      "currency": "USD",
      "alt_currency": "SLSH",
      "exchange_rate": 8000
    },
    "subscription": {
      "plan_id": 1,
      "plan": "gold",
      "status": "active",
      "expires_at": "2026-12-31T23:59:59Z"
    },
    "permissions": [
      { "key": "pos", "name": "POS" },
      { "key": "inventory", "name": "Inventory" },
      { "key": "transactions", "name": "Transactions" },
      { "key": "employees", "name": "Employee Management" },
      { "key": "reports", "name": "Reports" }
    ]
  }
}
```

Everything the app needs to boot is in this one response — profile, plan and
permissions. The legacy client made a second call to
`/api/merchants/subscriptions/current` at splash to learn the plan; that round
trip is gone.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.invalid_credentials` | Wrong PIN |
| `423` | `auth.locked` | Too many failed attempts; `error.details.retry_after` seconds |
| `403` | `auth.registration_incomplete` | Signup fee unpaid — resume registration |
| `403` | `auth.employee_disabled` | Staff member was removed |

```json
{
  "success": false,
  "message": "That PIN is not correct. 2 attempts left.",
  "error": {
    "code": "auth.invalid_credentials",
    "details": { "attempts_remaining": 2 }
  }
}
```

---

## POST /auth/pin

Creates the first PIN, immediately after registration or when staff are added.
Replaces `POST /api/merchants/store-pin` and `POST /api/employee/store-pin`.

**Request**

```json
{
  "phone_number": "+252634110101",
  "pin": "1234",
  "pin_confirmation": "1234"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `phone_number` | string | yes | Must resolve to a user with `has_pin: false` |
| `pin` | string | yes | Exactly 4 digits |
| `pin_confirmation` | string | yes | Must match `pin` |

**Response `201`** — identical body to [`/auth/pin/login`](#post-authpinlogin);
the user is signed in.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `409` | `auth.pin_already_set` | Use PIN reset instead |
| `422` | `validation.failed` | `pin` not 4 digits, or confirmation mismatch |
| `422` | `auth.pin_too_weak` | Rejects `0000`, `1234`, and repeated digits |

---

## PATCH /auth/pin

Changes the PIN of the signed-in user. Replaces
`POST /api/merchants/change-pin`.

**Request**

```json
{
  "current_pin": "1234",
  "pin": "5678",
  "pin_confirmation": "5678"
}
```

**Response `200`**

```json
{
  "success": true,
  "message": "PIN updated",
  "data": { "changed_at": "2026-09-18T14:03:11Z", "other_devices_signed_out": true }
}
```

Changing a PIN revokes tokens on every *other* device. The calling device keeps
its token.

**Errors** — `401 auth.invalid_credentials` if `current_pin` is wrong.

---

## POST /auth/pin/verify

Re-asserts the PIN of the signed-in merchant to authorise a sensitive action,
without issuing a new token. Used before adding staff. Replaces
`POST /api/login/verifyUserPin`.

**Request**

```json
{ "pin": "1234", "scope": "employees.create" }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `pin` | string | yes | |
| `scope` | string | no | What the confirmation is for; recorded in the audit log |

**Response `200`**

```json
{
  "success": true,
  "message": "Confirmed",
  "data": {
    "confirmation_token": "cnf_01JBXQ8M2P",
    "expires_at": "2026-09-18T14:08:11Z"
  }
}
```

`confirmation_token` is passed to the protected call (e.g.
`POST /employees`) and is valid for five minutes.

---

## POST /auth/pin/reset/request

Starts a forgotten-PIN reset by sending an OTP. Replaces
`POST /api/user/forgot-password`.

**Request**

```json
{ "phone_number": "+252634110101" }
```

The legacy API required a `type` of `merchant` or `employee`; v1 resolves it
from the phone number.

**Response `200`**

```json
{
  "success": true,
  "message": "We sent a code to +252 63 *** 0101",
  "data": {
    "masked_phone": "+252 63 *** 0101",
    "expires_in": 300,
    "resend_after": 60
  }
}
```

The OTP is **never** returned in the response body. The legacy endpoint echoed
`data.otp`, which defeats the point of sending it by SMS.

**Errors** — `429 auth.otp_throttled` with `error.details.retry_after`.

---

## POST /auth/pin/reset/verify

Verifies the OTP and returns a short-lived token authorising the PIN change.
Replaces `POST /api/user/verify-otp-reset-password`.

**Request**

```json
{ "phone_number": "+252634110101", "otp": "418902" }
```

**Response `200`**

```json
{
  "success": true,
  "message": "Code confirmed",
  "data": {
    "reset_token": "rst_01JBXQ9N4T7V",
    "expires_at": "2026-09-18T14:13:11Z"
  }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `422` | `auth.otp_invalid` | Wrong code; `error.details.attempts_remaining` |
| `410` | `auth.otp_expired` | Request a new one |

---

## POST /auth/pin/reset

Sets the new PIN using the reset token. Replaces
`POST /api/user/reset-password`.

**Request**

```json
{
  "reset_token": "rst_01JBXQ9N4T7V",
  "pin": "5678",
  "pin_confirmation": "5678"
}
```

**Response `200`** — same body as [`/auth/pin/login`](#post-authpinlogin); the
user is signed in on this device and signed out everywhere else.

> **Removed from the legacy flow:** resetting a PIN used to require paying a
> 500 SLSH fee through `merchant/invoice/issue` with `type: "Pin"`. If that
> charge is still wanted it belongs in [Payments](payments.md) as a
> `purpose: "pin_reset"` charge, gating this call — not embedded in the auth
> flow. **Confirm whether this fee is still charged.**

---

## GET /auth/session

Returns the current user, merchant, plan and permissions. Replaces
`GET /api/login/userinfo`. This is what the client caches as its profile and
re-fetches on resume.

**Request** — no body.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "user": {
      "id": 91,
      "type": "merchant",
      "first_name": "Kalid",
      "last_name": "Ahmed",
      "short_name": "KA",
      "email": "kalid@exelo.co",
      "phone_number": "+252634110101"
    },
    "merchant": {
      "id": 12,
      "business_name": "Exelo Retail",
      "merchant_code": "EXL-102",
      "other_merchant_code": "ZAAD-88",
      "state": "Maroodi Jeex",
      "city": "Hargeisa",
      "location": "Hargeisa, Maroodi Jeex",
      "currency": "USD",
      "alt_currency": "SLSH",
      "exchange_rate": 8000,
      "wallets": {
        "zaad_number": "+252632222222",
        "edahab_number": "+252631111111"
      }
    },
    "subscription": { "plan_id": 1, "plan": "gold", "status": "active" },
    "permissions": [
      { "key": "pos", "name": "POS" },
      { "key": "inventory", "name": "Inventory" },
      { "key": "transactions", "name": "Transactions" },
      { "key": "employees", "name": "Employee Management" },
      { "key": "reports", "name": "Reports" }
    ],
    "shift": { "active": true, "shift_id": 5512, "started_at": "2026-09-18T06:02:00Z" },
    "server_time": "2026-09-18T14:03:11Z"
  }
}
```

`shift` is included so the work timer can restore state without a second call.

**Offline behaviour.** The client caches this payload and renders from it when
the request fails. Only a `401` invalidates the cache.

---

## POST /auth/logout

Revokes the token for this device. Replaces `POST /api/logout`.

**Request**

```json
{ "all_devices": false }
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `all_devices` | bool | no | Default `false`. `true` revokes every token for this user. |

**Response `200`**

```json
{
  "success": true,
  "message": "Signed out",
  "data": { "revoked_devices": 1 }
}
```

### Client obligation on logout

The legacy client cleared only `auth_token` and `user_detail`, leaving the held
register ticket, sync queues, cached plan and last-known dashboard in place —
so the next person to sign in on that device saw the previous shop's data.

On logout the client **must** clear:

| Store | Keys |
| --- | --- |
| Preferences | `auth_token`, `user_detail`, `subscription_plan_id`, `last_known_*`, `held_register_ticket` |
| SQLite | `products`, `categories`, `categoriesid`, all report and stats tables |
| Secure storage | Cached NFC key |

**Except** unsynced queues. If `pending_creates`, `pending_updates` or
`held_register_ticket` are non-empty, logout must be blocked with a warning —
that data exists nowhere else. Drain it via
[`POST /sync/mutations`](sync.md#post-syncmutations) first.


---

## Implementation notes

Implemented in `AuthController` / `AuthService`, served under `/api/v1/auth/*`.
Legacy `/api/login/*` and `/api/employee/*` routes are unchanged.

- **Device binding.** `X-EXELO-Device-Id` is required on `pin/login`, `pin` and
  `pin/reset`. The Sanctum token is named `device:<id>`; logging in again on
  the same device replaces that device's previous token.
- **Lockout.** 5 wrong PINs lock the account for 15 minutes (`auth.locked`,
  `retry_after` in seconds). A correct PIN resets the counter.
- **PIN storage.** v1 stores only the hash (`users.password`) and marks
  `users.pin_set_at`. It clears the legacy plaintext `users.pin` column, so a PIN
  set through v1 makes the legacy `is_pin` flag read `false`.
- **`auth.pin_not_set` (409).** `POST /auth/pin/login` for an account with no PIN
  yet. The legacy code stored a default password of `1234` for these accounts;
  v1 refuses to accept it.
- **OTP.** 6 digits, hashed at rest, valid 5 minutes, 5 attempts, resend after
  60 seconds. An unknown number gets the normal `200` so the endpoint cannot
  enumerate accounts. SMS goes through `App\Services\Sms\SmsSender`, which is
  bound to `LogSmsSender` (logs in `local` only) until a provider is chosen.
- **Reset token** is single use and valid 10 minutes. Resetting revokes every
  token for the user, then signs in the calling device.
- **`confirmation_token`** is stored for five minutes with its `scope`. It is single-use:
  `POST /employees` consumes it (scope `employees.create`), see [employees.md](employees.md).
- **Rate limits.** `lookup` 30/min, login and PIN create/reset 20/min, OTP
  request and verify 10/min per IP.
- **Merchant fields.** `currency` is `USD`, `alt_currency` is `SLSH`,
  `exchange_rate` comes from `CONVERSION_RATE`. Merchants without a `city`
  column value fall back to `location`.
