# Payment Flows

Every place money moves in EXELO, and how each one uses the three payment
gateways: **eDahab**, **WaafiPay (Zaad)** and **cash**.

This page is the map. Endpoint details are in the module docs
([Registration](registration.md), [Subscription](subscription.md),
[Payments](payments.md), [Cart](cart.md), [Orders](orders.md)); provider call
details are in [eDahab](edahab.md) and [WaafiPay](waafipay.md).

---

## At a glance

| # | Flow | Who pays | Who receives | eDahab | Zaad | Cash | v1 state |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | [Merchant registration](#1-merchant-registration) | New merchant | EXELO | ✅ | ✅ | ❌ | **Live** |
| 2 | [Wallet verification](#2-wallet-verification) | Merchant | EXELO | ✅ | ✅ | ❌ | **Live** |
| 3 | [Subscription](#3-subscription) | Merchant | EXELO | ✅ | ✅ | ✅ agent confirms | **Live** |
| 4 | [Request for payment](#4-request-for-payment) | Shop's customer | The shop | legacy | legacy | — | **Not in v1** (legacy only) |
| 5 | [Checkout](#5-checkout) | Shop's customer | The shop | ✅ | ✅ | ✅ instant | **Live**. Paying the shop its share of a wallet sale is **not built** |

Flows 1–3 are **EXELO's income**: the money stays in EXELO's accounts.
Flows 4–5 are **the shop's income**: the customer's wallet pays EXELO's account
first, and EXELO must then pay the shop (see
[Paying the shop its share](#paying-the-shop-its-share)).

---

## The three gateways

| | eDahab | WaafiPay (Zaad) | Cash |
| --- | --- | --- | --- |
| Customer numbers | `+25265…`, `+25266…`, `+25262…` | `+25263…` | — |
| Collect money | `IssueInvoice` → customer approves on the handset | `API_PREAUTHORIZE` → customer enters PIN, money **held** | Counted by a person |
| Check / finish | `CheckInvoiceStatus` (read-only) | `API_PREAUTHORIZE_COMMIT` (**takes** the held money) | Sale: nothing to check. Subscription: an EXELO agent confirms in admin |
| Cancel | Not available | `API_CANCEL` (legacy only) | — |
| Send money out | `agentPayment` (legacy only) | `API_CREDITACCOUNT` (legacy only) | — |
| Money lands in | EXELO eDahab agent (`EXELO_AGENT_CODE`) | EXELO WaafiPay merchant (`WAAFI_MERCHANT_UID`) | The person holding the cash |
| Billed in | SLSH | SLSH | USD (sales), SLSH (subscription) |
| Server code | `WalletGateway` (issue, status) | `WalletGateway` (issue, status) | `ChargeService`, `InvoicePaymentService::confirmCash()` |

The wallet is picked **from the number**: `PhoneNumber::carrier()` maps the prefix
to `edahab` or `zaad`, and a number that doesn't match the chosen `rail` is
refused with `422 payment.wallet_invalid`.

### One lifecycle for every wallet payment

Every payment, whatever the flow, is one row in `invoices`. The `type` column
says which flow it belongs to (`Registration`, `Verification`, `Subscription`,
`Sale`), and `rail` says which gateway.

```
            issue (WalletGateway::issue)
                     │
                     ▼
               ┌──────────┐   poll: InvoicePaymentService::refresh()
               │ Pending  │ ◄───────────── one provider call per poll
               └────┬─────┘
       ┌────────────┼──────────────┬──────────────┐
       ▼            ▼              ▼              ▼
     Paid        Failed        Expired        Cancelled
       │
       └─► InvoicePaid event
             ├─ ApplySubscriptionOnPaid  (type Subscription)
             └─ FinalizeSaleOnPaid       (type Sale → order created, cart cleared)
```

| Step | eDahab | Zaad | Cash |
| --- | --- | --- | --- |
| Issue | `IssueInvoice`; store `InvoiceId` | `API_PREAUTHORIZE`; store `referenceId` + `transactionId` | No provider call |
| Each poll | `CheckInvoiceStatus` | `API_PREAUTHORIZE_COMMIT` | Sale: already `Paid`. Subscription: waits for the agent; `Expired` after 72 h |
| Wallet invoice expiry | 10 min (`registration.invoice_ttl_seconds`), then `Expired` | same | — |
| Paid | `InvoiceStatus == Paid` | `errorCode 0` and `state approved` | Sale: at once. Subscription: agent clicks confirm |

Registration and verification don't use the `InvoicePaid` listeners: the next
step (create the merchant, complete verification) checks that the invoice is
`Paid` and marks it `consumed_at` so it can't be used twice.

---

## 1. Merchant registration

A new shop pays a signup fee from their own wallet before the account exists.

| | |
| --- | --- |
| Endpoints | `GET /registration/quote` → `POST /registration/invoices` → poll `GET /registration/invoices/{id}` → `POST /merchants` |
| Auth | None (no account yet). Rate-limited (`throttle:v1-registration`) |
| Amount | Base price + EXELO fee, set by an admin under **Payment Fees** (fallback `REGISTRATION_FEE` + `REGISTRATION_FEE_CHARGE`, 500 + 50 SLSH); quoted for 15 min |
| Gateways | **eDahab, Zaad**. Cash is not accepted (`IssueInvoiceRequest`: `rail` in `zaad,edahab`) |
| Invoice | `type = Registration`, `purpose = registration` |
| On paid | `POST /merchants` creates the merchant only if a `Paid`, unconsumed Registration invoice exists for that phone (`402 registration.invoice_unpaid` otherwise), then marks it consumed |
| Code | `V1\RegistrationController`, `RegistrationService`, `WalletGateway`, `InvoicePaymentService` |
| Docs | [registration.md](registration.md) steps 3–6 |

```
App                                   Server                          Provider
GET  /registration/quote          →   fee + quote_id
POST /registration/invoices       →   WalletGateway::issue ───────►  IssueInvoice / API_PREAUTHORIZE
     { rail, wallet_number,       ←   202 pending, invoice_id
       quote_id, purpose }
GET  /registration/invoices/{id}  →   refresh() ──────────────────►  CheckInvoiceStatus / COMMIT
     (every poll_after seconds)   ←   pending … paid
POST /merchants                   →   merchant created, invoice consumed
```

## 2. Wallet verification

A registered merchant pays a fee to confirm the payout wallet numbers on file.
Same payment as registration with a different `purpose`.

| | |
| --- | --- |
| Endpoints | `GET /registration/quote?purpose=verification` → `POST /registration/invoices` (`purpose: verification`) → poll → `POST /merchants/{id}/verification/complete` with `invoice_id` |
| Auth | Issue and poll: none. Complete: Bearer, **shop owner only** (`403 auth.merchant_only` for employees) |
| Amount | `VERIFICATION_FEE` + `VERIFICATION_FEE_CHARGE` (default 500 + 50 SLSH). **The real fee is still to be confirmed** ([registration.md](registration.md#implementation-notes)) |
| Gateways | **eDahab, Zaad**. No cash |
| Invoice | `type = Verification` |
| On paid | `verification/complete` checks the invoice is `Paid`, unconsumed and billed to the merchant's phone, marks it consumed, then `markPendingWalletsVerified()` turns every `pending` wallet `verified` |
| Why it matters | A wallet rail is only offered at checkout once its wallet is `verified` ([`/payments/methods`](payments.md#1-get-apiv1paymentsmethods--which-payment-methods-the-shop-accepts)) |
| Code | `RegistrationService::issueInvoice()`, `completeVerification()`, `MerchantProfileService` |

## 3. Subscription

The merchant pays for a plan upgrade or renewal (e.g. Silver → Gold).

| | |
| --- | --- |
| Endpoints | `GET /plans`, `GET /subscription` → `POST /subscription/change` `{ plan_id, rail, wallet_number, idempotency_key }` → poll `GET /payments/charges/{inv_…}` |
| Auth | Bearer |
| Amount | The plan's `price_slsh`, in SLSH, set by an admin under **Subscription Plans** |
| Gateways | **eDahab, Zaad, cash**. Card returns `422 payment.rail_unavailable` |
| Invoice | `type = Subscription`, id `inv_…` |
| On paid | `InvoicePaid` → `ApplySubscriptionOnPaid` → `SubscriptionService::applyPaidInvoice()` starts the plan (once) |
| Downgrades | No payment; scheduled for the end of the period |
| Code | `V1\SubscriptionController`, `SubscriptionService`, `ApplySubscriptionOnPaid` |
| Docs | [subscription.md](subscription.md) |

**Cash for a subscription** is not instant. The merchant pays an EXELO agent in
person; the invoice stays `Pending` with `next_action: "await_cash_confirmation"`
until staff confirm it in the admin panel (`Admin\InvoiceController::confirmCash`,
permission `edit-invoice`). It becomes `Expired` if nobody confirms within 72 hours
(`subscription.cash_ttl_hours`).

Only one subscription payment can be open at a time.

## 4. Request for payment

The shop bills a customer for an **amount**, without a basket: "request payment"
in the app. The customer approves on their wallet, and the shop is owed the money.

**There is no v1 endpoint for this yet.** It runs on the legacy API:

| Step | Legacy call | What it does |
| --- | --- | --- |
| 1 | `POST /api/merchant/transaction/process` | Works out the fee: `total_customer_charge`, `amount_sent_to_merchant` |
| 2 | `POST /api/merchant/invoice/route` | Picks eDahab or Zaad from the number prefix, then: **eDahab** `IssueInvoice` + `CheckInvoiceStatus` (loops up to 40 s); **Zaad** `API_PREAUTHORIZE` + `API_PREAUTHORIZE_COMMIT` (loops, then `API_CANCEL`) |
| 3 | `POST /api/merchant/invoice/payment` (eDahab) or `/zaad/payment` (Zaad) | **The app** asks the server to pay the shop its share: `agentPayment` or `API_CREDITACCOUNT` |
| 4 | `GET /api/order/getInvoiceDetailsForInvoice/{invoiceId}` | Receipt |

Problems with the legacy flow (details in the provider docs):

- The shop only gets paid if **the app** makes step 3. If the app closes after
  step 2, the customer has paid EXELO and the shop gets nothing.
- The payout routes accept `phone_number` in the body, and step 2 holds a PHP
  worker for up to 40 seconds with `sleep()`.
- The Zaad route commits even after a failed pre-authorise (the `=` instead of
  `===` bug, [waafipay.md gap 8](waafipay.md#gaps-and-implementation-plan)).
- A refused Zaad payout crashes with a 500 ([waafipay.md gap 9](waafipay.md#gaps-and-implementation-plan)).

**Proposed v1 shape** (to confirm before building): a new charge `purpose:
payment_request` on the existing [`POST /payments/charges`](payments.md#3-post-apiv1paymentscharges--start-a-payment),
with an `amount` and no `cart_id` / `order_id`. It reuses the quote, the fee rule,
the `Sale`-style invoice and polling. On `paid`, no order is created; the shop's
share is paid by the server (next section), and the receipt comes from the
planned [`GET /invoices/{id}/receipt`](orders.md#8-get-apiv1invoicesidreceipt--receipt-for-a-request-payment-invoice).

## 5. Checkout

A sale at the till: the register's ticket (cart), or a held order paid later.

| | |
| --- | --- |
| Endpoints | [`POST /cart/pay`](cart.md#post-cartpay) (the ticket), [`POST /orders/{id}/pay`](orders.md#5-post-apiv1ordersidpay--take-payment-for-a-pending-order) (a held order), or directly [`POST /payments/charges`](payments.md#3-post-apiv1paymentscharges--start-a-payment); then poll `GET /payments/charges/{chg_…}` |
| Auth | Bearer + `pos` permission. Idempotency key required |
| Amount | The ticket or order total in USD. The 2.85 % wallet fee is added for the customer on **Gold**, taken from the shop on **Silver**; no fee on cash |
| Gateways | **eDahab, Zaad** (only if that wallet is `verified` for the shop), **cash** (always) |
| Invoice | `type = Sale`, `purpose = pos_sale` or `order_settlement`, id `chg_…` |
| Wallet billing | Charged in **SLSH** at the shop's exchange rate (`customer_charge` cents × rate ÷ 100) |
| Cash | Recorded `Paid` at once in USD; `amount_tendered` below the total → `422 payment.tender_too_low` |
| On paid | `InvoicePaid` → `FinalizeSaleOnPaid` → one transaction creates or settles the order, takes the stock off the shelf and clears the ticket (once, however often it's polled) |
| Code | `CheckoutService`, `ChargeService`, `PaymentService`, `FinalizeSaleOnPaid` |
| Docs | [payments.md](payments.md), [cart.md](cart.md), [orders.md](orders.md) |

```
Cash:   POST /cart/pay { rail: cash }            → 200 paid, order + receipt
Wallet: POST /cart/pay { rail: zaad|edahab,
                         customer.wallet_number } → 202 pending
        GET  /payments/charges/{chg_…}  (repeat) → paid → order + receipt
```

Only one wallet payment per ticket or order can be waiting (`409
payment.charge_pending`).

---

## Paying the shop its share

For **checkout** and **request for payment**, a wallet customer pays **EXELO's**
eDahab agent or WaafiPay merchant account, not the shop. The shop's share
(`merchant_receives` from the quote) has to be sent on:

| Gateway | Provider call | Recipient |
| --- | --- | --- |
| eDahab | `agentPayment` | Shop's eDahab agent code (`merchants.merchant_code`) or eDahab number |
| Zaad | `API_CREDITACCOUNT` (`accountType: MERCHANT`) | Shop's Zaad merchant code (`merchants.other_merchant_code`) or Zaad number (then `CUSTOMER`) |
| Cash | — | The shop already holds the cash |

**v1 does not do this yet.** `ChargeService` creates the order when a wallet sale is
paid, but nothing sends the shop its share, and `WalletGateway` has no payout call.
The legacy app did it with a separate call after each sale (step 3 above).

Until it is built, **wallet sales taken through v1 stay in EXELO's accounts.**
Either keep the legacy payout in the app for now, or settle manually.

Proposed design (to confirm):

- On `InvoicePaid` for a `Sale` (and `payment_request`) on a wallet rail, queue a
  `SettleMerchantShare` job. Don't send it inside the poll request.
- The job creates a `payouts` row **first** (merchant, invoice, rail, amount in
  SLSH, our unique reference, status `pending`), then calls
  `WalletGateway::payout()` once.
- Success → `sent` with the provider reference. Refused → `failed`, shown in
  admin for a retry. Timeout → `unknown`, **never retried automatically**; an
  admin checks with the provider first.
- A unique index on `payouts.invoice_id` guarantees each sale is paid out at most
  once.
- Log each payout to `LogActivity`.

This needs a new `payouts` table (migration), and is the same `WalletGateway::payout()`
the planned [`POST /payments/payouts`](payments.md#7-post-apiv1paymentspayouts--send-money-to-a-phone-number)
would use.

---

## Known risks across all flows

| Risk | Where | Effect | Fix |
| --- | --- | --- | --- |
| Wallet sales are not paid on to the shop | Checkout (v1) | Shop's money stays with EXELO | [Paying the shop its share](#paying-the-shop-its-share) |
| eDahab customer pays **after** our 10-minute expiry | All wallet flows | Invoice is `Expired` but the money arrived; no plan, no order | Re-check `Expired` eDahab invoices once (e.g. a scheduled job) and flag late payments for admin |
| Zaad hold never released | All Zaad flows | Abandoned or expired payments keep the customer's money held until WaafiPay releases it | Build cancel ([waafipay.md gap 4](waafipay.md#gaps-and-implementation-plan)) |
| Zaad declines reported as a bad number | All Zaad flows | Wrong message to the user | [waafipay.md gap 2](waafipay.md#gaps-and-implementation-plan); needs captured decline responses |
| Provider keys still in git history | Repository | The hard-coded keys are gone from the code (2026-09-25) but remain in old commits | **Rotate the keys** with eDahab and WaafiPay |

Fixed on 2026-09-25: an eDahab declined prompt now returns `"prompt": "declined"`;
the legacy logger no longer stores API keys; hard-coded credentials are removed
from the code; every Zaad prompt says what it is for.

## Saving provider requests and responses

Every call to eDahab and WaafiPay should be kept, so a disputed or stuck payment
can be traced from our invoice to the provider's reference and back.

**Built (2026-09-25).** Every eDahab and WaafiPay call made by the v1 flows
(registration, verification, subscription, checkout) writes one row to
`api_logs`, **including calls that time out or can't connect**. The writer is
`App\Services\Payments\ProviderHttp::send()`; migration
`2026_09_25_000001_add_provider_columns_to_api_logs_table`.

| Column | Holds | Example |
| --- | --- | --- |
| `provider` | Which gateway | `edahab`, `waafi` |
| `operation` | Which call | `IssueInvoice`, `checkInvoiceStatus`, `API_PREAUTHORIZE`, `API_PREAUTHORIZE_COMMIT` |
| `invoice_id` | The payment it belongs to | `8812` |
| `our_reference` | The id we sent | eDahab `txn_1_1727265060000`, Zaad `referenceId` |
| `provider_reference` | The id the provider returned | `MP260925.1148.A17068`, Zaad `transactionId` |
| `provider_status` | The outcome in one line | `Unpaid · 7 User declined`, `Pending`, `approved`, `E10235 We are sorry…` |
| `url` | The provider URL, with eDahab's `?hash=` | |
| `payload` | The request body, **API keys removed** | |
| `status_code` | HTTP status; `null` when no answer came back | `200` |
| `response_body` | The provider's full answer | |
| `duration_ms` | How long the call took | `18450` |
| `error` | Why it failed when there was no usable answer | `cURL error 28: Operation timed out`, `HTTP 503` |

**Every call for one payment, oldest first:** `$invoice->apiLogs`. The first
call (issue) runs before the invoice row exists; `App\Observers\InvoiceObserver`
links it when the invoice is created, matching on `our_reference`.

```php
Invoice::where('public_id', 'inv_…')->first()->apiLogs;                    // one payment
ApiLog::provider('edahab')->where('provider_reference', 'MP260925.1148.A17068')->first(); // from a support ticket
ApiLog::whereNotNull('error')->latest()->get();                            // calls that failed
```

The legacy `BaseController::logApiResponse()` also writes to `api_logs`, now
without API keys, but doesn't fill the new columns.

Not built: an admin page listing an invoice's provider calls.

What **not** to save or show:

- API keys and eDahab's secret key: never (the secret is never in a request
  anyway, only the hash).
- eDahab's `agentPayment` `TransactionMesage` reveals EXELO's agent balance. It
  can stay in `api_logs` (admin only) but must not be copied anywhere a merchant
  can see it.
- Keep `api_logs` out of any merchant-facing API; it is for support staff only.

**Existing rows** written by the legacy logger already contain API keys. After
the keys are rotated those values are useless, but it's cleaner to blank them
with a one-off command (not a migration). Ask before running it.

## Suggested build order

| Phase | What | State |
| --- | --- | --- |
| 0 | Split `WalletGateway` into `EdahabProvider` / `WaafiProvider` behind `App\Services\Payments\Contracts\WalletProvider`; captured responses as test fixtures | ✅ 2026-09-25 |
| 1 | Searchable `api_logs` (above) | ✅ 2026-09-25 |
| 2 | Security (hard-coded credentials removed, legacy logger redacts keys) and live fixes (eDahab decline hint, `Unpaid` = pending, 90 s eDahab timeout; Zaad `approved` in any case, `errorCode` as text, prompt text per purpose; two legacy Zaad bugs) | ✅ 2026-09-25, except Zaad decline mapping (needs captured responses) |
| 3 | **Payouts:** `payout()` on both providers, `payouts` table, `SettleMerchantShare` for checkout, `POST /payments/payouts` | Next; new table, confirm first |
| 4 | **Zaad cancel**, then `POST /payments/charges/{id}/cancel` | Waiting on WaafiPay: `API_CANCEL` or `API_PREAUTHORIZE_CANCEL` |
| 5 | **Request for payment in v1:** `payment_request` charge purpose, invoice receipt | |
| 6 | **eDahab** `ReturnUrl`, hosted payment page, late-payment check | |

Still to do by hand: **rotate the eDahab and WaafiPay keys**; they remain in git history.

## Testing

- Local: `EXELO_SIMULATE_PAYMENTS=true` with `APP_ENV=local` enables
  `POST /registration/invoices/{id}/simulate-payment`, which settles a pending
  Registration, Verification or Subscription invoice without a phone.
- Cash checkout needs no provider and is the quickest end-to-end test of the
  order flow.
- Every provider call is in `api_logs`; start there when a payment misbehaves.
- Feature tests fake both providers with `Http::fake()`; never call the real APIs,
  as commit and payout calls move real money.
