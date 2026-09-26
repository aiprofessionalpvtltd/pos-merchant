# Merchant Onboarding API: Account, Verification and Shops

The complete API, in the order the app calls it, for:

- **A.** Creating a **merchant account** (the person)
- **B.** Verifying the merchant's **phone number**
- **C.** Creating the **first shop**
- **D.** Signing in again later
- **E.** Adding more shops, switching, editing and closing them

Every endpoint is shown with its request, its response and the errors the app has
to handle. Reference pages: [registration.md](registration.md), [auth.md](auth.md),
[merchant.md](merchant.md), [multiple-shop.md](multiple-shop.md),
[errors.md](errors.md).

---

## The model

| | Merchant account | Shop |
| --- | --- | --- |
| Table | `merchants` (sign-in in `users`) | `shops` (`shops.merchant_id` → `merchants.id`) |
| What it is | The person who signs in | A business: stock, sales, staff, plan, payout wallets |
| Phone number | **The merchant's own mobile.** Signs in with it | **The shop's own mobile.** Different from the merchant's and from every other shop's |
| How many | One per person | As many as the merchant wants |
| Cost | **Registration fee** once, then **phone verification fee** once | **Free** |

One merchant has many shops. The full table layout is in
[data-model.md](data-model.md).

**Only two charges, both once per merchant:** the registration fee (creates the
account) and the phone verification fee (verifies the merchant's number). Every
shop, the first included, is free.

After verification, the merchant's verified number becomes **every new shop's
verified payout wallet**, so a new shop takes Zaad / eDahab payments straight away
without another fee.

## The journey at a glance

```text
A. CREATE THE MERCHANT ACCOUNT  — registration fee
   POST /registration/phone/check            is the merchant's number free?
   GET  /registration/quote?purpose=registration
   POST /registration/invoices               pay the registration fee
   GET  /registration/invoices/{id}          poll until paid
   POST /merchants                           create the account (no shop yet)
   POST /auth/pin                            set PIN → token; onboarding.next_step = "verify_phone"

B. VERIFY THE MERCHANT'S PHONE  — verification fee, paid FROM that phone
   GET  /registration/quote?purpose=verification
   POST /registration/invoices               purpose "verification"
   GET  /registration/invoices/{id}          poll until paid
   POST /account/verification/complete       phone verified; next_step = "create_shop"

C. CREATE THE FIRST SHOP  — free, its own number
   GET  /geo/states                          state dropdown
   POST /shops                               shop created, active, payout wallet verified ✅

D. SIGN IN AGAIN
   POST /auth/lookup  →  POST /auth/pin/login

E. MORE SHOPS  — free
   POST /shops · POST /shops/{id}/select · GET /shops · GET/PATCH/DELETE /shops/{id}

F. THE MERCHANT AND ALL THEIR SHOPS
   GET  /account                             merchant + every shop with its details
   PATCH /account                            edit the merchant's own details
   GET  /account/employees                   the staff of every shop (see employees.md)
```

The app follows `onboarding.next_step` from the sign-in and session responses:

| `onboarding.next_step` | Show |
| --- | --- |
| `verify_phone` | "Verify your phone number" (B) |
| `create_shop` | "Create your shop" (C) |
| `null` | The shop's home screen |

---

## Conventions

**Base URL:** `https://<host>/api/v1`

**Headers**

| Header | When | Value |
| --- | --- | --- |
| `Accept` | Every call | `application/json` |
| `Content-Type` | Calls with a body | `application/json` |
| `X-EXELO-Device-Id` | `POST /auth/pin`, `POST /auth/pin/login`, `POST /auth/pin/reset` | A UUID the app creates once per install and never changes |
| `Authorization` | Every call after sign-in | `Bearer <token>` |
| `X-EXELO-Confirmation` | `DELETE /shops/{id}` | Token from `POST /auth/pin/verify` |

**Envelope.** Branch on `error.code`, never on `message`; `message` is safe to show.

```json
{ "success": true, "message": "…", "data": { }, "meta": { "request_id": "req_…", "server_time": "2026-09-26T08:00:00Z" } }
```

```json
{ "success": false, "message": "…", "error": { "code": "domain.reason", "field": "phone_number", "details": { } }, "meta": { "…": "…" } }
```

**Phone numbers.** Send `+252…` or a local format (`0654110101`); the server
normalises it. Zaad numbers start `63`; eDahab `65`, `66` or `62`.

**Money.** `{ "amount": 550, "currency": "SLSH", "display": "550 SLSH" }`. SLSH is
whole shillings, USD is cents. Wallets are always billed in SLSH.

**Idempotency.** `POST /registration/invoices` takes an `idempotency_key`: a new
UUID when the user taps Pay, reused on every retry of **that** payment. The
registration and verification payments each get their own key.

---

## A. Create the merchant account

For someone with no EXELO account. The number used here is **the merchant's own
mobile**: they sign in with it, and in B they verify it.

### A1. POST `/registration/phone/check` — Is the merchant's number free?

**Request**

```json
{ "phone_number": "+252654110101" }
```

**Response `200`: new number**

```json
{
  "success": true,
  "message": "Number available",
  "data": { "available": true, "registration_complete": false, "invoice_required": true, "pending_invoice": null }
}
```

**Response `200`: already paid, account never created**

```json
{
  "success": true,
  "message": "You already paid. Continue where you left off.",
  "data": {
    "available": true,
    "registration_complete": false,
    "invoice_required": false,
    "pending_invoice": { "invoice_id": "inv_01M2W762W927DAYTDR5DSJYS7G", "status": "paid", "paid_at": "2026-09-26T06:55:42Z" }
  }
}
```

**Response `200`: taken** (a merchant or a shop uses it)

```json
{
  "success": true,
  "message": "This number already has an EXELO account",
  "data": { "available": false, "registration_complete": true, "invoice_required": false, "pending_invoice": null }
}
```

| Result | App does |
| --- | --- |
| `invoice_required: true` | A2 |
| `pending_invoice` set | Skip to A5 with `pending_invoice.invoice_id`. Don't charge again |
| `available: false` | Sign in ([D](#d-sign-in-again)) |

### A2. GET `/registration/quote?purpose=registration` — Registration fee

Set by an admin (Admin → Payment Fees).

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
    "expires_at": "2026-09-26T07:10:42Z"
  }
}
```

Show `total.slsh.display`; the split into `base` and `exelo_fee` is optional.
`quote_id` locks the price for 15 minutes.

### A3. POST `/registration/invoices` — Pay the registration fee

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

| Field | Required | Notes |
| --- | --- | --- |
| `phone_number` | yes | The merchant's own number, being registered |
| `wallet_number` | yes | The Zaad or eDahab number that pays. Usually the same |
| `rail` | yes | `zaad` or `edahab`; must match `wallet_number` |
| `purpose` | yes | `registration` |
| `quote_id` | yes | From A2 |
| `idempotency_key` | yes | New UUID for this payment |

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
    "expires_at": "2026-09-26T07:05:42Z"
  }
}
```

On eDahab, `"prompt": "declined"` means the customer turned the prompt down; the
invoice stays `pending`.

**Errors**

| Status | Code | App does |
| --- | --- | --- |
| `409` | `registration.phone_taken` | Sign in instead |
| `410` | `quote.expired` | New quote (A2) |
| `422` | `payment.wallet_invalid` | Wallet doesn't belong to `rail` |
| `502` | `payment.provider_unavailable` | Retry with the **same** key |

### A4. GET `/registration/invoices/{invoice_id}` — Wait for the payment

Poll every `poll_after` seconds (3).

**Response `200`: paid**

```json
{
  "success": true,
  "message": "Payment received",
  "data": {
    "invoice_id": "inv_01M2W762W927DAYTDR5DSJYS7G",
    "status": "paid",
    "paid_at": "2026-09-26T06:55:42Z",
    "amount": { "amount": 550, "currency": "SLSH", "display": "550 SLSH" },
    "receipt_no": "EXL-RCP-00024"
  }
}
```

| `status` | App does |
| --- | --- |
| `pending` | Keep polling (show "declined" if `prompt: "declined"`) |
| `paid` | A5 |
| `failed`, `expired`, `cancelled` | "Try again": A3 with a **new** key |

An invoice expires after 10 minutes.

### A5. POST `/merchants` — Create the merchant account

Personal details only. **No shop is created here.**

**Request**

```json
{
  "invoice_id": "inv_01M2W762W927DAYTDR5DSJYS7G",
  "first_name": "Kalid",
  "last_name": "Ahmed",
  "dob": "1990-01-01",
  "email": "kalid@exelo.co",
  "phone_number": "+252654110101"
}
```

| Field | Required | Notes |
| --- | --- | --- |
| `invoice_id` | yes | The paid registration invoice from A3 |
| `first_name`, `last_name` | yes | |
| `dob` | yes | `YYYY-MM-DD` |
| `phone_number` | yes | The merchant's number; must match the invoice |
| `email` | no | |

**Response `201`**

```json
{
  "success": true,
  "message": "Account created. Set your PIN to continue.",
  "data": {
    "merchant": null,
    "user": { "id": 109, "type": "merchant", "has_pin": false, "phone_number": "+252654110101" },
    "merchant_account": { "id": 41, "phone_number": "+252654110101", "phone_verified": false },
    "subscription": null,
    "next_step": "set_pin"
  }
}
```

**Errors**

| Status | Code | App does |
| --- | --- | --- |
| `402` | `registration.invoice_unpaid` | Back to A4 |
| `409` | `registration.invoice_consumed` | The account exists (retry after a timeout): A6 |
| `409` | `registration.phone_taken` | Sign in |
| `422` | `validation.failed` | Per field in `error.details` |

> **Deprecated:** sending shop fields (`business_name`, `state`, `city`,
> `merchant_code`, `other_merchant_code`) here still creates the account **and** a
> shop that shares the merchant's number, as older app versions expect. New apps
> must not send them.

### A6. POST `/auth/pin` — Set the PIN and sign in

**Headers:** `X-EXELO-Device-Id`

**Request**

```json
{ "phone_number": "+252654110101", "pin": "2580", "pin_confirmation": "2580" }
```

**Response `201`**

```json
{
  "success": true,
  "message": "PIN created",
  "data": {
    "token": "18|vJ2kQx9fR7pLmN3sT6wY0aB4cD8eF1gH",
    "expires_at": "2027-03-26T06:56:30Z",
    "user": { "id": 109, "type": "merchant", "first_name": "Kalid", "last_name": "Ahmed", "short_name": "KA", "email": "kalid@exelo.co", "phone_number": "+252654110101" },
    "merchant": null,
    "subscription": null,
    "permissions": [ { "key": "pos", "name": "POS" }, "…" ],
    "shops": [],
    "onboarding": { "phone_verified": false, "next_step": "verify_phone" }
  }
}
```

Store `data.token`; send `Authorization: Bearer <token>` from now on. The account
has no shop yet (`merchant: null`, `shops: []`), so go to B.

**Errors:** `400 request.device_id_missing`, `409 auth.pin_already_set` (sign in
instead), `422 auth.pin_too_weak`.

---

## B. Verify the merchant's phone

A **separate payment**, made **from the merchant's own number**. Paying from it
proves the merchant controls it. Once verified, the merchant can create shops, and
the number becomes each new shop's payout wallet.

### B1. GET `/registration/quote?purpose=verification` — Verification fee

Same shape as [A2](#a2-get-registrationquotepurposeregistration--registration-fee),
with `"purpose": "verification"` and the verification price set by the admin.

### B2. POST `/registration/invoices` — Pay from the merchant's number

**Request**

```json
{
  "phone_number": "+252654110101",
  "wallet_number": "+252654110101",
  "rail": "edahab",
  "purpose": "verification",
  "quote_id": "qte_SAWJYM9FNY",
  "idempotency_key": "4e7a0c91-5d2b-4f83-a6e1-9b3c7d2f8a15"
}
```

| Field | Required | Notes |
| --- | --- | --- |
| `phone_number` | yes | The merchant's own number |
| `wallet_number` | yes | **Must be the same number**: it pays for its own verification |
| `rail` | yes | `edahab` or `zaad`, matching the number |
| `purpose` | yes | `verification` |
| `quote_id` | yes | From B1 |
| `idempotency_key` | yes | A **new** UUID, not the registration payment's |

**Response `202`**: as in [A3](#a3-post-registrationinvoices--pay-the-registration-fee).

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `422` | `validation.failed` | `wallet_number`: "Pay from the number you are verifying". Nothing is charged |
| `422` | `validation.failed` | `phone_number`: "This number has no EXELO account" |
| `410` | `quote.expired` | New quote (B1) |
| `502` | `payment.provider_unavailable` | Retry with the same key |

### B3. GET `/registration/invoices/{invoice_id}` — Wait for the payment

As in [A4](#a4-get-registrationinvoicesinvoice_id--wait-for-the-payment).

### B4. POST `/account/verification/complete` — Mark the phone verified

**Headers:** `Authorization`

**Request**

```json
{ "invoice_id": "inv_01M3C0A7Q2F9WJ3K8ZP4H6T1RD" }
```

**Response `200`**

```json
{
  "success": true,
  "message": "Your phone number is verified. You can now create your shop.",
  "data": { "phone_number": "+252654110101", "phone_verified": true, "next_step": "create_shop" }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `402` | `registration.invoice_unpaid` | The fee isn't paid yet |
| `403` | `auth.merchant_only` | Staff can't do this |
| `409` | `registration.invoice_consumed` | That payment was already used |
| `422` | `validation.failed` | Unknown invoice, not for this merchant's number, or not paid from it |

---

## C. Create the first shop

Free. The shop needs **its own mobile number**: not the merchant's, and not used by
any other shop or account.

### C1. GET `/geo/states` — States for the form

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
  }
}
```

### C2. POST `/shops` — Create the shop

**Headers:** `Authorization`

**Request**

```json
{
  "business_name": "Exelo Retail",
  "phone_number": "+252634220202",
  "state": "maroodi_jeex",
  "city": "Hargeisa",
  "email": "shop@exelo.co",
  "merchant_code": "740852",
  "other_merchant_code": "467188"
}
```

| Field | Required | Notes |
| --- | --- | --- |
| `business_name` | yes | |
| `phone_number` | yes | The **shop's** own number; new to EXELO |
| `state` | yes | A code from C1 |
| `city` | yes | |
| `email` | no | The shop's email |
| `merchant_code` | no | The shop's eDahab agent code; unique |
| `other_merchant_code` | no | The shop's Zaad merchant code; unique |

The owner's name and date of birth are copied from the merchant account.

**Response `201`**

```json
{
  "success": true,
  "message": "Exelo Retail is ready. You can start selling.",
  "data": {
    "shop": { "id": 94, "business_name": "Exelo Retail", "phone_number": "+252634220202", "location": "Hargeisa, Maroodi Jeex", "role": "owner", "plan": "silver", "is_active": true },
    "subscription": { "plan": "silver", "status": "active" },
    "payout_wallets": [
      { "rail": "zaad", "label": "Zaad", "number": null, "status": "not_set", "verified_at": null, "is_default": false },
      { "rail": "edahab", "label": "eDahab", "number": "+252654110101", "status": "verified", "verified_at": "2026-09-26T07:02:10+00:00", "is_default": true },
      { "rail": "golis", "label": "Golis", "number": null, "status": "not_set", "verified_at": null, "is_default": false },
      { "rail": "evc", "label": "EVC", "number": null, "status": "not_set", "verified_at": null, "is_default": false }
    ]
  }
}
```

What happens:

- The shop starts on the default (free) plan.
- **The first shop becomes the active shop at once** (`is_active: true`): every call
  with this token now works on it. No switch call is needed.
- **The merchant's verified number becomes the shop's verified payout wallet**, so
  eDahab (or Zaad) is offered at checkout straight away.
- `onboarding.next_step` is now `null`. **Onboarding is complete.**

**Errors**

| Status | Code | App does |
| --- | --- | --- |
| `403` | `auth.merchant_only` | Staff can't create shops |
| `409` | `account.phone_unverified` | Verify the phone first (B) |
| `409` | `registration.phone_taken` | "This number is already used on EXELO. A shop needs its own number." |
| `422` | `validation.failed` | Per field; includes a code already registered |

---

## D. Sign in again

### D1. POST `/auth/lookup` — Who is this number?

Send the **merchant's own** number.

**Request**

```json
{ "phone_number": "+252654110101" }
```

**Response `200`**

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

`business_name` is `null` while the merchant has no shop.

| Result | App does |
| --- | --- |
| `exists: false` | Create an account (A1) |
| `has_pin: false` | Set the PIN (A6) |
| `has_pin: true` | PIN screen (D2) |

### D2. POST `/auth/pin/login` — Sign in

**Headers:** `X-EXELO-Device-Id`

**Request**

```json
{ "phone_number": "+252654110101", "pin": "2580", "shop_id": 94 }
```

`shop_id` is optional: sign straight into that shop. Without it, the active shop
is the one this device used last, else the first.

**Response `200`**: the same `data` as A6 (`token`, `user`, `merchant`,
`subscription`, `permissions`, `shops`, `onboarding`), message
`"Welcome back, Kalid"`. Follow `onboarding.next_step` if it isn't `null`.

**Errors**

| Status | Code | App does |
| --- | --- | --- |
| `401` | `auth.invalid_credentials` | Wrong PIN: `error.details.attempts_remaining` |
| `423` | `auth.locked` | Wait `error.details.retry_after` seconds |
| `403` | `auth.registration_incomplete` | Finish A |
| `403` | `auth.employee_disabled` | Staff member removed |
| `403` | `shop.not_a_member` | `shop_id` isn't one of theirs |
| `409` | `auth.pin_not_set` | A6 |

---

## E. More shops

### E1. POST `/shops` — Add another shop

Free, exactly like [C2](#c2-post-shops--create-the-shop), with the new shop's own
number. Differences from the first shop:

- It is **not** made active (`is_active: false`); switch to it with E2.
- It gets the merchant's verified number as its payout wallet too. Shops of the
  **same** merchant may share a payout number.

Message: `"Berbera Mart is open. Switch to it to start selling."`

### E2. POST `/shops/{id}/select` — Switch shop

**Headers:** `Authorization`. No body, no PIN.

**Response `200`**

```json
{
  "success": true,
  "message": "Now working in Berbera Mart",
  "data": {
    "user": { "id": 109, "type": "merchant", "first_name": "Kalid", "…": "…" },
    "merchant": { "id": 130, "business_name": "Berbera Mart", "…": "…" },
    "subscription": { "plan_id": 2, "plan": "silver", "status": "active" },
    "permissions": [ "…" ],
    "shift": { "active": false, "shift_id": null, "started_at": null },
    "server_time": "2026-09-26T09:10:00Z",
    "shops": [ { "id": 94, "is_active": false, "…": "…" }, { "id": 130, "is_active": true, "…": "…" } ],
    "onboarding": { "phone_verified": true, "next_step": null }
  }
}
```

Every call with this token now acts on that shop. Other devices keep theirs.
**Errors:** `404 shop.not_found`.

### E3. GET `/shops` — My shops

**Response `200`**

```json
{
  "success": true,
  "data": {
    "shops": [
      { "id": 94, "business_name": "Exelo Retail", "phone_number": "+252634220202", "location": "Hargeisa, Maroodi Jeex", "role": "owner", "plan": "gold", "is_active": false },
      { "id": 130, "business_name": "Berbera Mart", "phone_number": "+252634330303", "location": "Berbera, Sahil", "role": "owner", "plan": "silver", "is_active": true }
    ],
    "active_shop_id": 130
  }
}
```

`role` is `owner` or `staff` (staff see only their shop); `plan` is the plan key.

### E4. GET `/shops/{id}` — One shop

**Response `200`**

```json
{
  "success": true,
  "data": {
    "id": 130,
    "version": 1,
    "business_name": "Berbera Mart",
    "merchant_code": null,
    "other_merchant_code": null,
    "owner": { "id": 109, "first_name": "Kalid", "last_name": "Ahmed", "short_name": "KA", "email": null, "phone_number": "+252634330303", "dob": "1990-01-01" },
    "address": { "state": "Sahil", "state_code": "sahil", "city": "Berbera", "location": "Berbera, Sahil" },
    "logo": null,
    "currency": "USD",
    "alt_currency": "SLSH",
    "exchange_rate": 10500,
    "subscription": { "plan_id": 2, "plan": "silver", "status": "active" },
    "created_at": "2026-09-26T09:05:00Z"
  }
}
```

`owner.phone_number` here is the **shop's** number. **Errors:** `404 shop.not_found`.

### E5. PATCH `/shops/{id}` — Edit a shop

Owner only. Send only what changes. `If-Match: <version>` is optional.

**Request**

```json
{ "business_name": "Berbera Mart & Pharmacy", "state": "sahil", "city": "Berbera" }
```

Fields: `business_name`, `first_name`, `last_name`, `email`, `state`, `city`,
`merchant_code`, `other_merchant_code`, `logo_file_id`.

**Response `200`**: the shop as in E4, message `"Shop details saved"`.
**Errors:** `403 auth.merchant_only`, `404 shop.not_found`,
`409 resource.version_conflict`, `422 validation.failed`.

### E6. DELETE `/shops/{id}` — Close a shop

Owner only, PIN confirmed first:

```text
POST /auth/pin/verify { "pin": "2580", "scope": "shops.delete" }   → data.confirmation_token
DELETE /shops/130    X-EXELO-Confirmation: <confirmation_token>
```

**Response `200`**

```json
{
  "success": true,
  "message": "Berbera Mart is closed",
  "data": { "shop_id": 130, "status": "closed", "staff_removed": 2, "active_shop_id": 94 }
}
```

The shop's staff are removed, devices using it move to the first remaining shop,
and its records are kept. Its number stays reserved.

**Errors:** `401 auth.confirmation_required`, `403 auth.merchant_only`,
`404 shop.not_found`, `409 shop.last_shop`, `409 shop.payments_pending`.

### E7. Payout wallets of another shop

A new shop automatically gets the merchant's verified number as its payout wallet
(see [C2](#c2-post-shops--create-the-shop)). To pay a shop out on a **different**
number, verify that number for that shop. **No shop switching is needed.**

```text
GET  /registration/quote?purpose=verification            → quote_id
POST /registration/invoices
     { "phone_number":  "<the SHOP's own number>",       ← identifies the shop
       "wallet_number": "<the number to verify>",        ← it pays, and becomes the payout number
       "rail": "edahab" | "zaad", "purpose": "verification",
       "quote_id": "qte_…", "idempotency_key": "<new uuid>" }
GET  /registration/invoices/{invoice_id}                 → poll until "paid"
POST /merchants/{shop_id}/verification/complete          → { "invoice_id": "inv_…" }
```

- **`{shop_id}` is the shop's id** (`shops[].id` in `GET /account` or `GET /shops`), even
  though the path says `merchants`. It can be **any shop the merchant owns**, not only
  the one the session is working in.
- The wallet that paid is **saved** as the shop's number on that network and marked
  `verified`. Saving it first with `PATCH /merchant/wallets` is optional.
- **`404 merchant.not_found`** means `{shop_id}` isn't one of the merchant's shops. The
  response lists them in `error.details.your_shop_ids`. (A common slip: using the
  *merchant's* id from `GET /account` instead of the shop's.)
- The verification fee is charged for **each number verified**.
- To just look: `GET /merchant/wallets` returns the current shop's wallets plus
  `shops[]` with **every** shop's (see
  [merchant.md, endpoint 3](merchant.md#3-get-apiv1merchantwallets--list-the-payout-wallets-and-their-status)).
  Editing numbers with `PATCH /merchant/wallets` still acts on the current shop.

Details: [registration.md, endpoint 8](registration.md#8-post-apiv1merchantsidverificationcomplete--confirm-payout-wallets).

---

## F. The merchant and all their shops

### F1. GET `/account` — The merchant with every shop's details

Merchant only (`403 auth.merchant_only` for staff). **Headers:** `Authorization`

**Response `200`**

```json
{
  "success": true,
  "data": {
    "merchant": {
      "id": 41,
      "first_name": "Kalid",
      "last_name": "Ahmed",
      "dob": "1990-01-01",
      "email": "kalid@exelo.co",
      "phone_number": "+252654110101",
      "phone_verified": true,
      "phone_verified_at": "2026-09-26T07:02:10Z",
      "created_at": "2026-09-26T06:56:10Z"
    },
    "shops": [
      {
        "id": 94,
        "business_name": "Exelo Retail",
        "phone_number": "+252634220202",
        "email": "shop@exelo.co",
        "address": { "state": "Maroodi Jeex", "state_code": "maroodi_jeex", "city": "Hargeisa", "location": "Hargeisa, Maroodi Jeex" },
        "merchant_code": "740852",
        "other_merchant_code": "467188",
        "subscription": { "plan_id": 1, "plan": "gold", "status": "active", "expires_at": "2026-10-26T07:05:00Z" },
        "payout_wallets": [
          { "rail": "zaad", "label": "Zaad", "number": null, "status": "not_set", "verified_at": null, "is_default": false },
          { "rail": "edahab", "label": "eDahab", "number": "+252654110101", "status": "verified", "verified_at": "2026-09-26T07:05:00+00:00", "is_default": true },
          { "rail": "golis", "label": "Golis", "number": null, "status": "not_set", "verified_at": null, "is_default": false },
          { "rail": "evc", "label": "EVC", "number": null, "status": "not_set", "verified_at": null, "is_default": false }
        ],
        "default_rail": "edahab",
        "staff_count": 3,
        "staff": [
          { "id": 51, "first_name": "Nasra", "last_name": "Yusuf", "role": "Cashier" },
          { "id": 52, "first_name": "Ayaan", "last_name": "Warsame", "role": "Stock keeper" },
          { "id": 53, "first_name": "Bilan", "last_name": "Hersi", "role": "Supervisor" }
        ],
        "is_active": true,
        "created_at": "2026-09-26T07:05:00Z"
      },
      {
        "id": 130,
        "business_name": "Berbera Mart",
        "phone_number": "+252634330303",
        "…": "…",
        "is_active": false
      }
    ],
    "shops_count": 2,
    "active_shop_id": 94,
    "onboarding": { "phone_verified": true, "next_step": null }
  }
}
```

| Field | Meaning |
| --- | --- |
| `merchant` | The `merchants` row: the merchant's own details and number |
| `shops[]` | Every open shop the merchant owns (`shops` rows), oldest first |
| `shops[].subscription` | That shop's plan key, status and expiry |
| `shops[].payout_wallets` | That shop's four payout networks with number and status |
| `shops[].staff_count` | Active staff in that shop |
| `shops[].staff` | The first 5 of them (`id`, name, `role`). The full team of every shop is [`GET /account/employees`](employees.md#12-get-apiv1accountemployees--staff-of-every-shop) |
| `shops[].is_active` | The current shop of **this** session |
| `active_shop_id` | Same, as an id |

### F2. PATCH `/account` — Edit the merchant's own details

Merchant only. Send only what changes. Shops are edited with
[E5](#e5-patch-shopsid--edit-a-shop).

**Request**

```json
{ "first_name": "Kalid", "last_name": "Ahmed", "dob": "1990-01-01", "email": "kalid@exelo.co" }
```

| Field | Notes |
| --- | --- |
| `first_name`, `last_name` | |
| `dob` | `YYYY-MM-DD`, in the past |
| `email` | Unique among merchants; `null` clears it |

The merchant's phone number can't be changed here: it's their verified sign-in
number.

**Response `200`**: the same body as F1, message `"Your details are saved"`.

**Errors:** `403 auth.merchant_only`, `422 validation.failed`.

---

## If the app is closed partway

| State on the server | What the API says | Resume at |
| --- | --- | --- |
| Nothing paid | Phone check → `invoice_required: true` | A2 |
| Registration paid, no account | Phone check → `pending_invoice` | A5 with that `invoice_id` |
| Account, no PIN | Lookup → `has_pin: false` | A6 |
| Signed in, phone not verified | `onboarding.next_step: "verify_phone"` | B (a paid, unused verification invoice can go straight to B4) |
| Phone verified, no shop | `onboarding.next_step: "create_shop"` | C |
| Has a shop | `onboarding.next_step: null` | Home |

---

## Error codes in this flow

| Code | Status | Where | Meaning |
| --- | --- | --- | --- |
| `validation.failed` | 422 | Everywhere | Per-field messages in `error.details` |
| `request.device_id_missing` | 400 | A6, D2 | `X-EXELO-Device-Id` missing |
| `quote.expired` | 410 | A3, B2 | New quote |
| `payment.wallet_invalid` | 422 | A3, B2, E7 | Number doesn't belong to the rail |
| `payment.provider_unavailable` | 502 | A3, B2 | Wallet provider down; retry with the same key |
| `invoice.not_found` | 404 | A4, B3 | Unknown invoice |
| `registration.phone_taken` | 409 | A3, A5, C2, E1 | Number already used by a merchant or a shop |
| `registration.invoice_unpaid` | 402 | A5, B4 | Fee not paid yet |
| `registration.invoice_consumed` | 409 | A5, B4 | Payment already used |
| `account.phone_unverified` | 409 | C2 | Verify the merchant's phone first |
| `auth.pin_already_set` | 409 | A6 | Sign in |
| `auth.pin_too_weak` | 422 | A6 | Another PIN |
| `auth.pin_not_set` | 409 | D2 | A6 |
| `auth.invalid_credentials` | 401 | D2 | Wrong PIN |
| `auth.locked` | 423 | D2 | Too many wrong PINs |
| `auth.registration_incomplete` | 403 | D2 | Finish A |
| `auth.employee_disabled` | 403 | D2 | Staff member removed |
| `auth.merchant_only` | 403 | B4, C2, E1, E5, E6, E7 | Owners only |
| `auth.confirmation_required` | 401 | E6 | Confirm the PIN first |
| `wallet.number_taken` | 409 | E7 | Payout number is on another merchant's shop |
| `shop.not_a_member` | 403 | D2 | `shop_id` isn't theirs |
| `shop.not_found` | 404 | E2, E4–E6 | No such shop for this person |
| `shop.last_shop` | 409 | E6 | Can't close the only shop |
| `shop.payments_pending` | 409 | E6 | A payment is still waiting |

---

## Existing merchants

Merchants who registered before this flow have one number for both their account
and their first shop. They keep signing in with it; their account's number was
filled from that shop, and `onboarding.next_step` is `null` because they already
have a shop. They add more shops the same way (E1), each with its own number.

---

## Testing locally

- **No real wallet:** with `EXELO_SIMULATE_PAYMENTS=true` and `APP_ENV=local`,
  `POST /registration/invoices/{invoice_id}/simulate-payment` marks a pending
  registration or verification invoice paid.
- **Fees:** Admin → Payment Fees.
- **Provider calls:** the `api_logs` table.

```bash
BASE=http://localhost/api/v1
DEV='X-EXELO-Device-Id: 11111111-2222-3333-4444-555555555555'
ME=+252654110101

# A. merchant account
curl "$BASE/registration/quote?purpose=registration" -H 'Accept: application/json'
curl -X POST $BASE/registration/invoices -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"phone_number\":\"$ME\",\"wallet_number\":\"$ME\",\"rail\":\"edahab\",\"purpose\":\"registration\",\"quote_id\":\"<quote_id>\",\"idempotency_key\":\"<uuid-1>\"}"
curl -X POST $BASE/registration/invoices/<invoice_id>/simulate-payment -H 'Accept: application/json'
curl -X POST $BASE/merchants -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"invoice_id\":\"<invoice_id>\",\"first_name\":\"Kalid\",\"last_name\":\"Ahmed\",\"dob\":\"1990-01-01\",\"phone_number\":\"$ME\"}"
curl -X POST $BASE/auth/pin -H 'Accept: application/json' -H 'Content-Type: application/json' -H "$DEV" \
  -d "{\"phone_number\":\"$ME\",\"pin\":\"2580\",\"pin_confirmation\":\"2580\"}"          # → TOKEN

# B. verify the merchant's phone
curl "$BASE/registration/quote?purpose=verification" -H 'Accept: application/json'
curl -X POST $BASE/registration/invoices -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"phone_number\":\"$ME\",\"wallet_number\":\"$ME\",\"rail\":\"edahab\",\"purpose\":\"verification\",\"quote_id\":\"<quote_id>\",\"idempotency_key\":\"<uuid-2>\"}"
curl -X POST $BASE/registration/invoices/<invoice_id>/simulate-payment -H 'Accept: application/json'
curl -X POST $BASE/account/verification/complete -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -d '{"invoice_id":"<invoice_id>"}'

# C. first shop (free, its own number)
curl -X POST $BASE/shops -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOKEN" \
  -d '{"business_name":"Exelo Retail","phone_number":"+252634220202","state":"maroodi_jeex","city":"Hargeisa"}'
```
