# Data Model: Merchants and Shops

**One merchant has many shops.** Merchants and shops are stored in separate tables
(since 2026-09-26).

```text
users                      sign-in only: PIN, tokens, staff and admin logins
  │ 1
  │
  │ 1
merchants                  the merchant: own phone, name, date of birth, email, phone verification
  │ 1
  │
  │ many   shops.merchant_id → merchants.id
shops                      a shop: name, own phone, address, codes, payout wallets, plan
  │ 1
  │
  │ many   <table>.merchant_id → shops.id   (legacy column name, see below)
products · categories · carts · orders · invoices · sales · transactions
employees · shifts · files · merchant_subscriptions · personal_access_tokens
```

**Each shop has many employees**, and one person (one `users` row) can be an employee
of several shops, with one staff record per shop:

```text
merchants ──< shops ──< employees >── users        merchant → shops → employees
                          │  (unique per user + shop)
                          └─< employee_permissions   what they may do in that shop
shifts: user + shop         hours and pay are per shop
```

---

## Tables

### `users`: sign-in

Everyone who signs in: merchants, staff and admins. Holds only what sign-in needs:
`name`, `email`, `password`/`pin`, `pin_set_at`, lockout counters and
`user_type` (`merchant`, `employee`, admin types).

### `merchants`: the merchant

One row per merchant (`user_type = merchant`).

| Column | Holds |
| --- | --- |
| `id` | The merchant id |
| `user_id` | Their sign-in (`users.id`), unique |
| `first_name`, `last_name`, `dob` | The merchant's details |
| `email` | The merchant's email |
| `phone_number` | **The merchant's own mobile.** Unique. They sign in with it and verify it |
| `phone_verified_at` | Set when the verification fee was paid from `phone_number` |
| `created_at`, `updated_at`, `deleted_at` | |

Model: `App\Models\MerchantAccount` (see [Names in code](#names-in-code)).

### `shops`: the shops

One row per shop. (Before 2026-09-26 this table was called `merchants`; it was
renamed, so ids didn't change.)

| Column | Holds |
| --- | --- |
| `id` | The shop id (`shop_id` in the API) |
| `merchant_id` | **The merchant that owns the shop** (`merchants.id`) |
| `user_id` | The owner's sign-in (`users.id`), kept for existing code |
| `business_name` | Shop name |
| `phone_number` | **The shop's own mobile.** Unique; never the merchant's number |
| `email` | The shop's email |
| `state`, `state_code`, `city`, `location` | Address |
| `merchant_code`, `other_merchant_code` | eDahab agent code, Zaad merchant code |
| `zaad_number`, `edahab_number`, `golis_number`, `evc_number` | Payout wallets |
| `wallet_states`, `default_rail` | Wallet status (`pending` / `verified`) and default payout network |
| `vat_rate`, `is_vat_inclusive`, `exchange_rate`, `exchange_rate_updated_at`, `timezone`, `language` | The shop's settings (`NULL` `exchange_rate` uses the server default) |
| `preferences` | JSON: the shop's **full** `receipt`, `register` and `alerts` options, stored per shop. New shops start from `config('exelo.preference_defaults')` |
| `first_name`, `last_name`, `dob` | Owner details copied onto the shop (legacy) |
| `is_approved`, `version`, `deleted_at` | Active flag, edit version, closed date |

Models: `App\Models\Shop` for new code; `App\Models\Merchant` is the same table
for the legacy API.

### Tables that belong to a shop

`products`, `categories`, `carts`, `orders`, `invoices`, `sales`, `transactions`,
`files`, `merchant_subscriptions` and `personal_access_tokens` each
have a **`merchant_id` column that holds a shop id** (`shops.id`). The name comes
from before shops had their own table. It stays until the legacy API is retired,
then becomes `shop_id`.

`personal_access_tokens.merchant_id` is the **current shop of that device's
session** (see [multiple-shop.md](multiple-shop.md)).

> `employees` no longer uses the legacy name: it has `shop_id` and `merchant_id` (a real merchant id).

### `employees`: a person's job in one shop

One row per person **per shop**. It stores both links as foreign keys.

| Column | Holds |
| --- | --- |
| `user_id` | The person's sign-in (`users.id`, `user_type = employee`). Unique together with `shop_id` |
| `shop_id` | **The shop they work in** (FK `shops.id`). Unique together with `user_id` |
| `merchant_id` | **The merchant that owns that shop** (FK `merchants.id`), filled automatically from the shop |
| `phone_number`, `first_name`, `last_name`, `dob` | Their details |
| `role`, `salary`, `salary_currency`, `salary_period` | Their job and pay **in this shop** |
| `status`, `removed_at`, `former_phone_number` | `active` / `inactive`; removal keeps history |

Permissions live in `employee_permissions` (`employee_id` → `employees.id`), so they
are **per shop**. Someone who works in two shops has two `employees` rows and one
`users` row (one phone, one PIN).

### `shifts`: hours worked in a shop

`user_id` (the person) and `merchant_id` (the shop the shift was worked in). Hours,
payroll and "on shift" are always calculated per shop.

---

## Rules

| Rule | Enforced by |
| --- | --- |
| One merchant per sign-in | `merchants.user_id` unique |
| A merchant's phone and every shop's phone are all different | Unique `merchants.phone_number`, unique `shops.phone_number`, and `POST /shops` refuses a number used by any merchant or shop |
| Registration fee creates the merchant; verification fee verifies its phone | `POST /merchants`, `POST /account/verification/complete` |
| Shops are free; the first one needs a verified merchant phone | `POST /shops` (`409 account.phone_unverified`) |
| A new shop gets the merchant's verified number as its payout wallet | `RegistrationService::openShop()` |
| Closing a shop keeps its rows (soft delete) and reserves its number | `DELETE /shops/{id}` |
| A shop's staff belong to that shop only; the owner can manage all of them | `GET /account/employees`, `POST /employees` with `shop_id`, `POST /employees/{id}/transfer` |
| A person has at most one staff record per shop | Unique `employees(user_id, shop_id)` |
| A merchant's or a shop's number is never a staff number | `EmployeeService::phoneTaken()` |
| Permissions, shifts and hours are per shop | `User::actingEmployee()`, `shifts.merchant_id` |

---

## Names in code

| Concept | Table | Model | API |
| --- | --- | --- | --- |
| Sign-in | `users` | `User` | `user` |
| Merchant | `merchants` | `MerchantAccount` | `merchant` in `GET /account`; `merchant_account` in `POST /merchants` |
| Shop | `shops` | `Shop` (new code), `Merchant` (legacy) | `shop`, `shop_id`; `merchant` in session responses (the current shop) |
| Employee | `employees` (one row per person per shop) | `Employee` | `employee`, `shop` inside each employee |

The model for the `merchants` table is called `MerchantAccount` because the
existing `App\Models\Merchant` class has always meant a shop and is used
throughout the legacy code. Renaming it can happen with the column rename above.

Relationships:

```php
$user->merchantAccount;          // the merchants row
$user->shops;                    // the shops this sign-in owns
$merchantAccount->shops;         // same shops, through shops.merchant_id
$merchantAccount->user;          // the sign-in
$shop->merchantAccount;          // the merchant that owns the shop
$shop->owner;                    // the owner's sign-in
$shop->employees;                // its staff
$merchantAccount->employees;     // the staff of all its shops
$employee->shop;                 // the shop they work in
$user->employments;              // a person's staff records, one per shop
$user->actingEmployee();         // their staff record in the current shop (permissions)
$user->actingMerchant();         // the current shop of this session (a Shop/Merchant)
```

---

## API

| Endpoint | Returns |
| --- | --- |
| `GET /api/v1/account` | The merchant with **all their shops and each shop's details** |
| `PATCH /api/v1/account` | Edit the merchant's own details |
| `GET /api/v1/shops`, `GET/PATCH/DELETE /api/v1/shops/{id}`, `POST /api/v1/shops` | The shops (see [multiple-shop.md](multiple-shop.md)) |
| `GET /api/v1/account/employees` | The staff of **every** shop, with per-shop counts |
| `GET/PATCH /api/v1/shops/{id}/settings` | One shop's settings, stored on its own `shops` row ([merchant.md](merchant.md#settings-of-one-specific-shop-shopsidsettings)) |
| `GET /api/v1/merchant?include=all` | The current shop's profile **plus** the merchant, every shop, the full subscription and all staff, in one call ([merchant.md](merchant.md#everything-about-the-merchant-include)) |
| `POST /api/v1/employees` (`shop_id`), `POST /api/v1/employees/{id}/transfer` | Add staff to any of the owner's shops; move staff between them ([employees.md](employees.md)) |

Full requests and responses: [merchant-onboarding.md](merchant-onboarding.md#f-the-merchant-and-all-their-shops).

---

## Migrations

| Migration | Change |
| --- | --- |
| `2026_09_26_000002_move_shops_to_their_own_table` | Renames `merchants` → `shops` (same rows and ids; foreign keys follow) |
| `2026_09_26_000003_create_merchants_table` | Creates `merchants`, fills one row per merchant (details, own phone, verification), links every shop with `shops.merchant_id`, and removes those fields from `users` |
| `2026_09_26_000004_add_shop_status_index_to_employees_table` | Index `employees(merchant_id, status)` for per-shop staff lists and counts |
| `2026_09_26_000005_scope_shifts_and_staff_to_shops` | Adds `shifts.merchant_id` (backfilled) and the unique `employees(user_id, merchant_id)`. Refuses to run if a person already has two staff records in one shop |
| `2026_09_26_000006_store_full_preferences_on_every_shop` | Fills `shops.preferences` with the full receipt, register and alert options for every existing shop, keeping anything already set. Rolls back to storing only differences |

| `2026_09_26_000007_add_shop_id_and_merchant_id_to_employees_table` | Renames `employees.merchant_id` (a shop id) to `shop_id` (FK `shops`), adds a new `merchant_id` (FK `merchants`) backfilled from each shop's owner |

All roll back. The renamed `shops` table keeps its old constraint names (for
example `merchants_user_id_foreign`), so new constraints on either table must be
named explicitly.
