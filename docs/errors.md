# Error Catalogue

Every error the API can return, with the status code and what the client should
do about it.

Branch on `error.code`, never on `message`. Messages are written for
shopkeepers and will be reworded and translated; codes are a contract.

---

## Shape

```json
{
  "success": false,
  "message": "That barcode already belongs to another product",
  "error": {
    "code": "product.barcode_taken",
    "field": "bar_code",
    "details": { "existing_product_id": 4812 }
  },
  "meta": { "request_id": "req_01JBXQ7K2M9F", "server_time": "2026-09-18T14:03:11Z" }
}
```

| Field | Notes |
| --- | --- |
| `message` | Safe to display verbatim |
| `error.code` | Stable `domain.reason` string |
| `error.field` | Present on single-field validation failures |
| `error.details` | Structured context. Contents documented per code below. |
| `meta.request_id` | Include this when reporting a problem — it locates the server log |

---

## Validation

`422` with per-field messages:

```json
{
  "success": false,
  "message": "Please check the form",
  "error": {
    "code": "validation.failed",
    "details": {
      "state": ["Choose a state"],
      "dob": ["Enter a valid date of birth"],
      "price": ["Price must be greater than zero"]
    }
  }
}
```

`details` is always `{ field: [messages] }`. Render each under its input.

---

## Auth

| Code | Status | Meaning | Client action |
| --- | --- | --- | --- |
| `auth.invalid_credentials` | 401 | Wrong PIN | Show `attempts_remaining` from `details` |
| `auth.token_invalid` | 401 | Token malformed or unknown | Clear session, go to login |
| `auth.token_expired` | 401 | Token past its lifetime | Clear session, go to login |
| `auth.token_revoked` | 401 | Signed out elsewhere, or staff removed | Clear session, explain why |
| `auth.locked` | 423 | Too many failed PIN attempts | Show countdown from `details.retry_after` |
| `auth.confirmation_required` | 401 | Missing `X-EXELO-Confirmation` | Prompt for the PIN, retry |
| `auth.merchant_only` | 403 | Employees cannot do this | Hide the action |
| `auth.permission_denied` | 403 | Lacks the permission | `details.required_permission` |
| `auth.employee_disabled` | 403 | Staff account removed | Clear session |
| `auth.registration_incomplete` | 403 | Signup never finished | Resume registration |
| `auth.pin_already_set` | 409 | Use reset, not create | Route to PIN reset |
| `auth.pin_not_set` | 409 | Account has no PIN yet | Route to PIN creation |
| `auth.pin_too_weak` | 422 | `0000`, `1234`, repeated digits | Ask for another PIN |
| `auth.otp_invalid` | 422 | Wrong code | `details.attempts_remaining` |
| `auth.otp_expired` | 410 | Code timed out | Offer resend |
| `auth.otp_throttled` | 429 | Too many requests | `details.retry_after` |

**Only a `401` clears the session.** Every other failure leaves the shop
working from cache. This distinction matters: a server hiccup must not log a
merchant out mid-sale.

---

## Registration

| Code | Status | Meaning |
| --- | --- | --- |
| `registration.phone_taken` | 409 | Number already has an account |
| `registration.invoice_unpaid` | 402 | Signup fee not settled |
| `registration.invoice_consumed` | 409 | That invoice already created an account |
| `registration.state_unknown` | 422 | `state` is not a valid code |
| `quote.expired` | 410 | Fetch a fresh quote |

## Shops

See [multiple-shop.md](multiple-shop.md).

| Code | Status | Meaning |
| --- | --- | --- |
| `shop.not_a_member` | 403 | Login `shop_id` isn't one of this person's shops |
| `shop.not_found` | 404 | No such shop for this person, or it was closed |
| `shop.last_shop` | 409 | The owner's only shop can't be closed |
| `shop.payments_pending` | 409 | The shop still has a payment waiting |

---

## Payments

| Code | Status | Meaning | `details` |
| --- | --- | --- | --- |
| `payment.declined` | 402 | Rail refused | `reason`: `insufficient_funds`, `wallet_blocked`, `limit_exceeded` |
| `payment.rail_unavailable` | 422 | Merchant has no verified wallet on that rail | `available_rails` |
| `payment.wallet_invalid` | 422 | Number not valid for that rail | |
| `wallet.number_taken` | 409 | Payout number already belongs to another shop (`error.field` is `wallets.N.number`) | |
| `settings.rate_out_of_range` | 422 | Exchange rate outside the allowed range (`error.field: exchange_rate`) | `min`, `max` |
| `payment.provider_unavailable` | 502 | Wallet provider down | `retry_after` |
| `payment.timeout` | 504 | No answer in time | `charge_id` — **poll it, do not re-charge** |
| `payment.already_settled` | 409 | Cannot cancel a paid charge | |
| `payment.cart_changed` | 409 | Cart moved since the quote, or the amount no longer matches the sale total | `current_total`, or `current` (the ticket) |
| `payment.charge_pending` | 409 | A payment for this ticket or order is already waiting for the customer | `charge_id` |
| `payment.tender_too_low` | 422 | Cash given is less than the amount due | `due` |
| `payment.amount_mismatch` | 422 | Amount does not match the quote | |

### `payment.timeout` is the dangerous one

A timeout does **not** mean the charge failed — it means the answer was lost.
The client must poll
[`GET /payments/charges/{id}`](payments.md#get-paymentschargesid) using the
`charge_id` in `details`, never issue a fresh charge. Retrying with the same
idempotency key is also safe; retrying with a new one is how a shopper gets
billed twice.

---

## Cart

| Code | Status | Meaning | `details` |
| --- | --- | --- | --- |
| `cart.empty` | 422 | Nothing to pay for | |
| `cart.not_found` | 404 | No such ticket for this user | |
| `cart.line_not_found` | 404 | Line already removed | |
| `cart.quantity_invalid` | 422 | Zero or negative | |
| `cart.version_conflict` | 409 | Cart changed on another till | `current` |
| `cart.has_held_lines` | 409 | Unsynced offline lines exist | `held_count` — call `/cart/sync` |
| `product.out_of_stock` | 409 | Cannot add | `available` |

---

## Products & inventory

| Code | Status | Meaning | `details` |
| --- | --- | --- | --- |
| `product.not_found` | 404 | Unknown or deleted | |
| `product.barcode_unknown` | 404 | No product with that barcode | `barcode`, `normalised` |
| `product.barcode_taken` | 409 | Another product owns it | `existing_product_id` |
| `product.in_active_cart` | 409 | Cannot delete while on a ticket | `cart_ids` |
| `category.name_taken` | 409 | Already exists | `category` |
| `inventory.insufficient_quantity` | 409 | Not enough to move | `available` |

Replaying `POST /products` with a `client_uuid` that was already created is **not
an error**: it returns `200` with the existing product and the message `Product
already added`. The client adopts the returned record and deletes its queued row.
A `from` equal to `to` on a transfer is an ordinary `422 validation.failed` on `to`.

---

## Orders

| Code | Status | Meaning | `details` |
| --- | --- | --- | --- |
| `order.not_found` | 404 | | |
| `order.invalid_transition` | 409 | Not an allowed status change | `from`, `to`, `allowed` |
| `order.cannot_delete_complete` | 409 | Completed orders are history | |
| `order.already_paid` | 409 | Money was already received for this order | |

---

## Employees & shifts

| Code | Status | Meaning |
| --- | --- | --- |
| `employee.phone_taken` | 409 | Number already belongs to an EXELO user |
| `employee.permission_unknown` | 422 | Bad key in `permission_keys` |
| `employee.not_found` | 404 | |
| `shift.already_active` | 409 | Already clocked in; `details.shift` |
| `shift.already_ended` | 409 | |
| `shift.end_before_start` | 422 | |
| `shift.not_found` | 404 | Not a shift of this user or shop | |
| `shift.time_in_future` | 422 | Time more than 5 minutes ahead of the server | |

---

## Subscription & plan gating

| Code | Status | Meaning | `details` |
| --- | --- | --- | --- |
| `plan.feature_unavailable` | 403 | Not included in this plan | `feature`, `required_plan` |
| `subscription.already_on_plan` | 409 | | |
| `subscription.change_pending` | 409 | Earlier change still settling | `charge_id` |
| `subscription.plan_unavailable` | 422 | Not offered to this merchant | |
| `subscription.charge_closed` | 409 | Idempotency key belongs to a failed, expired or cancelled payment; use a new key | `charge_id`, `status` |
| `subscription.already_cancelled` | 409 | The plan is already set to end | |
| `subscription.nothing_to_cancel` | 409 | On the default plan, or the paid plan has already lapsed | |
| `charge.not_found` | 404 | No such charge for this shop | |
| `invoice.not_pending` | 409 | Only a pending payment can be confirmed or simulated | |
| `invoice.not_cash` | 409 | Cash confirmation on a non-cash payment | |

`plan.feature_unavailable` should route to the upgrade screen with
`details.required_plan` preselected, not show a raw error.

---

## Files

| Code | Status | `details` |
| --- | --- | --- |
| `file.too_large` | 413 | `max_bytes` |
| `file.type_unsupported` | 415 | `accepted` |
| `file.corrupt` | 422 | |
| `file.not_found` | 404 | |
| `file.in_use` | 409 | `attached_to` (`{ type: "product"\|"order"\|"merchant", id }`) |

---

## NFC

| Code | Status | Meaning |
| --- | --- | --- |
| `nfc.tag_already_registered` | 409 | Registered to this merchant |
| `nfc.tag_not_found` | 404 | Unknown, **or** owned by another merchant |
| `nfc.password_invalid` | 401 | `details.attempts_remaining` |
| `nfc.tag_revoked` | 403 | Blocked |
| `nfc.key_version_retired` | 409 | Tag needs re-writing with the current key |

`nfc.tag_not_found` covers another merchant's tag deliberately — a distinct
"belongs to someone else" response would let anyone probe which shop a tag
belongs to.

---

## Sync & idempotency

| Code | Status | Meaning | `details` |
| --- | --- | --- | --- |
| `idempotency.key_reused` | 409 | Same key, different body | `original_request_id` |
| `idempotency.key_missing` | 400 | Required on this endpoint | |
| `resource.version_conflict` | 409 | Stale `If-Match` | `current` |
| `sync.cursor_too_old` | 410 | Beyond retention — full resync | `full_resync_required: true` |
| `sync.mutation_unsupported` | 422 | Unknown mutation `type` | `supported_types` |

---

## Transport & platform

| Code | Status | Meaning | Client action |
| --- | --- | --- | --- |
| `rate_limited` | 429 | Too many requests | Back off per `Retry-After` |
| `maintenance` | 503 | Planned downtime | Show `details.until`, go offline-first |
| `app_version_unsupported` | 426 | Below `min_app_version` | Force upgrade |
| `server_error` | 500 | Unhandled fault | Retry with backoff; report `request_id` |

---

## Client handling rules

1. **`401` clears the session. Nothing else does.** A `500` or a timeout means
   fall back to cache, not log out.
2. **Timeouts are not failures.** For any endpoint with an idempotency key,
   retry with the *same* key. For payments, poll the charge.
3. **`409` is a question, not a dead end.** Version conflicts carry the current
   server state; show it and let the shopkeeper choose.
4. **Never parse `message`.** It is translated and reworded.
5. **Surface `request_id`** in any error screen with a "report a problem"
   action. It is the only thing that locates the failure in the server log.
6. **Retry with backoff on `5xx` and `429`.** Start at 1s, double, cap at 60s,
   add jitter. On a congested link a fleet of tills retrying in lockstep makes
   the outage worse.
