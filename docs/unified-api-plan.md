# EXELO — One Backend, One API

Plan for merging the Firebase data and the PHP/Laravel backend into a single
system.

This is a **plan document**. Nothing here is implemented yet.

> **Looking for the endpoint reference?** The full per-module API
> documentation — every request and response format — lives in
> [`docs/api/`](api/README.md). This document covers the reasoning, the
> migration and the rollout; that one covers the contract.

---

## 0. The short version

The Gold app currently talks to **three** stores:

| Store | What it holds today | Verdict |
| --- | --- | --- |
| Laravel API (~74 endpoints) | All business data: merchants, products, carts, orders, employees, payments, reports | **Keep. This is the one system.** |
| Firebase (project `mamafrica-20a3d`) | NFC tag passwords, one shared AES key, plus a dead legacy `users` login path | **Fold into Laravel, then delete** |
| Device (SQLite + SharedPreferences + secure storage) | Offline catalogue mirror, queued writes, held register lines | **Stays, but becomes a real sync client** |

Firebase is **not** the source of truth for any live business data. Only two
Firestore documents are actually in use. That makes this merge far smaller than
it looks — the hard part is not moving Firebase, it is that the offline layer
has no idempotency and one of its queues is never drained.

Three things must be fixed as part of this work, not after it:

1. `syncPendingCreates()` exists but is **never called** — products added offline
   are stranded on the device forever.
2. Held register lines replay with **no idempotency key** — a timeout after a
   successful `cart/add` silently double-charges the shopper.
3. The transaction report writes into the **inventory report table**, so the
   offline transaction report shows the wrong data.

---

## 1. Where data lives today

### 1.1 Laravel (`https://exelo.aiprofessionals.co`)

Single base URL in `lib/constants.dart:4`. Every call is
`Authorization: Bearer <auth_token>` except the pre-login and invoice paths.

Roughly 74 distinct paths across auth, registration, subscription, payments,
cart, inventory, employees, orders and reporting. The full legacy inventory is
mapped to the new surface in section 12.

> **Note:** at the time of writing this host answers with a LiteSpeed default
> page and returns 404 HTML for `/api/*`. Whatever the merge produces has to be
> deployed somewhere real; see section 11.

### 1.2 Firebase (project `mamafrica-20a3d`)

| Path | Status | Data |
| --- | --- | --- |
| `config/encryption` | **Live** | One base64 AES key, shared across devices |
| `nfc_tags/admin` | **Live** | Map of `{nfcTagId: encryptedPassword}` |
| `users/{uid}` | Dead | Legacy phone-OTP signup: `fullName`, `phoneNumber`, bcrypt `pin`, `isSubscribed` |
| `products/{docId}` | Dead | A `lastSoldTimestamp` backfill widget that is never navigated to |

Also present: anonymous sign-in at boot, App Check, and two Cloud Functions
(`generateClientToken`, `processPayment`) wrapping Braintree.

Firebase never issues `auth_token`. The Firebase UID and the Laravel session
never meet — there is no reconciliation code anywhere.

### 1.3 The device

SQLite (`pos_app.db`, schema v10) holds a catalogue mirror plus report caches,
and two queues: `pending_updates` and `pending_creates`. SharedPreferences holds
the session, the last-known dashboard snapshot, the last-known checkout snapshot,
and `held_register_ticket` — unpaid register lines that only exist on the phone.

### 1.4 External

- Braintree via Firebase Cloud Functions (card payments)
- A Stripe Cloud Function referenced by dead code and not present in the repo

---

## 2. Target architecture

```
              ┌──────────────────────────────┐
  Flutter ───▶│   EXELO API  (Laravel)       │
              │   /api/v1/*  one base URL    │
              └──────────────┬───────────────┘
                             │
        ┌────────────┬───────┴────────┬──────────────┐
        ▼            ▼                ▼              ▼
   MySQL/Postgres  Object store   Payment rails   Device keys
   (all business   (product &     (Zaad, eDahab,  (NFC, AES)
    data)           signatures)    Braintree)
```

One base URL. One token. One identity. No Firebase in the runtime path.

**Principles**

1. **Laravel wins.** It already owns everything that matters. Moving business
   data *into* Firebase would be a much larger migration for no benefit.
2. **Every write that can be retried gets an idempotency key.** This is the
   single biggest correctness gap today and offline retail is exactly where it
   bites.
3. **`/api/v1` from day one**, with the legacy unversioned routes kept alive as
   aliases until the fleet has upgraded.
4. **One response envelope** so the client can stop special-casing per endpoint.
5. **Server assigns all identity.** The client may propose a UUID; it never
   invents a primary key.

---

## 3. Conventions

### 3.1 Envelope

Every response, success or failure:

```json
{
  "success": true,
  "message": "Human readable, safe to show in a snackbar",
  "data": { },
  "meta": { "request_id": "req_01J...", "server_time": "2026-09-18T14:00:00Z" }
}
```

Errors:

```json
{
  "success": false,
  "message": "That barcode already belongs to another product",
  "error": {
    "code": "product.barcode_taken",
    "field": "bar_code",
    "details": { "existing_product_id": 4812 }
  },
  "meta": { "request_id": "req_01J..." }
}
```

`error.code` is a stable machine string. `message` is for the shopkeeper. The
app currently shows raw `message` in snackbars — that keeps working.

### 3.2 Status codes

| Code | Meaning |
| --- | --- |
| 200 | OK |
| 201 | Created |
| 202 | Accepted — queued, poll for result (invoices) |
| 400 | Malformed request |
| 401 | Missing/expired token → client must re-login |
| 403 | Authenticated but lacks the permission |
| 404 | No such resource for this merchant |
| 409 | Conflict — stale write, duplicate barcode, cart changed |
| 422 | Validation failed, `error.details` carries per-field messages |
| 429 | Rate limited |
| 5xx | Server fault — client falls back to cache |

The app currently treats "any HTTP answer" as online. That stays true, but 401
must become a hard signal to clear the session.

### 3.3 Auth

`Authorization: Bearer <token>` (Laravel Sanctum). Add:

- `X-EXELO-Device-Id` — stable per install, used for idempotency scoping and
  for shift/audit attribution
- `X-EXELO-App-Version` — so the server can warn or force-upgrade old clients

Tokens should carry: merchant id, acting user id, user type (`merchant` |
`employee`), plan, and the permission list — so the app stops re-deriving
permissions from a cached `user_detail` blob.

### 3.4 Idempotency (the important one)

Any **non-GET** endpoint that the offline layer can replay MUST accept:

```
Idempotency-Key: <uuid v4 generated once, at the moment of user intent>
```

Server behaviour: first call executes and the response is stored against the key
for 24h; any repeat with the same key returns **the original response**, not a
new write. Scope the key to `(merchant_id, device_id, key)`.

Required on at minimum:

- `POST /cart/items` (held-line replay — double-charge risk today)
- `POST /cart/pay` and every settlement endpoint
- `POST /products` (stranded offline creates)
- `PATCH /products/{id}`
- `POST /orders` and order status transitions
- `POST /inventory/transfers`

The key is generated when the shopkeeper taps, persisted next to the queued row,
and reused on every retry. Not regenerated per attempt.

### 3.5 Concurrency

Mutable resources return `version` (monotonic int) and accept
`If-Match: <version>`. Mismatch returns 409 with the current server copy in
`error.details.current`, so the app can show "this changed on another till".

### 3.6 Lists

`?page=1&per_page=50&updated_since=<iso8601>&q=<search>`, responding with
`meta.pagination`. `updated_since` is what makes delta sync possible.

### 3.7 Money

Never floats. Every amount is an integer of the smallest unit plus a currency:

```json
{ "amount": 1250, "currency": "USD", "display": "$12.50" }
```

Today the app parses money out of strings (`total_sales_in_usd: "420.00"`) and
does `double.tryParse` in the UI. Fix it at the boundary.

---

> **Sections 4–16 are a summary with the reasoning behind each design choice.**
> For the full contract — every field, every request and response body, every
> error — see the module reference in [`docs/api/`](api/README.md).

---

## 4. Auth & session

Reference: [`docs/api/auth.md`](api/auth.md)

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| POST | `/api/v1/auth/lookup` | — | Given a phone, say whether it is a merchant or employee and whether a PIN exists |
| POST | `/api/v1/auth/pin/login` | — | Exchange phone + PIN for a token |
| POST | `/api/v1/auth/pin` | — | Create the first PIN after registration |
| PATCH | `/api/v1/auth/pin` | Bearer | Change PIN (old + new) |
| POST | `/api/v1/auth/pin/verify` | Bearer | Re-assert PIN for a sensitive action (adding staff) |
| POST | `/api/v1/auth/pin/reset/request` | — | Send OTP to reset a forgotten PIN |
| POST | `/api/v1/auth/pin/reset/verify` | — | Verify the OTP, returns a short-lived reset token |
| POST | `/api/v1/auth/pin/reset` | reset token | Set the new PIN |
| GET | `/api/v1/auth/session` | Bearer | Current user, merchant, plan, permissions |
| POST | `/api/v1/auth/logout` | Bearer | Revoke this device's token |

**Collapses today's split paths.** Merchants and employees currently use
different endpoints for the same act (`/api/login/verifyUser` vs
`/api/employee/verifyEmployee`, `/api/merchants/store-pin` vs
`/api/employee/store-pin`). One endpoint, with `user_type` in the response.

`POST /api/v1/auth/pin/login`

```json
{ "phone_number": "+252634110101", "pin": "1234", "device_id": "dev_..." }
```

```json
{
  "success": true,
  "data": {
    "token": "2|abc...",
    "expires_at": "2026-12-01T00:00:00Z",
    "user": { "id": 91, "type": "merchant", "first_name": "Kalid", "last_name": "Ahmed" },
    "merchant": { "id": 12, "business_name": "Exelo Retail", "state": "Maroodi Jeex", "city": "Hargeisa" },
    "subscription": { "plan_id": 1, "plan": "gold", "status": "active" },
    "permissions": ["POS", "Inventory", "Transactions", "Employee Management", "Reports"]
  }
}
```

`GET /api/v1/auth/session` replaces `/api/login/userinfo` and is what the app
should cache as `user_detail`. Returning `permissions` and `subscription`
together removes the current second call to
`/api/merchants/subscriptions/current` at splash — one less round trip on a
slow line.

**Decision needed:** token lifetime and refresh. A shop on a weak link should
not be logged out mid-sale. Recommend long-lived device tokens with server-side
revocation rather than short tokens plus refresh.

---

## 5. Registration & onboarding

Reference: [`docs/api/registration.md`](api/registration.md)

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/registration/quote` | — | Fee quote for a given amount (replaces `transaction/process`) |
| POST | `/api/v1/registration/phone/check` | — | Duplicate phone / existing registration check |
| POST | `/api/v1/registration/invoices` | — | Issue the signup-fee invoice on Zaad or eDahab |
| GET | `/api/v1/registration/invoices/{id}` | — | Poll payment status |
| POST | `/api/v1/merchants` | — | Create the merchant once the fee is paid |
| POST | `/api/v1/merchants/{id}/verification/complete` | Bearer | Finish wallet verification |
| GET | `/api/v1/geo/states` | — | The five states for the registration dropdown |

`POST /api/v1/merchants`

```json
{
  "first_name": "Kalid",
  "last_name": "Ahmed",
  "dob": "1990-01-01",
  "state": "Maroodi Jeex",
  "city": "Hargeisa",
  "business_name": "Exelo Retail",
  "email": "kalid@exelo.co",
  "phone_number": "+252634110101",
  "wallets": { "edahab_code": "102", "zaad_code": "88" },
  "invoice_id": "inv_..."
}
```

**Two cleanups here.**

*State and city.* The app now sends `location` (city) plus an optional `state`.
The unified API should carry `state` and `city` as first-class columns and keep
`location` as a computed read-only string (`"Hargeisa, Maroodi Jeex"`) so old
clients and receipts keep working. `GET /geo/states` means the five states stop
being hardcoded in the app.

*Invoice polling.* Today the client polls `invoice/status` on a timer. Add a
webhook from the wallet provider plus `GET .../invoices/{id}` for the client, so
a shop on a weak link is not burning requests in a loop.

---

## 6. Subscription & plans

Reference: [`docs/api/subscription.md`](api/subscription.md)

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/plans` | — | Catalogue of plans, prices, features |
| GET | `/api/v1/subscription` | Bearer | Current plan and status |
| POST | `/api/v1/subscription/change` | Bearer | Upgrade/downgrade (returns an invoice to pay) |
| POST | `/api/v1/subscription/cancel` | Bearer | Cancel (endpoint exists only commented-out today) |

Plan gating is currently a magic string: `subscription_plan_id == "2"` means
Silver. Replace with an explicit `plan: "gold" | "silver"` plus a `features`
array so the app stops hardcoding the comparison, and so a third tier does not
require an app release.

---

## 7. Payments & wallets

Reference: [`docs/api/payments.md`](api/payments.md)

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/payments/methods` | Bearer | Wallets on file and their verification status |
| POST | `/api/v1/payments/quote` | Bearer | Fees/split for an amount (one replacement for `transaction/process`) |
| POST | `/api/v1/payments/charges` | Bearer | Start a charge on any rail — **idempotent** |
| GET | `/api/v1/payments/charges/{id}` | Bearer | Poll charge status |
| POST | `/api/v1/payments/charges/{id}/confirm` | Bearer | Confirm (Zaad two-step commit) |
| POST | `/api/v1/payments/payouts` | Bearer | Merchant sends money to a phone |
| POST | `/api/v1/payments/card/session` | Bearer | Braintree client token (replaces the Cloud Function) |
| POST | `/api/v1/webhooks/payments/{provider}` | signature | Provider callbacks |

`POST /api/v1/payments/charges`

```json
{
  "rail": "zaad",
  "amount": { "amount": 125000, "currency": "SLSH" },
  "purpose": "pos_sale",
  "order_id": 8812,
  "customer_phone": "+252634110101"
}
```

```json
{
  "data": {
    "charge_id": "chg_01J...",
    "status": "pending",
    "provider_ref": { "transaction_id": "...", "reference_id": "..." },
    "next_action": "confirm"
  }
}
```

**This is the biggest simplification in the whole plan.** Today there are
parallel near-duplicate flows for Zaad vs eDahab vs cash vs card, split again
between Gold (`payments.dart`) and Silver (`paymentsforsmall.dart`), with
endpoints differing only by a `/zaad` path segment, and the registration fee /
PIN-reset fee / POS sale all reusing `transaction/process` with a different
`type` string. One `charges` resource with a `rail` and a `purpose` replaces all
of it.

**Braintree must move.** The card path currently calls two Cloud Functions
directly from the app. The Braintree keys are committed in `functions/index.js`
as literal fallbacks and in `.env`. Move card payments behind
`/api/v1/payments/*`, rotate every Braintree credential, and confirm whether the
gateway should be Sandbox or Production — the deployed function is pinned to
**Sandbox**.

---

## 8. POS: cart & checkout

Reference: [`docs/api/cart.md`](api/cart.md)

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/cart` | Bearer | Current cart with totals |
| POST | `/api/v1/cart/items` | Bearer | Add a line — **idempotent** |
| PATCH | `/api/v1/cart/items/{product_id}` | Bearer | Change quantity or price |
| DELETE | `/api/v1/cart/items/{product_id}` | Bearer | Remove a line |
| DELETE | `/api/v1/cart` | Bearer | Clear |
| POST | `/api/v1/cart/sync` | Bearer | **New.** Reconcile a whole offline ticket in one call |
| POST | `/api/v1/cart/pay` | Bearer | Complete the sale — **idempotent** |
| POST | `/api/v1/cart/hold` | Bearer | Park as a pending order with customer + signature |

`GET /api/v1/cart` returns one totals object instead of today's loose fields
(`subtotal`, `vat`, `exelo_amount`, `total`, `total_in_sls`, `subtotal_in_sls`):

```json
{
  "data": {
    "cart_id": 4471,
    "type": "shop",
    "version": 7,
    "items": [
      { "product_id": 812, "name": "Sugar 1kg", "quantity": 2,
        "unit_price": { "amount": 150, "currency": "USD" },
        "line_total": { "amount": 300, "currency": "USD" } }
    ],
    "totals": {
      "subtotal": { "amount": 300, "currency": "USD" },
      "vat":      { "amount": 15,  "currency": "USD" },
      "fee":      { "amount": 5,   "currency": "USD" },
      "total":    { "amount": 320, "currency": "USD" },
      "total_alt":{ "amount": 256000, "currency": "SLSH" }
    }
  }
}
```

### `POST /api/v1/cart/sync` — the offline fix

Replaces the current loop that replays held lines one `cart/add` at a time and
can duplicate them.

```json
{
  "idempotency_key": "9c1f...",
  "client_ticket_id": "tkt_local_18",
  "lines": [
    { "product_id": 812, "quantity": 2, "client_line_id": "ln_1" },
    { "product_id": 907, "quantity": 1, "client_line_id": "ln_2" }
  ]
}
```

The server reconciles the whole ticket in one transaction and returns the
authoritative cart. Lines already applied under the same key are not re-added.
Per-line results report anything rejected (product deleted, out of stock) so the
till can show exactly which item failed instead of silently dropping it.

**Payment stays online-only.** Nothing in this plan should be read as allowing
an offline "paid" state. Held lines are unpaid lines.

---

## 9. Orders

Reference: [`docs/api/orders.md`](api/orders.md)

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/orders?status=pending\|complete` | Bearer | List |
| GET | `/api/v1/orders/{id}` | Bearer | Detail |
| POST | `/api/v1/orders` | Bearer | Create from cart — **idempotent** |
| PATCH | `/api/v1/orders/{id}/status` | Bearer | pending ⇄ complete |
| POST | `/api/v1/orders/{id}/pay` | Bearer | Settle a pending order — **idempotent** |
| DELETE | `/api/v1/orders/{id}` | Bearer | Delete a pending order |
| GET | `/api/v1/orders/{id}/receipt` | Bearer | Receipt payload |
| GET | `/api/v1/invoices/{id}/receipt` | Bearer | Receipt for a request-payment invoice |

One `orders` resource replaces `cart/placeOrder`, `cart/paidOrder`,
`cart/placePendingOrder`, `cart/updateOrderStatusToComplete`,
`cart/updateOrderStatusToPending` and `order/allByStatus`.

Customer signatures are currently posted as a base64 data URL inside JSON. Move
to `POST /api/v1/files` and store a reference — base64 in the request body on a
weak link is a slow, failure-prone upload.

---

## 10. Inventory & catalogue

Reference: [`docs/api/inventory.md`](api/inventory.md)

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/products` | Bearer | List — supports `updated_since`, `q`, `category_id`, `type`, paging |
| GET | `/api/v1/products/{id}` | Bearer | Detail |
| POST | `/api/v1/products` | Bearer | Create — **idempotent**, accepts `client_uuid` |
| PATCH | `/api/v1/products/{id}` | Bearer | Update — **idempotent**, `If-Match` |
| DELETE | `/api/v1/products/{id}` | Bearer | Soft delete (tombstone) |
| GET | `/api/v1/products/lookup?barcode=&type=` | Bearer | Scan lookup |
| GET | `/api/v1/categories` | Bearer | List / search |
| POST | `/api/v1/categories` | Bearer | Create |
| POST | `/api/v1/inventory/transfers` | Bearer | Move stock — **idempotent** |
| PATCH | `/api/v1/inventory/{product_id}/quantities` | Bearer | Manual quantity correction |
| GET | `/api/v1/inventory/alerts?type=alarm\|restock` | Bearer | Low-stock lists |

`POST /api/v1/inventory/transfers` replaces four near-identical endpoints
(`shop-to-stock`, `stock-to-shop`, `shop-to-transportation`,
`stock-to-transportation`):

```json
{ "product_id": 812, "quantity": 5, "from": "shop", "to": "stock", "idempotency_key": "..." }
```

**Three specific fixes this module must carry.**

*Offline-created products need real identity.* The client generates a
`client_uuid` when the shopkeeper saves offline. On sync the server returns the
real `id` **and** echoes `client_uuid`, so the device can rewrite its local row
instead of orphaning it. Today `pending_creates` has only an autoincrement row
id and is never synced at all.

*Deletes need tombstones.* The local mirror never prunes, so a product deleted
on another till stays sellable on this one. `GET /products?updated_since=`
must include soft-deleted rows with `deleted_at` set.

*Update is a POST today.* `POST /api/products/{id}` with multipart is really a
PATCH. The new API uses PATCH with JSON, and images go through the file
endpoint.

---

## 11. Files & media

Reference: [`docs/api/files.md`](api/files.md)

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| POST | `/api/v1/files` | Bearer | Upload (product image, signature), returns `file_id` + URL |
| GET | `/api/v1/files/{id}` | Bearer/signed | Fetch |

Today images are multipart fields on the product endpoints and are read back
from `${baseUrl}/public/<path>` — a raw public path, guessable across merchants.
Move to opaque ids and signed URLs, and return a `thumb` variant; the inventory
grid currently pulls full-size images on a phone connection.

Note that the app already resolves `https://exelo.aiprofessionals.co` to a
parked LiteSpeed page. Wherever the unified API lands, image URLs must be
returned absolute by the server, not assembled in the client from a base URL
constant.

---

## 12. Employees & shifts

Reference: [`docs/api/employees.md`](api/employees.md)

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/employees` | Bearer | List |
| POST | `/api/v1/employees` | Bearer | Create with permissions |
| GET | `/api/v1/employees/{id}` | Bearer | Detail + sales metrics |
| PATCH | `/api/v1/employees/{id}` | Bearer | Update |
| DELETE | `/api/v1/employees/{id}` | Bearer | Remove |
| GET | `/api/v1/permissions` | Bearer | Assignable permissions |
| GET | `/api/v1/employees/summary` | Bearer | Team KPI strip |
| GET | `/api/v1/shifts` | Bearer | Shift history |
| POST | `/api/v1/shifts/start` | Bearer | Clock in |
| POST | `/api/v1/shifts/{id}/end` | Bearer | Clock out |
| PATCH | `/api/v1/shifts/{id}` | Bearer | Correct times |

Today start and end are the same `POST /api/user/shift` distinguished only by
which field is present, which makes a double-tap ambiguous. Split them.

Permissions are matched by **display name** in the app (`'Employee Management'`).
Give each permission a stable `key` (`employees.manage`) and keep `name` for
display, so renaming a label in the admin panel does not silently lock a
shopkeeper out of their own dashboard.

---

## 13. Dashboard & reports

Reference: [`docs/api/dashboard.md`](api/dashboard.md)

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/dashboard` | Bearer | One payload: KPIs, weekly series, recent activity |
| GET | `/api/v1/reports/sales` | Bearer | Sales report, date range |
| GET | `/api/v1/reports/inventory` | Bearer | Inventory report |
| GET | `/api/v1/reports/products` | Bearer | `?metric=sold\|in_shop\|in_stock\|new_shop\|new_stock` |
| GET | `/api/v1/reports/catalogue` | Bearer | Products with categories and totals |

`GET /api/v1/dashboard?weeks=4`

```json
{
  "data": {
    "revenue": {
      "total": { "amount": 428050, "currency": "USD" },
      "total_alt": { "amount": 34244000, "currency": "SLSH" },
      "change_pct": 12.4
    },
    "series": {
      "granularity": "day",
      "points": [
        { "date": "2026-09-08", "day": "Mon",
          "sales": { "amount": 42000, "currency": "USD" }, "products_sold": 38 }
      ]
    },
    "orders":    { "pending": 4, "complete": 121 },
    "inventory": { "in_shop": 123, "in_stock": 393, "alarm": 6, "restock": 11 }
  }
}
```

Two things worth fixing while specifying this:

*The week window.* The revenue chart pages by week and needs history. The
`weeks` parameter lets the dashboard ask for four weeks in one call rather than
the current fixed seven-day series.

*Five endpoints collapse into one.* `getNewShopProductsListing`,
`getTotalProductsInShop`, `getNewStockProductsListing`, `getTotalProductsInStock`
and `getSoldProducts` are the same report with a different filter.

---

## 14. NFC & device keys — the actual Firebase migration

Reference: [`docs/api/nfc.md`](api/nfc.md)

This is the only Firebase data that has to move.

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/nfc/keys/current` | Bearer | Current merchant encryption key (wrapped) |
| POST | `/api/v1/nfc/keys/rotate` | Bearer | Rotate, keeping the old key readable |
| GET | `/api/v1/nfc/tags` | Bearer | Registered tags |
| POST | `/api/v1/nfc/tags` | Bearer | Register a tag with its password hash |
| POST | `/api/v1/nfc/tags/{tag_id}/verify` | Bearer | Verify a tag password |
| DELETE | `/api/v1/nfc/tags/{tag_id}` | Bearer | Revoke |

Migrating as-is would carry two real problems, so the plan changes the shape:

1. **`config/encryption` is one AES key for every merchant on the platform.**
   Any device that ever booted can read it. The unified API must scope the key
   **per merchant** and re-encrypt existing tags during migration.
2. **`nfc_tags/admin` is a single shared document** holding every tag password
   under one key. Passwords should be verified **server-side** against a hash,
   not fetched to the device for local comparison.

Both Firestore documents are read/written by `lib/nfc_functionality.dart` with
the AES key cached in `FlutterSecureStorage` under `exelo_encryption_key`. That
cache stays as an offline convenience; Firestore stops being the fallback.

---

## 15. Sync & offline contract (new surface)

Reference: [`docs/api/sync.md`](api/sync.md)

None of this exists today; it is what makes the device a well-behaved client.

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/sync/manifest` | Bearer | Per-collection server cursors and counts |
| GET | `/api/v1/sync/changes?since=<cursor>` | Bearer | Delta for products, categories, prices, employees — includes tombstones |
| POST | `/api/v1/sync/mutations` | Bearer | Batch replay of queued offline writes |
| GET | `/api/v1/health` | — | Cheap liveness probe |

`POST /api/v1/sync/mutations` takes the queue as a list, each entry carrying its
own `idempotency_key` and `client_uuid`, and returns a per-entry result
(`applied` | `duplicate` | `conflict` | `rejected`) with the server id. That is
what lets the device drain `pending_creates`, `pending_updates` and
`held_register_ticket` safely and know precisely what to delete locally.

`GET /api/v1/health` replaces the current connectivity probe, which fetches the
**site root** — a full HTML page — just to decide whether the shop is online.

---

## 16. Profile & settings

Reference: [`docs/api/merchant.md`](api/merchant.md)

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/merchant` | Bearer | Merchant profile |
| PATCH | `/api/v1/merchant` | Bearer | Update name, business name, state, city, wallet codes |
| GET | `/api/v1/merchant/wallets` | Bearer | Wallet numbers and verification state |
| PATCH | `/api/v1/merchant/wallets` | Bearer | Update wallet numbers |

---

## 17. Legacy → unified mapping

All 74 legacy routes are mapped to their v1 replacement in
[`docs/api/legacy-mapping.md`](api/legacy-mapping.md), grouped by module and
annotated with what changed.

**74 legacy paths → 63 unified endpoints.** The reduction is almost entirely
duplication: merchant and employee auth were separate endpoint families for
identical acts, Gold and Silver had parallel payment flows, four inventory
transfer endpoints differed only by direction, and five product-stat endpoints
were the same report with a different filter.

Old routes stay alive as aliases through Phase 5 (section 19).

---

## 18. Data migration

| # | Data | From | To | Notes |
| --- | --- | --- | --- | --- |
| 1 | NFC encryption key | Firestore `config/encryption` | `nfc_keys` table, per merchant | Today one global key. Split per merchant and re-wrap existing tags. |
| 2 | NFC tag passwords | Firestore `nfc_tags/admin` | `nfc_tags` table | Store hashes, verify server-side. Requires one pass over the existing map. |
| 3 | Legacy Firebase users | Firestore `users` | — | Reconcile against Laravel merchants by phone. Expect this to be empty or stale; confirm before deleting. |
| 4 | Firestore `products` | Firestore | — | Drop. Only a dead backfill widget touches it. |
| 5 | Braintree credentials | `.env` + hardcoded in `functions/index.js` | Server-side secret store | **Rotate all of them.** They are committed to git. |
| 6 | Stranded offline products | Device `pending_creates` | `POST /sync/mutations` | Needs a one-time app release that drains the queue before Firebase/API cutover. Data is otherwise lost. |
| 7 | Held register lines | Device `held_register_ticket` | `POST /cart/sync` | Unpaid lines. Must survive the upgrade. |

Item 6 is the one with a deadline attached: those rows exist only on merchants'
phones, and a reinstall loses them.

---

## 19. Phased rollout

Each phase ships independently and leaves the app working.

**Phase 1 — Foundations (server only, no app change)**
Stand up `/api/v1` beside the existing routes. Envelope, error codes, Sanctum
tokens, idempotency middleware, `/health`. Legacy routes proxy to the new
services so both surfaces share one implementation. Deploy to a host that is
actually serving the API.

**Phase 2 — Read paths**
Move the app to `/api/v1` for session, dashboard, products, categories, orders,
employees, reports. Read-only, so a rollback is safe. Delete-tombstones and
`updated_since` land here.

**Phase 3 — Write paths + idempotency**
Cart, payments, orders, product create/update. Every write carries an
idempotency key. `POST /cart/sync` and `POST /sync/mutations` replace the
one-at-a-time replay loops. **This is where the double-charge risk is closed.**
Wire `syncPendingCreates()` at the same time.

**Phase 4 — Firebase decommission**
Move NFC keys and tags. Move Braintree behind the API and rotate keys. Remove
`firebase_storage`, `firebase_database`, `cloud_functions` (all already unused),
then `cloud_firestore`, `firebase_auth`, `firebase_app_check`, `firebase_core`.
Delete the dead login path (`newuser.dart`, `olduser.dart`, `profile_settings.dart`,
`subscription.dart`, `Models/userinfo.dart`, `Models/user_provider.dart`).
Removing anonymous sign-in also removes two 8-second boot timeouts on a weak
line.

**Phase 5 — Retire legacy routes**
Once telemetry shows no traffic on the old paths, delete them.

**Sequencing constraint:** Phase 3 must ship before Phase 4, because draining
queued offline writes needs the idempotent endpoints to exist. And an app
release that drains `pending_creates` must reach merchants before any server
cutover that would reject the legacy shape.

---

## 20. Bugs and gaps this work must fix

Found during the audit. Each is a real defect today, not a design preference.

| # | Problem | Impact | Where |
| --- | --- | --- | --- |
| 1 | `syncPendingCreates()` is never called | Products added offline are **permanently stranded** | `lib/OfflineHelper/sync_helper.dart:107` |
| 2 | Held-line replay has no idempotency | Server-success + client-timeout ⇒ **duplicate cart lines, shopper overcharged** | `lib/newCashRegistered.dart:677` |
| 3 | Transaction report saves into `inventory_reports` | Offline sales report shows the **wrong data** | `lib/API/report_service.dart:37` |
| 4 | `pending_updates` writes `id`, schema declares `product_id` | Column is always null; fragile replay | `lib/OfflineHelper/db_helper.dart:73`, `lib/inventory_page.dart:1291` |
| 5 | Catalogue mirror never prunes | Deleted products stay sellable offline | `lib/OfflineHelper/db_helper.dart:301` |
| 6 | Logout clears only 2 of ~10 keys | Held ticket, queues, plan and cached stats survive a logout → **next user sees the last shop's data** | `lib/API/dashboard_service.dart:100` |
| 7 | Braintree keys committed in git, gateway pinned to Sandbox | Credential exposure; card payments may not be live | `functions/index.js:19-22`, `.env` |
| 8 | Firebase API keys and `google-services.json` committed | Exposure | `lib/main.dart:44-67`, `web/index.html` |
| 9 | One global NFC AES key for all merchants | Any device can decrypt any merchant's tags | Firestore `config/encryption` |
| 10 | Connectivity probe fetches the full site root | Wasted bytes on exactly the connections that can least afford it | `lib/OfflineHelper/isConnectivity.dart:13` |
| 11 | Permissions matched by display name | Renaming a label locks merchants out | `lib/dashboard.dart:204` |
| 12 | Money handled as parsed strings | Rounding and display drift | throughout |

Items 1, 2 and 6 are the ones that cost a shopkeeper money or data.

---

## 21. Open questions

These need your decision before the spec is frozen.

1. **Where does the API actually run?** `exelo.aiprofessionals.co` currently
   serves a parked LiteSpeed page and 404s on `/api/*`. Is there another live
   host, or does this merge include a deployment?
2. **Is the Laravel source available?** It is not in this repo. The plan assumes
   it can be modified; if it cannot, the shape changes completely.
3. **Card payments — live or not?** The Braintree function is pinned to Sandbox.
4. **Is the legacy Firebase `users` collection real?** If shops registered
   through the old phone-OTP flow, they need reconciling. If it is empty, that
   whole migration step disappears.
5. **How many devices are in the field with unsynced `pending_creates`?** This
   sets how urgent the drain release is.
6. **Multi-till?** If two devices share one merchant account, the conflict rules
   in 3.5 matter a lot more, and cart becomes per-device rather than per-merchant.
7. **Token lifetime**, given shops that can be offline for a long stretch.
8. **Do you want push** (order paid, stock low)? If yes, that is the one reason
   to keep a Firebase dependency — FCM — and it should be decided now rather
   than after the SDKs are removed.

---

## 22. Recommended next step

Freeze the answers to section 21, then specify Phase 1 as OpenAPI and generate a
typed Dart client from it, so the app stops hand-rolling ~74 call sites with
inconsistent response parsing.

The highest-value work is not the Firebase merge. Firebase holds two documents.
The value is in **section 20, items 1, 2 and 6** — the offline layer currently
loses products, can double-charge shoppers, and leaks one shop's data into the
next session. Fold that into Phase 3 and the merge pays for itself.
