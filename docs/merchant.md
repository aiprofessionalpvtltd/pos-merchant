# Merchant Profile & Settings

The shop's own details, payout wallets and preferences.

> **Status: implemented**, including the shop logo via
> [`logo_file_id`](files.md#post-apiv1files--upload-a-file). See
> [Implementation notes](#implementation-notes) for where the data is stored.

Employees can read this module; only the shop owner can write to it.

| Method | Path | Auth |
| --- | --- | --- |
| GET | `/merchant` | Bearer |
| PATCH | `/merchant` | Bearer · shop owner |
| GET | `/merchant/wallets` | Bearer |
| PATCH | `/merchant/wallets` | Bearer · shop owner |
| GET | `/merchant/settings` | Bearer |
| PATCH | `/merchant/settings` | Bearer · shop owner |

---

## Complete endpoint list

Full URL = `{BASE_URL}/api/v1` + path.

**Headers**

| Header | Sent on | Value |
| --- | --- | --- |
| `Accept` | Every request | `application/json` |
| `Content-Type` | Requests with a body | `application/json` |
| `Authorization` | Every request | `Bearer <token>` from [`POST /auth/pin/login`](auth.md#post-authpinlogin) |
| `If-Match` | `PATCH /merchant` (optional) | The `version` you last read, so a change from another device is not overwritten |

| # | Method | Full path | Purpose | Needs | Body / query | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | GET | `/api/v1/merchant` | Get the shop profile | Bearer (owner or employee) | — | `200` | `401`, `404 merchant.not_found` |
| 2 | PATCH | `/api/v1/merchant` | Edit the shop profile | Bearer, owner only | any subset of `business_name`, `first_name`, `last_name`, `email`, `state`, `city`, `merchant_code`, `other_merchant_code`, `logo_file_id` | `200` | `403 auth.merchant_only`, `409 resource.version_conflict`, `422 validation.failed` |
| 3 | GET | `/api/v1/merchant/wallets` | List the payout wallets and their status | Bearer (owner or employee) | — | `200` | `401` |
| 4 | PATCH | `/api/v1/merchant/wallets` | Add, change or remove payout numbers | Bearer, owner only | `wallets[]`, optional `default_rail` | `200` | `403 auth.merchant_only`, `409 wallet.number_taken`, `422 payment.wallet_invalid`, `422 validation.failed` |
| 5 | GET | `/api/v1/merchant/settings` | Get the shop preferences | Bearer (owner or employee) | — | `200` | `401` |
| 6 | PATCH | `/api/v1/merchant/settings` | Change shop preferences | Bearer, owner only | any subset of the settings object | `200` | `403 auth.merchant_only`, `422 settings.rate_out_of_range`, `422 validation.failed` |

**Status codes shared by every endpoint**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.token_invalid` | Missing, revoked or expired token — clear the session |
| `403` | `auth.merchant_only` | An employee tried to change the shop (writes are owner-only) |
| `422` | `validation.failed` | Per-field messages in `error.details` |
| `429` | `rate_limited` | Slow down; honour `Retry-After` |

**Response envelope.** Every response carries `success`, `message`, `data` (or
`error`) and `meta.request_id` / `meta.server_time`. Branch on `error.code`,
never on `message`. See [errors.md](errors.md).

---

## Concepts

### Who can do what

| Action | Owner | Employee |
| --- | --- | --- |
| Read profile, wallets, settings | yes | yes |
| Change profile, wallets, settings | yes | no (`403 auth.merchant_only`) |

Employees need the profile to render receipts and the wallets to show payment
options, so reads are open to them.

### The shop address

`address.state` and `address.city` are stored fields. **`address.location` is
read-only**, computed as `"City, State"`. It exists so receipts and any client
still reading the legacy single `location` field keep working. Writes go to
`state` (a code from [`GET /geo/states`](registration.md#1-get-apiv1geostates--load-the-state-dropdown))
and `city`.

### Payout wallets

A wallet is a phone number the shop is paid on, per rail: `zaad`, `edahab`,
`golis` or `evc`.

| `status` | Meaning |
| --- | --- |
| `verified` | Confirmed, can receive money |
| `pending` | Awaiting verification |
| `rejected` | Verification failed; `rejection_reason` is present |
| `not_set` | No number on file |

Changing a number resets that wallet to `pending` and requires verification
again. A shop must not be able to silently redirect payouts to a new number.
Verification completes through
[`POST /merchants/{id}/verification/complete`](registration.md#8-post-apiv1merchantsidverificationcomplete--confirm-payout-wallets).

The same number cannot be on two shops. The number must belong to its rail (for
example a `63…` number is Zaad), otherwise `422 payment.wallet_invalid`.

### The exchange rate guard

The exchange rate multiplies every SLSH price in the app, so a typo of one extra
zero would misprice the whole catalogue on every till at once. It is checked
against a sanity range on every change (`422 settings.rate_out_of_range`).

### Optimistic concurrency

`GET /merchant` returns a `version`. Send it back as `If-Match: <version>` on
`PATCH /merchant` and the server refuses the write with
`409 resource.version_conflict` if another device changed the profile in between,
returning the current copy in `error.details.current`.

---

## 1. GET `/api/v1/merchant` — Get the shop profile

**Purpose:** The shop's own details for the profile screen and receipts, including
the owner, address, logo, currency settings and current plan. With `?include=` it
also returns everything related to the merchant in one call: the merchant, all their
shops, the subscription in full and all their staff (see
[Everything about the merchant](#everything-about-the-merchant-include)).

**Query**

| Param | Notes |
| --- | --- |
| `include` | Optional. Comma-separated (`include=shops,employees`) or `include[]=shops`. Values: `merchant`, `shops`, `subscription`, `employees`, or `all`. Merchant only |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "id": 12,
    "version": 4,
    "business_name": "Exelo Retail",
    "merchant_code": "EXL-102",
    "other_merchant_code": "ZAAD-88",
    "owner": {
      "id": 91,
      "first_name": "Kalid",
      "last_name": "Ahmed",
      "short_name": "KA",
      "email": "kalid@exelo.co",
      "phone_number": "+252634110101",
      "dob": "1990-01-01"
    },
    "address": {
      "state": "Maroodi Jeex",
      "state_code": "maroodi_jeex",
      "city": "Hargeisa",
      "location": "Hargeisa, Maroodi Jeex"
    },
    "logo": { "id": "file_01JBY1A2K8", "url": "https://cdn.exelo.co/m/12/logo.png" },
    "currency": "USD",
    "alt_currency": "SLSH",
    "exchange_rate": 8000,
    "vat_rate": 0.05,
    "timezone": "Africa/Mogadishu",
    "subscription": { "plan_id": 1, "plan": "gold", "status": "active" },
    "created_at": "2026-02-01T09:00:00Z"
  },
  "meta": { "request_id": "req_01JBY0Q3M8", "server_time": "2026-09-19T13:09:19Z" }
}
```

`logo` is `null` until `logo_file_id` is set. Employees receive the same object.

### Everything about the merchant (`?include=`)

`GET /api/v1/merchant?include=all` returns the same object as above (the **current**
shop, unchanged) with the merchant's whole picture merged in. Without `include` the
response is exactly the object above, so existing apps are unaffected.

| `include` | Adds | Same data as |
| --- | --- | --- |
| `merchant` | `merchant`: the merchant's own details and verified phone | [`GET /account`](data-model.md#api) |
| `shops` | `shops[]`: **every** shop with address, codes, plan, payout wallets, staff count and a 5-person staff preview | `GET /account` |
| `subscription` | The current shop's `subscription` **in full** (replaces the short one), and each shop's too when `shops` is also asked | [`GET /subscription`](subscription.md) |
| `employees` | `employees`: the staff of **all** shops, with per-shop counts | [`GET /account/employees`](employees.md#12-get-apiv1accountemployees--staff-of-every-shop) |
| `all` | All of the above | |

```json
{
  "success": true,
  "data": {
    "id": 94,
    "version": 4,
    "business_name": "Exelo Retail",
    "owner": { "id": 91, "first_name": "Kalid", "phone_number": "+252634220202", "…": "…" },
    "address": { "state": "Maroodi Jeex", "city": "Hargeisa", "…": "…" },
    "currency": "USD",
    "…": "…",

    "subscription": {
      "plan_id": 1, "plan": "gold", "plan_name": "Gold Package", "status": "active",
      "features": ["dashboard.full", "pos.register", "employees.manage", "…"],
      "started_at": "2026-09-01T00:00:00Z", "expires_at": "2026-10-01T00:00:00Z",
      "renews_automatically": false, "can_upgrade": false, "can_downgrade": true,
      "resubscribe_eligible": false, "grace": null
    },

    "merchant": {
      "id": 41, "first_name": "Kalid", "last_name": "Ahmed", "dob": "1990-01-01",
      "email": "kalid@exelo.co", "phone_number": "+252654110101",
      "phone_verified": true, "phone_verified_at": "2026-09-26T07:02:10Z", "created_at": "2026-09-26T06:56:10Z"
    },

    "shops": [
      {
        "id": 94, "business_name": "Exelo Retail", "phone_number": "+252634220202", "email": "shop@exelo.co",
        "address": { "state": "Maroodi Jeex", "state_code": "maroodi_jeex", "city": "Hargeisa", "location": "Hargeisa, Maroodi Jeex" },
        "merchant_code": "740852", "other_merchant_code": "467188",
        "subscription": { "plan": "gold", "status": "active", "features": ["…"], "expires_at": "2026-10-01T00:00:00Z", "…": "…" },
        "payout_wallets": [ { "rail": "edahab", "number": "+252654110101", "status": "verified", "…": "…" }, "…" ],
        "default_rail": "edahab",
        "staff_count": 3,
        "staff": [ { "id": 51, "first_name": "Nasra", "last_name": "Yusuf", "role": "Cashier" }, "…" ],
        "is_active": true,
        "created_at": "2026-09-26T07:05:00Z"
      },
      { "id": 130, "business_name": "Berbera Mart", "is_active": false, "…": "…" }
    ],

    "employees": {
      "items": [
        { "id": 51, "first_name": "Nasra", "last_name": "Yusuf", "role": "Cashier", "phone_number": "+252634110303",
          "shop": { "id": 94, "business_name": "Exelo Retail" }, "status": "off_shift",
          "permissions": [ { "key": "pos", "name": "POS" } ], "…": "…" }
      ],
      "total": 4,
      "has_more": false,
      "by_shop": [
        { "shop_id": 94, "business_name": "Exelo Retail", "active": 3 },
        { "shop_id": 130, "business_name": "Berbera Mart", "active": 1 }
      ]
    }
  }
}
```

- **The top-level fields are always the current shop** (the one this session is
  working in), so the profile screen behaves as before. `shops[].is_active` marks it.
- **`employees.items` holds up to 100 people**, ordered by shop then name. When
  `has_more` is `true`, page through
  [`GET /account/employees`](employees.md#12-get-apiv1accountemployees--staff-of-every-shop).
- **Merchant only.** Staff can call `GET /merchant` without `include` as before;
  with it they get `403 auth.merchant_only`, because the extras span every shop.
- **Errors:** `422 validation.failed` for an unknown `include` value
  ("Use merchant, shops, subscription, employees or all"), `403 auth.merchant_only`.
- Ask only for what a screen needs: `include=all` runs several queries per shop.

## 2. PATCH `/api/v1/merchant` — Edit the shop profile

**Purpose:** Changes the shop's details. Replaces `PUT /api/update-merchants`.
Owner only.

**Request** — any subset

```json
{
  "business_name": "Exelo Retail & Wholesale",
  "first_name": "Kalid",
  "last_name": "Ahmed",
  "state": "maroodi_jeex",
  "city": "Hargeisa",
  "merchant_code": "EXL-102",
  "other_merchant_code": "ZAAD-88",
  "logo_file_id": "file_01JBY1A2K8"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `business_name` | string | no | Appears on receipts |
| `first_name`, `last_name` | string | no | Owner name |
| `email` | string | no | Valid email, not used by another account |
| `state` | string | no | A `code` from [`GET /geo/states`](registration.md#1-get-apiv1geostates--load-the-state-dropdown) |
| `city` | string | no | Free text |
| `merchant_code` | string | no | eDahab agent code; must be unique |
| `other_merchant_code` | string | no | Zaad agent code; must be unique |
| `logo_file_id` | string \| null | no | From [`POST /files`](files.md#1-post-apiv1files--upload-a-file), `purpose: merchant_logo`. `null` clears the logo. |

The phone number is **not** editable here — it is the login identity. Changing it
needs OTP verification on both the old and new number, which is a separate flow
and not currently in the app.

**Headers:** `If-Match: 4` (optional, see
[Optimistic concurrency](#optimistic-concurrency)).

**Response `200`** — the updated shop, same shape as
[`GET /merchant`](#1-get-apiv1merchant--get-the-shop-profile), with a new `version`.

```json
{
  "success": true,
  "message": "Shop details saved",
  "data": {
    "id": 12,
    "version": 5,
    "business_name": "Exelo Retail & Wholesale",
    "address": {
      "state": "Maroodi Jeex",
      "state_code": "maroodi_jeex",
      "city": "Hargeisa",
      "location": "Hargeisa, Maroodi Jeex"
    }
  }
}
```

(Other fields as in the profile response.)

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.merchant_only` | Employees cannot edit the shop |
| `409` | `resource.version_conflict` | Changed on another device; `error.details.current` has the current copy |
| `422` | `validation.failed` | Per-field messages, for example an unknown `state` or a taken `merchant_code` |

```json
{
  "success": false,
  "message": "This shop was changed on another device",
  "error": {
    "code": "resource.version_conflict",
    "details": { "current": { "id": 12, "version": 6, "business_name": "Exelo Retail" } }
  }
}
```

```json
{
  "success": false,
  "message": "Please check the form",
  "error": {
    "code": "validation.failed",
    "details": {
      "state": ["Choose a state"],
      "merchant_code": ["This code is already registered"]
    }
  }
}
```

## 3. GET `/api/v1/merchant/wallets` — List the payout wallets and their status

**Purpose:** The numbers each shop gets paid on, and whether each is verified. This
is the **settings** view. [`GET /payments/methods`](payments.md#get-paymentsmethods)
is the payment-screen view of the **current** shop.

Every shop has its own wallets and its own verification, so the response is
**shop based**: `wallets` and `default_rail` are the **current** shop's (as before),
and `shops` lists **every** shop's wallets. A merchant gets all their shops; a staff
member gets the shops they work in.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "wallets": [
      { "rail": "zaad", "label": "Zaad", "number": "+252632222222", "status": "verified",
        "verified_at": "2026-02-03T10:00:00Z", "is_default": true },
      { "rail": "edahab", "label": "eDahab", "number": "+252631111111", "status": "verified",
        "verified_at": "2026-02-03T10:02:00Z", "is_default": false },
      { "rail": "golis", "label": "Golis", "number": null, "status": "not_set",
        "verified_at": null, "is_default": false },
      { "rail": "evc", "label": "EVC", "number": null, "status": "not_set",
        "verified_at": null, "is_default": false }
    ],
    "default_rail": "zaad",
    "active_shop_id": 94,
    "shops": [
      {
        "shop_id": 94,
        "business_name": "Exelo Retail",
        "is_active": true,
        "wallets": [ { "rail": "zaad", "number": "+252632222222", "status": "verified", "…": "…" }, "…" ],
        "default_rail": "zaad",
        "verified_rails": ["zaad", "edahab"],
        "pending_rails": []
      },
      {
        "shop_id": 130,
        "business_name": "Berbera Mart",
        "is_active": false,
        "wallets": [ { "rail": "evc", "number": "+252614440001", "status": "pending", "…": "…" }, "…" ],
        "default_rail": "evc",
        "verified_rails": [],
        "pending_rails": ["evc"]
      }
    ]
  }
}
```

| Field | Meaning |
| --- | --- |
| `wallets`, `default_rail` | The **current** shop's wallets, unchanged from before |
| `active_shop_id` | The current shop (the one this session is working in) |
| `shops[]` | One entry per shop: its four wallets (same shape as `wallets`), its default, and quick lists of the rails that are `verified` or still `pending` |

A `rejected` wallet also carries `rejection_reason`. All four rails are always
listed for every shop, so the app can draw the whole screen without guessing.

Reading is shop based, but **writing stays on the current shop**:
[`PATCH /merchant/wallets`](#4-patch-apiv1merchantwallets--add-change-or-remove-payout-numbers)
and wallet verification act on the shop the session is working in, so switch shop
first ([`POST /shops/{id}/select`](multiple-shop.md#4-post-shopsidselect--switch-shop)).

## 4. PATCH `/api/v1/merchant/wallets` — Add, change or remove payout numbers

**Purpose:** Sets the shop's payout numbers. Owner only.

**Request**

```json
{
  "wallets": [
    { "rail": "zaad", "number": "+252632222222" },
    { "rail": "evc", "number": "+252634444444" }
  ],
  "default_rail": "zaad"
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `wallets` | array | yes | Only the rails listed are touched |
| `wallets[].rail` | enum | yes | `zaad` \| `edahab` \| `golis` \| `evc` |
| `wallets[].number` | string \| null | yes | E.164 or local format. `null` removes the wallet. |
| `default_rail` | enum | no | Must be a rail that is on file |

**Response `200`**

```json
{
  "success": true,
  "message": "EVC number saved. Verify it to start receiving payments there.",
  "data": {
    "wallets": [
      { "rail": "zaad", "label": "Zaad", "number": "+252632222222", "status": "verified", "verified_at": null, "is_default": true },
      { "rail": "edahab", "label": "eDahab", "number": null, "status": "not_set", "verified_at": null, "is_default": false },
      { "rail": "golis", "label": "Golis", "number": null, "status": "not_set", "verified_at": null, "is_default": false },
      { "rail": "evc", "label": "EVC", "number": "+252634444444", "status": "pending", "verified_at": null, "is_default": false }
    ],
    "default_rail": "zaad",
    "verification_required": ["evc"]
  }
}
```

The full four-rail list is returned, as in `GET /merchant/wallets`.
`verification_required` lists the rails that changed and now need verifying. An
unchanged number keeps its status. If the default rail's number is removed, the
default falls back to the first rail that still has a number.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.merchant_only` | Employees cannot change wallets |
| `409` | `wallet.number_taken` | The number is already on another shop |
| `422` | `payment.wallet_invalid` | The number does not belong to that rail (`error.field: "wallets.1.number"`) |
| `422` | `validation.failed` | Unknown rail, or `default_rail` is not on file |

```json
{
  "success": false,
  "message": "That number is already used by another shop",
  "error": { "code": "wallet.number_taken", "field": "wallets.1.number" }
}
```

## 5. GET `/api/v1/merchant/settings` — Get the shop preferences

**Purpose:** Shop preferences that change app behaviour: VAT, exchange rate, receipt
and register options, and stock alert defaults. These are currently hardcoded in
the client or absent. Moving them server-side means a shop can change its VAT rate
or exchange rate without an app release, and the rate used for SLSH display is the
same on every till.

Settings belong to **one shop**: this is the shop the session is working in. For a
specific shop by id, see [`/shops/{id}/settings`](#settings-of-one-specific-shop-shopsidsettings).

**Response `200`**

```json
{
  "success": true,
  "data": {
    "vat_rate": 0.05,
    "vat_label": "5%",
    "vat_inclusive": false,
    "exchange_rate": 8000,
    "exchange_rate_source": "manual",
    "exchange_rate_updated_at": "2026-09-01T00:00:00Z",
    "receipt": {
      "footer": "Thank you for shopping with Exelo Retail",
      "show_logo": true,
      "print_automatically": false
    },
    "register": {
      "allow_price_override": true,
      "require_customer_on_hold": true,
      "scan_sound": true
    },
    "alerts": {
      "default_alarm_limit": 4,
      "default_stock_limit": 10
    },
    "timezone": "Africa/Mogadishu",
    "language": "en"
  }
}
```

## 6. PATCH `/api/v1/merchant/settings` — Change shop preferences

**Purpose:** Changes preferences. Partial and nested: only the keys sent are
changed. Owner only.

**Request.** Every field is optional: send only the keys to change. Groups (`receipt`,
`register`, `alerts`) are merged, so `{ "receipt": { "footer": "Thanks" } }` changes only
the footer. Keys not listed below are ignored.

```json
{
  "vat_rate": 0.05,
  "vat_inclusive": false,
  "exchange_rate": 8500,
  "timezone": "Africa/Mogadishu",
  "language": "en",
  "receipt": {
    "footer": "Mahadsanid!",
    "show_logo": true,
    "print_automatically": false
  },
  "register": {
    "allow_price_override": false,
    "require_customer_on_hold": true,
    "scan_sound": true
  },
  "alerts": {
    "default_alarm_limit": 4,
    "default_stock_limit": 10
  }
}
```

**Input fields**

| Field | Type | Rule | Meaning |
| --- | --- | --- | --- |
| `vat_rate` | number | `0` to `1`, a **fraction**: `0.05` = 5%, `0.155` = 15.5% | VAT rate on sales. Stored with 4 decimals |
| `vat_inclusive` | boolean | | `true` when shelf prices already include VAT |
| `exchange_rate` | integer | Whole SLSH per USD, at least `1`, and within the allowed range (`5000` to `15000` by default), otherwise `422 settings.rate_out_of_range` | The shop's own rate, used instead of the server default. Setting a different value records `exchange_rate_updated_at` and turns `exchange_rate_source` to `manual` |
| `timezone` | string | A valid timezone id, e.g. `Africa/Mogadishu`, `Africa/Nairobi` | The shop's timezone for dates and reports |
| `language` | string | `en` or `so` | The shop's language |
| `receipt.footer` | string \| `null` | Up to 200 characters. `null` clears it | Line printed at the bottom of receipts |
| `receipt.show_logo` | boolean | | Show the shop logo on receipts |
| `receipt.print_automatically` | boolean | | Print the receipt after each sale |
| `register.allow_price_override` | boolean | | Whether a cashier may change a line's price at the register |
| `register.require_customer_on_hold` | boolean | | Whether holding a ticket needs a customer |
| `register.scan_sound` | boolean | | Beep when a barcode is scanned |
| `alerts.default_alarm_limit` | integer | `0` to `100000` | Default low-stock **alarm** limit for products |
| `alerts.default_stock_limit` | integer | `0` to `100000` | Default **stock** limit for products |

The server stores and returns the `register` and `alerts` values for the app to apply
at the register and when adding products; only the `receipt` values are used by the
server itself (they appear in the receipt). Sending a value identical to the stored one
is fine and changes nothing.

**Response `200`** — the full settings object, as in
[`GET /merchant/settings`](#5-get-apiv1merchantsettings--get-the-shop-preferences),
with the changes applied:

```json
{
  "success": true,
  "message": "Settings saved",
  "data": {
    "vat_rate": 0.05,
    "vat_label": "5%",
    "vat_inclusive": false,
    "exchange_rate": 8500,
    "exchange_rate_source": "manual",
    "exchange_rate_updated_at": "2026-09-26T10:00:00Z",
    "receipt": { "footer": "Mahadsanid!", "show_logo": true, "print_automatically": false },
    "register": { "allow_price_override": false, "require_customer_on_hold": true, "scan_sound": true },
    "alerts": { "default_alarm_limit": 4, "default_stock_limit": 10 },
    "timezone": "Africa/Mogadishu",
    "language": "en"
  }
}
```

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.merchant_only` | Employees cannot change shop settings |
| `422` | `settings.rate_out_of_range` | The exchange rate is outside the allowed range (`error.details` has `min` and `max`) |
| `422` | `validation.failed` | Per-field messages in `error.details`, e.g. `vat_rate` above `1`, `language` not `en` or `so`, `receipt.footer` over 200 characters, an unknown `timezone`, a non-boolean toggle, or an alert limit outside `0` to `100000` |

```json
{
  "success": false,
  "message": "Please check the form",
  "error": {
    "code": "validation.failed",
    "details": { "vat_rate": ["The vat rate field must be between 0 and 1."], "language": ["The selected language is invalid."] }
  }
}
```

```json
{
  "success": false,
  "message": "That exchange rate looks wrong. Check it and try again.",
  "error": {
    "code": "settings.rate_out_of_range",
    "field": "exchange_rate",
    "details": { "min": 5000, "max": 15000 }
  }
}
```

### Settings of one specific shop (`/shops/{id}/settings`)

Settings are **per shop**: each shop has its own VAT, exchange rate, timezone and
preferences, stored on its own row in `shops` (`vat_rate`, `is_vat_inclusive`,
`exchange_rate`, `exchange_rate_updated_at`, `timezone`, `language`, and `preferences`
for `receipt`, `register` and `alerts`). The merchant's own row in `merchants` has none.

**Every shop stores its own full set of preferences** in its `shops.preferences` JSON
column: a new shop is created with all the values below, and existing shops were filled in
by a migration. `config/exelo.php` (`preference_defaults`) is only the **template for new
shops**; it is no longer what a shop reads its values from. Changing one value with
`PATCH` keeps the rest stored, and the other shops are untouched.

| Group | Keys and starting values |
| --- | --- |
| `receipt` | `footer` `null`, `show_logo` `true`, `print_automatically` `false` |
| `register` | `allow_price_override` `true`, `require_customer_on_hold` `true`, `scan_sound` `true` |
| `alerts` | `default_alarm_limit` `4`, `default_stock_limit` `10` |

A key added to the template later is filled in on read until the shop stores it, and
a row with no stored preferences reads as the template.

`GET` and `PATCH /merchant/settings` (endpoints 5 and 6) act on **the shop the session is
working in**. To read or change a **specific** shop, without switching to it, address it by
id:

| Method | Path | Who |
| --- | --- | --- |
| `GET` | `/api/v1/shops/{id}/settings` | The shop's owner, or its staff |
| `PATCH` | `/api/v1/shops/{id}/settings` | The shop's owner only |

`{id}` is a shop id (`shops[].id` from `GET /account` or `GET /shops`). The request
body, validation, response and errors are **exactly those of endpoints 5 and 6**, plus a
`shop` object saying which shop the settings belong to:

```json
{
  "success": true,
  "data": {
    "shop": { "id": 130, "business_name": "Berbera Mart" },
    "vat_rate": 0.1,
    "vat_label": "10%",
    "vat_inclusive": false,
    "exchange_rate": 9000,
    "exchange_rate_source": "manual",
    "exchange_rate_updated_at": "2026-09-26T10:00:00Z",
    "receipt": { "footer": null, "show_logo": true, "print_automatically": false },
    "register": { "allow_price_override": true, "require_customer_on_hold": true, "scan_sound": true },
    "alerts": { "default_alarm_limit": 4, "default_stock_limit": 10 },
    "timezone": "Africa/Mogadishu",
    "language": "en"
  }
}
```

```json
PATCH /api/v1/shops/130/settings
{ "vat_rate": 0.07, "exchange_rate": 8200, "receipt": { "footer": "Thanks for shopping" } }
```

Only the keys sent change, and only that shop: the other shops' settings and versions
are untouched.

**Extra errors:** `404 shop.not_found` when `{id}` isn't one of the caller's shops
(a staff member can only reach the shops they work in); `403 auth.merchant_only` for
staff on `PATCH`.

---

## Step by step: adding a payout wallet

```
GET   /merchant/wallets                     → see which rails are set and verified
PATCH /merchant/wallets   { wallets: [...] }  → 200, the new rail is "pending", verification_required: ["evc"]
POST  /merchants/{id}/verification/complete   → verification completes (registration.md, endpoint 8)
GET   /merchant/wallets                     → the rail is now "verified"
```

| State | Client does |
| --- | --- |
| `403 auth.merchant_only` | Hide the edit controls for employees |
| `409 wallet.number_taken` | Show the error under that number field |
| `422 payment.wallet_invalid` | Tell the owner which wallet the number belongs to |
| wallet `pending` | Show "Verify to start receiving payments" and do not offer that rail on the payment screen |

---

## Postman / curl quick start

```bash
BASE=https://your-host/api/v1

curl $BASE/merchant -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X PATCH $BASE/merchant -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -H 'If-Match: 4' \
  -d '{"business_name":"Exelo Retail & Wholesale","state":"maroodi_jeex","city":"Hargeisa"}'

curl $BASE/merchant/wallets -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X PATCH $BASE/merchant/wallets -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"wallets":[{"rail":"evc","number":"+252634444444"}],"default_rail":"zaad"}'

curl $BASE/merchant/settings -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl -X PATCH $BASE/merchant/settings -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "Authorization: Bearer $TOKEN" -d '{"exchange_rate":8500,"receipt":{"footer":"Mahadsanid!"}}'
```

---

## Implementation notes

Where the data lives (all on the `merchants` table, added by migration
`2026_09_20_000001_add_v1_profile_and_settings_columns_to_merchants`):

| Data | Column(s) |
| --- | --- |
| `version` / `If-Match` | `version`, bumped by one on every profile, wallet or settings write that changes something. A `PATCH` that changes nothing does not bump it. |
| Wallet numbers | Existing `zaad_number`, `edahab_number`, `golis_number`, `evc_number` (written in `+252…` format) |
| Wallet status | `wallet_states` (JSON: `status`, `verified_at`, `rejection_reason` per rail) and `default_rail`. A number with no stored state counts as `verified`, so shops registered before this module keep working. |
| Exchange rate | `exchange_rate` and `exchange_rate_updated_at`. When empty, the shop uses `CONVERSION_RATE` from the environment (`exchange_rate_source: "default"`). Once the owner sets one, the source is `"manual"`. |
| VAT, timezone, language | `vat_rate` (fraction, default `0.05`), `is_vat_inclusive`, `timezone` (default `Africa/Mogadishu`), `language` (`en` or `so`) |
| Receipt, register, alerts | `preferences` (JSON); keys never set fall back to the defaults in `config/exelo.php` |

Behaviour worth knowing:

- **Wallet verification.** A changed number becomes `pending`. It turns `verified`
  when a paid verification fee is consumed by
  [`POST /merchants/{id}/verification/complete`](registration.md#8-post-apiv1merchantsidverificationcomplete--confirm-payout-wallets).
  Nothing sets `rejected` yet; the status and `rejection_reason` are reserved for
  the wallet provider callback.
- **Exchange rate range.** `5000`–`15000` by default; override with
  `EXCHANGE_RATE_MIN` and `EXCHANGE_RATE_MAX`. The session's
  `merchant.exchange_rate` now returns the shop's own rate.
- **Owner-only writes** are enforced before validation, so an employee always
  gets `403 auth.merchant_only`, never a `422`.
- **Profile edits** also update the owner's login name and email, and rebuild
  `address.location` as `"City, State"`.
- **Logo.** `logo_file_id` resolves through the [Files module](files.md);
  send `null` to clear it. `logo` is `null` until one is set.
- **Legacy routes** (`PUT /api/update-merchants`, `GET /api/merchants/getPhoneNumbersStatus`,
  `POST /api/merchants/verificationComplete`) keep working alongside.

