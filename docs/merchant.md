# Merchant Profile & Settings

The shop's own details, payout wallets and preferences.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/merchant`](#get-merchant) | Bearer |
| PATCH | [`/merchant`](#patch-merchant) | Bearer · merchant only |
| GET | [`/merchant/wallets`](#get-merchantwallets) | Bearer |
| PATCH | [`/merchant/wallets`](#patch-merchantwallets) | Bearer · merchant only |
| GET | [`/merchant/settings`](#get-merchantsettings) | Bearer |
| PATCH | [`/merchant/settings`](#patch-merchantsettings) | Bearer · merchant only |

Employees can read this module; only the merchant can write to it.

---

## GET /merchant

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
  }
}
```

### `location` is computed

`address.location` is **read-only**, derived from `state` and `city`. It exists
so receipts and any client still reading the legacy single `location` field
keep working. Writes go to `state` and `city`.

---

## PATCH /merchant

Replaces `PUT /api/update-merchants`.

**Request**

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
| `email` | string | no | |
| `state` | string | no | A `code` from [`GET /geo/states`](registration.md#get-geostates) |
| `city` | string | no | |
| `merchant_code` | string | no | |
| `other_merchant_code` | string | no | |
| `logo_file_id` | string | no | From [`POST /files`](files.md#post-files) |

The phone number is **not** editable here — it is the login identity. Changing
it needs OTP verification on both the old and new number, which is a separate
flow and not currently in the app.

Send `If-Match: <version>` to avoid clobbering a change from another device.

**Response `200`** — the updated merchant.

**Errors** — `403 auth.merchant_only`, `409 resource.version_conflict`,
`422 validation.failed`.

---

## GET /merchant/wallets

The numbers the shop gets paid on, and their verification state. Overlaps with
[`GET /payments/methods`](payments.md#get-paymentsmethods), which is the
payment-screen view; this one is the settings view.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "wallets": [
      { "rail": "zaad",   "label": "Zaad",   "number": "+252632222222", "status": "verified",
        "verified_at": "2026-02-03T10:00:00Z", "is_default": true },
      { "rail": "edahab", "label": "eDahab", "number": "+252631111111", "status": "verified",
        "verified_at": "2026-02-03T10:02:00Z", "is_default": false },
      { "rail": "golis",  "label": "Golis",  "number": null, "status": "not_set",
        "verified_at": null, "is_default": false },
      { "rail": "evc",    "label": "EVC",    "number": null, "status": "not_set",
        "verified_at": null, "is_default": false }
    ],
    "default_rail": "zaad"
  }
}
```

| `status` | Meaning |
| --- | --- |
| `verified` | Confirmed, can receive money |
| `pending` | Awaiting verification |
| `rejected` | Verification failed; `rejection_reason` present |
| `not_set` | No number on file |

---

## PATCH /merchant/wallets

Adds or changes payout numbers.

**Request**

```json
{
  "wallets": [
    { "rail": "zaad", "number": "+252632222222" },
    { "rail": "evc",  "number": "+252634444444" }
  ],
  "default_rail": "zaad"
}
```

Only the rails present are touched. Send `"number": null` to remove one.

Changing a number resets that wallet to `pending` and triggers verification —
a merchant must not be able to silently redirect payouts to a new number.

**Response `200`**

```json
{
  "success": true,
  "message": "EVC number added. Verify it to start receiving payments there.",
  "data": {
    "wallets": [
      { "rail": "zaad", "number": "+252632222222", "status": "verified", "is_default": true },
      { "rail": "evc",  "number": "+252634444444", "status": "pending",  "is_default": false }
    ],
    "verification_required": ["evc"]
  }
}
```

Verification completes through
[`POST /merchants/{id}/verification/complete`](registration.md#post-merchantsidverificationcomplete).

---

## GET /merchant/settings

Shop preferences that change app behaviour.

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

These are currently hardcoded in the client or absent entirely. Moving them
server-side means a shop can change its VAT rate or exchange rate without an
app release — and on the exchange rate in particular, that the rate used for
SLSH display is the same on every till.

---

## PATCH /merchant/settings

**Request**

```json
{
  "exchange_rate": 8500,
  "receipt": { "footer": "Mahadsanid!" },
  "register": { "allow_price_override": false }
}
```

Partial and nested — only the keys sent are changed.

**Response `200`** — the full settings object.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `403` | `auth.merchant_only` | Employees cannot change shop settings |
| `422` | `settings.rate_out_of_range` | Sanity bound on the exchange rate |

> The exchange rate is guarded deliberately. It multiplies every SLSH price in
> the app, so a typo of one extra zero would misprice the entire catalogue on
> every till at once.
