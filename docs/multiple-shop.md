# Multiple Shops

One owner, several shops. The owner signs in once with their phone and PIN, sees
all their shops, and moves between them without typing the PIN again. Each shop
keeps its own business details, plan, payout wallets, stock, orders and staff.

**Status: implemented (2026-09-25)** for **owners**. Staff who work in more than
one shop are a later phase; see [Not in this version](#not-in-this-version).

---

## Concepts

| Term | Meaning | Stored in |
| --- | --- | --- |
| **Person** | Someone who signs in: one PIN, one lockout counter | `users` |
| **Shop** | A business: stock, orders, plan, payout wallets, staff | `merchants` (one row per shop) |
| **Owner** | The person a shop belongs to. One person can own many shops | `merchants.user_id` |
| **Staff** | A person working in one shop, with the permissions the owner gave them | `employees` |
| **Active shop** | The shop a signed-in device is acting for right now | `personal_access_tokens.merchant_id` |

Every v1 endpoint that works on "the shop" (products, cart, orders, payments,
dashboard, staff, settings…) acts on the **active shop** of the token it is called
with. Nothing else about those endpoints changes.

## Rules

1. **The merchant and each shop have their own phone numbers.** The merchant signs
   in with **their own** number and PIN. Each shop's number is its contact number,
   different from the merchant's and new to EXELO. (Merchants registered before this
   share one number between their account and first shop, and can still sign in with
   any of their shops' numbers.)
2. **Creating a shop is free**, the first one included. The only charges are the
   merchant's registration fee and phone verification fee, once each. The first
   shop needs a verified merchant phone (see [merchant-onboarding.md](merchant-onboarding.md)).
3. **Each shop has its own subscription.** A new shop starts on the default (free)
   plan and is upgraded on its own.
4. **Switching shop needs no PIN.** The same person is already signed in on this
   device; only the active shop changes.
5. **Only the owner** can open, edit or close a shop. Staff see only their own shop.
6. **Closing a shop needs the PIN again** (a confirmation token), and the last shop
   can't be closed.

---

## Endpoints

Base URL `{BASE_URL}/api/v1`. Every call needs `Accept: application/json`;
calls with a body need `Content-Type: application/json`.

| # | Method | Path | Who | Purpose |
| --- | --- | --- | --- | --- |
| 1 | POST | [`/auth/pin/login`](#1-post-authpinlogin--sign-in) | anyone | Sign in; optionally straight into one shop. **Changed:** `shop_id`, `shops` |
| 2 | GET | [`/auth/session`](#2-get-authsession--current-session) | signed in | The active shop's session. **Changed:** `shops` |
| 3 | GET | [`/shops`](#3-get-shops--list-my-shops) | signed in | The shops this person can act for |
| 4 | POST | [`/shops/{id}/select`](#4-post-shopsidselect--switch-shop) | owner or staff of the shop | Make a shop the active one |
| 5 | POST | [`/shops`](#5-post-shops--create-a-shop) | owner | Create a shop (free) |
| 6 | GET | [`/shops/{id}`](#6-get-shopsid--shop-details) | owner or staff of the shop | One shop's profile |
| 7 | PATCH | [`/shops/{id}`](#7-patch-shopsid--edit-a-shop) | owner of the shop | Edit a shop's business details |
| 8 | DELETE | [`/shops/{id}`](#8-delete-shopsid--close-a-shop) | owner of the shop + PIN | Close a shop |
| 9 | GET | [`/shops/{id}/settings`](merchant.md#settings-of-one-specific-shop-shopsidsettings) | owner or staff of the shop | One shop's settings (VAT, exchange rate, timezone, receipt, register, alerts) |
| 10 | PATCH | [`/shops/{id}/settings`](merchant.md#settings-of-one-specific-shop-shopsidsettings) | owner of the shop | Change one shop's settings |

Headers:

| Header | Sent on | Value |
| --- | --- | --- |
| `Authorization` | every call except login | `Bearer <token>` |
| `X-EXELO-Device-Id` | login | The same device id as today |
| `X-EXELO-Confirmation` | `DELETE /shops/{id}` | Token from [`POST /auth/pin/verify`](auth.md#post-authpinverify) with `"scope": "shops.delete"` |
| `If-Match` | `PATCH /shops/{id}` (optional) | The shop's `version`; a stale one returns `409 resource.version_conflict` |

---

### 1. POST `/auth/pin/login` — Sign in

Unchanged except for one optional field and two response fields.

**Request**

```json
{
  "phone_number": "+252634110101",
  "pin": "1234",
  "shop_id": 30
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `phone_number` | string | yes | The owner's number, or the number of any of their shops |
| `pin` | string | yes | 4 digits |
| `shop_id` | int | no | **New.** Sign straight into this shop. Must be one of the person's shops |

**Which shop becomes active**, in order:

1. `shop_id`, if sent;
2. the shop this device was last using for this person;
3. the shop whose phone number was typed;
4. the person's first shop.

**Response `200`**: today's response, now with `shops`:

```json
{
  "success": true,
  "message": "Welcome back, Amina",
  "data": {
    "token": "18|vJ2kQx9fR7pLmN3sT6wY0aB4cD8eF1gH",
    "expires_at": "2027-03-25T09:00:00Z",
    "user": { "id": 91, "type": "merchant", "first_name": "Amina", "last_name": "Yusuf", "short_name": "AY", "email": "amina@example.com", "phone_number": "+252634110101" },
    "merchant": { "id": 30, "business_name": "Berbera Mart", "merchant_code": null, "state": "Sahil", "city": "Berbera", "location": "Berbera, Sahil", "currency": "USD", "alt_currency": "SLSH", "exchange_rate": 8000 },
    "subscription": { "plan_id": 2, "plan": "silver", "status": "active", "expires_at": null },
    "permissions": [ { "key": "pos", "name": "POS" }, "…" ],
    "shops": [
      { "id": 12, "business_name": "Exelo Retail", "phone_number": "+252634110101", "location": "Hargeisa, Maroodi Jeex", "role": "owner", "plan": "gold", "is_active": false },
      { "id": 30, "business_name": "Berbera Mart", "phone_number": "+252634220202", "location": "Berbera, Sahil", "role": "owner", "plan": "silver", "is_active": true }
    ]
  }
}
```

`merchant` is the **active shop**. A person with one shop gets exactly today's
response plus a one-item `shops`; the app only needs a shop picker when `shops`
has more than one entry.

**Errors**: as today, plus:

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `shop.not_a_member` | `shop_id` isn't one of this person's shops |

### 2. GET `/auth/session` — Current session

Unchanged, plus `shops` (same shape as in login). Use it to redraw the shop
picker.

### 3. GET `/shops` — List my shops

**Response `200`**

```json
{
  "success": true,
  "data": {
    "shops": [
      { "id": 12, "business_name": "Exelo Retail", "phone_number": "+252634110101", "location": "Hargeisa, Maroodi Jeex", "role": "owner", "plan": "gold", "is_active": false },
      { "id": 30, "business_name": "Berbera Mart", "phone_number": "+252634220202", "location": "Berbera, Sahil", "role": "owner", "plan": "silver", "is_active": true }
    ],
    "active_shop_id": 30
  }
}
```

| Field | Meaning |
| --- | --- |
| `role` | `owner` or `staff` |
| `plan` | The shop's current plan **key** (`gold`, `silver`, or an admin-created key) |
| `is_active` | The shop this token is acting for |

Closed shops aren't listed. Staff see only the shop they work in.

### 4. POST `/shops/{id}/select` — Switch shop

Makes shop `{id}` the active shop for **this token**. No body, no PIN.

**Response `200`**: the new active shop's session, the same body as
[`GET /auth/session`](auth.md#get-authsession):

```json
{
  "success": true,
  "message": "Now working in Exelo Retail",
  "data": {
    "user": { "…": "…" },
    "merchant": { "id": 12, "business_name": "Exelo Retail", "…": "…" },
    "subscription": { "plan": "gold", "status": "active", "…": "…" },
    "permissions": [ "…" ],
    "shift": { "active": false, "shift_id": null, "started_at": null },
    "server_time": "2026-09-25T09:10:00Z",
    "shops": [ "…" ]
  }
}
```

After switching, **every call** made with this token acts on the new shop. The
token itself doesn't change. Other devices stay on the shop they were using.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `404` | `shop.not_found` | No such shop for this person (or it was closed) |

### 5. POST `/shops` — Create a shop

For a signed-in merchant. **Free**, no payment step. The first shop needs the
merchant's phone to be verified first
([merchant-onboarding.md, B](merchant-onboarding.md#b-verify-the-merchants-phone)).

**Request**

```json
{
  "business_name": "Berbera Mart",
  "phone_number": "+252634220202",
  "state": "sahil",
  "city": "Berbera",
  "email": "berbera@example.com",
  "merchant_code": "740853",
  "other_merchant_code": null
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `business_name` | string | yes | |
| `phone_number` | string | yes | The shop's own number. New to EXELO: not the merchant's, not another shop's |
| `state` | string | yes | A code from [`GET /geo/states`](registration.md) |
| `city` | string | yes | |
| `email` | string | no | The shop's email |
| `merchant_code` | string | no | The shop's eDahab agent code; unique |
| `other_merchant_code` | string | no | The shop's Zaad merchant code; unique |

The owner's name and date of birth are copied from the merchant account.

**Response `201`**

```json
{
  "success": true,
  "message": "Berbera Mart is open. Switch to it to start selling.",
  "data": {
    "shop": { "id": 30, "business_name": "Berbera Mart", "phone_number": "+252634220202", "location": "Berbera, Sahil", "role": "owner", "plan": "silver", "is_active": false },
    "subscription": { "plan": "silver", "status": "active" },
    "payout_wallets": [ { "rail": "edahab", "number": "+252654110101", "status": "verified", "…": "…" }, "…" ]
  }
}
```

- **The first shop** becomes the active shop at once (`is_active: true`, message
  "… is ready. You can start selling."). **Later shops** don't; call
  [`POST /shops/{id}/select`](#4-post-shopsidselect--switch-shop) to work in them.
- The merchant's **verified number becomes the shop's verified payout wallet**
  (`payout_wallets`), so it takes wallet payments at once. Shops of the same merchant
  may share a payout number.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.merchant_only` | Staff can't open shops |
| `409` | `account.phone_unverified` | First shop: verify the merchant's phone first |
| `409` | `registration.phone_taken` | The number already belongs to a shop |
| `422` | `validation.failed` | Missing fields, unknown invoice, number doesn't match the payment, code already registered |

### 6. GET `/shops/{id}` — Shop details

The same body as [`GET /merchant`](merchant.md), for shop `{id}`, whether or not it's
the active one.

**Response `200`** (abridged)

```json
{
  "success": true,
  "data": {
    "id": 30,
    "version": 1,
    "business_name": "Berbera Mart",
    "merchant_code": "740853",
    "other_merchant_code": null,
    "owner": { "id": 91, "first_name": "Amina", "last_name": "Yusuf", "short_name": "AY", "email": null, "phone_number": "+252634220202", "dob": "1990-01-01" },
    "address": { "state": "Sahil", "state_code": "sahil", "city": "Berbera", "location": "Berbera, Sahil" },
    "logo": null,
    "currency": "USD",
    "alt_currency": "SLSH",
    "exchange_rate": 8000,
    "subscription": { "plan_id": 2, "plan": "silver", "status": "active" },
    "created_at": "2026-09-25T09:05:00Z",
    "…": "…"
  }
}
```

**Errors**: `404 shop.not_found`.

### 7. PATCH `/shops/{id}` — Edit a shop

Same body and rules as [`PATCH /merchant`](merchant.md#2-patch-apiv1merchant--edit-the-shop-profile),
for shop `{id}`. Only the fields sent are changed.

**Request**

```json
{ "business_name": "Berbera Mart & Pharmacy", "city": "Berbera" }
```

| Field | Type | Notes |
| --- | --- | --- |
| `business_name` | string | |
| `first_name`, `last_name` | string | The owner's name on this shop |
| `email` | string | Unique |
| `state`, `city` | string | `state` is a code |
| `merchant_code`, `other_merchant_code` | string | Unique |
| `logo_file_id` | string \| null | From [`POST /files`](files.md) |

The phone number, payout wallets and plan are changed through their own endpoints
([wallets](merchant.md#4-patch-apiv1merchantwallets--add-change-or-remove-payout-numbers),
[subscription](subscription.md)), on the active shop.

**Response `200`**: the shop's profile, as in [6](#6-get-shopsid--shop-details).

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.merchant_only` | Only the owner can edit a shop |
| `404` | `shop.not_found` | Not one of this person's shops |
| `409` | `resource.version_conflict` | `If-Match` is stale |
| `422` | `validation.failed` | Per field |

### 8. DELETE `/shops/{id}` — Close a shop

Closes shop `{id}`. Its orders, stock and payments are kept (soft delete) for
reports and the admin panel, but nobody can act for it any more.

First confirm the PIN:

```text
POST /auth/pin/verify { "pin": "1234", "scope": "shops.delete" }  → confirmation_token
DELETE /shops/30
X-EXELO-Confirmation: <confirmation_token>
```

**What closing does**, in one transaction:

1. Removes every staff member of that shop (as removing them one by one would:
   open shifts closed, tokens revoked).
2. Moves every token that was acting for it to the owner's first remaining shop.
3. Soft-deletes the shop.

**Response `200`**

```json
{
  "success": true,
  "message": "Berbera Mart is closed",
  "data": { "shop_id": 30, "status": "closed", "staff_removed": 2, "active_shop_id": 12 }
}
```

`active_shop_id` is the shop **this** token now acts for.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.confirmation_required` | Missing, used or expired PIN confirmation |
| `403` | `auth.merchant_only` | Only the owner can close a shop |
| `404` | `shop.not_found` | Not one of this person's shops |
| `409` | `shop.last_shop` | The owner's only shop can't be closed |
| `409` | `shop.payments_pending` | A payment for this shop is still waiting; try again when it settles or expires |

A closed shop's phone number stays reserved and can't open a new shop.

---

## Step by step

### Create a second shop and start selling there

```text
POST  /shops                   { business_name, phone_number: "+252634220202", state, city }   (free)
                                                  → 201, shop 30
POST  /shops/30/select                            → 200, session for Berbera Mart
POST  /cart/items …                               → sells in Berbera Mart
```

### Switch shop

```text
GET   /shops                                      → picker
POST  /shops/12/select                            → 200, session for Exelo Retail
```

The app keeps one token per device. Before switching, it should finish or hold
the current shop's ticket and replay any queued offline work
([sync](sync.md)) while the old shop is still active.

### Sign straight into a shop

```text
POST  /auth/pin/login { phone_number, pin, shop_id: 30 }
```

---

## Errors added

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `shop.not_a_member` | Login `shop_id` isn't one of this person's shops |
| `404` | `shop.not_found` | No such shop for this person, or it was closed |
| `409` | `shop.last_shop` | The only shop can't be closed |
| `409` | `shop.payments_pending` | The shop has a payment still waiting |

---

## Database changes

| Table | Change |
| --- | --- |
| `shops` | The shops' own table (renamed from `merchants`, same rows and ids), with `merchant_id` → the owning merchant |
| `merchants` | Now the merchants' own table: one row per merchant (own phone, name, verification) |
| `personal_access_tokens` | Nullable `merchant_id` (a shop id): the session's current shop. Tokens issued before multi-shop have none and act for the owner's first shop |

One merchant has many shops; each shop has its own subscription, wallets, stock
and orders. The full layout, and why other tables' `merchant_id` columns hold a
shop id, is in [data-model.md](data-model.md).

---

## Not in this version

| Feature | Why it waits |
| --- | --- |
| ~~Staff in more than one shop~~ | **Done (2026-09-26).** One person can work in several shops with separate permissions, shifts and hours in each. See [employees.md](employees.md#staff-in-several-shops) |
| **Combined reports across shops** | Each shop's dashboard and reports stay separate |
| **Moving stock between shops** | Transfers stay within one shop |
| **Re-using a closed shop's number** | The number stays reserved |

---

## The app

The app's current "Switch shop" screen (branch `cursor/exelo-gold-release-4058`)
switches on the device by swapping saved tokens. With this API:

- An owner's shops come from `shops` in the login response or `GET /shops`; no
  shop list needs saving on the device.
- Switching is `POST /shops/{id}/select`: no PIN, no second token.
- **Two different people** sharing one till still sign in separately (one token
  each), as the device-side screen does today.
- Keep local data **per shop id**: catalogue cache, held ticket, offline queues,
  exchange rate, NFC key (already done for most of these).
- Choose screens by `subscription.plan` (the key) and `permissions`, never by plan id.

---

## Implementation notes

- **Active shop:** `App\Models\PersonalAccessToken` (Sanctum's model plus
  `merchant_id`). `User::actingMerchant()` reads the token's shop, and checks that
  the person still owns it (or works there) on every request. All v1 endpoints get
  their shop from `actingMerchant()`, so none of them changed.
- **Login:** `AuthService::loginWithPin()` chooses the shop and stores it on the
  token; `SessionResource` adds `shops`.
- **Shops:** `ShopController` → `ShopService`. Opening a shop reuses registration's
  invoice checks and shop creation (`RegistrationService::openShop()`); editing
  reuses `MerchantProfileService::updateProfile()`; closing reuses
  `EmployeeService::remove()` for its staff.
- **Legacy API:** unchanged; it acts for the owner's first shop.
