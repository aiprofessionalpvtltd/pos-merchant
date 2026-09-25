# WaafiPay (Zaad) Provider Integration

How the server talks to **WaafiPay**, the gateway for **Zaad** (Telesom) wallets:
holding money in a customer's wallet (**pre-authorise**), taking it (**commit**),
releasing it (**cancel**), and sending money out to a wallet (**credit account**).

This is a **server-to-provider** reference. The app never calls WaafiPay directly;
it goes through [Payments](payments.md) (`rail: "zaad"`), [Registration](registration.md)
and [Subscription](subscription.md). The other wallet is documented in
[eDahab](edahab.md).

> **Status**
>
> | WaafiPay service | Used by | State |
> | --- | --- | --- |
> | `API_PREAUTHORIZE` | `WalletGateway::issue()` (charges, registration, subscription) | **Live** |
> | `API_PREAUTHORIZE_COMMIT` | `WalletGateway::status()` (called on every charge poll) | **Live** |
> | `API_CANCEL` | Legacy `API\PaymentController::cancelWaafiTransaction()` only | **Legacy only** |
> | `API_CREDITACCOUNT` | Legacy `API\PaymentController::makeMerchantPaymentToWaafi()` only | **Legacy only**. No v1 endpoint |
>
> See [Gaps and implementation plan](#gaps-and-implementation-plan).

---

## Configuration

| `.env` key | Config key | Meaning |
| --- | --- | --- |
| `WAAFI_MERCHANT_UID` | `exelo.providers.waafi.merchant_uid` | Merchant account, `M…` |
| `WAAFI_API_USER_ID` | `exelo.providers.waafi.api_user_id` | API user of that merchant |
| `WAAFI_API_KEY` | `exelo.providers.waafi.api_key` | `API-…AHX`. Sent in every body |
| `API_TIMEOUT` | `exelo.providers.timeout` | HTTP timeout in seconds (default 30) |

```dotenv
WAAFI_MERCHANT_UID=
WAAFI_API_USER_ID=
WAAFI_API_KEY=
```

**Endpoint:** every service is a `POST` to one URL. The service is chosen by
`serviceName` in the body.

```
POST https://api.waafipay.net/asm
Content-Type: application/json
```

> **Security: credentials are in the source code.** The legacy
> `API\PaymentController` has real WaafiPay credentials as `env()` fallbacks,
> and **two different merchant accounts**: `API_PREAUTHORIZE` falls back to one
> merchant, while `COMMIT`, `CANCEL` and `CREDITACCOUNT` fall back to another. If
> the `.env` keys are ever missing, the money is held on one account and the
> commit is sent from another, so it can never succeed. Remove every fallback
> (read `config('exelo.providers.waafi.*')` only) and **ask WaafiPay for new
> keys**, since the current ones are in git history. `config/services.php` has
> the same problem with an eDahab `API_KEY` fallback.
>
> The pre-authorise, commit and cancel calls for one payment **must** use the same
> merchant credentials.

---

## Request envelope

Every service uses the same outer shape. WaafiPay has **no request signature**:
the `apiKey` in the body is the only credential, so calls must only go over
HTTPS and the key must never be logged (`WalletGateway::redact()` removes it
from `api_logs`).

```json
{
  "schemaVersion": "1.0",
  "requestId": "12113456678",
  "timestamp": "2026-09-24 13:42:03.078",
  "channelName": "WEB",
  "serviceName": "API_PREAUTHORIZE",
  "sessionId": "876879976876",
  "serviceParams": {
    "merchantUid": "<WAAFI_MERCHANT_UID>",
    "apiUserId": "<WAAFI_API_USER_ID>",
    "apiKey": "<WAAFI_API_KEY>"
  }
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `schemaVersion` | string | yes | Always `"1.0"` |
| `requestId` | string | yes | Unique per call. `WalletGateway` uses `uniqid('', true)` |
| `timestamp` | string | yes | `Y-m-d H:i:s.v` in the collection. `WalletGateway` sends `Y-m-d` and the collection's commit example sends `"2025-09-06 Standard"`, and both are accepted, so WaafiPay does not seem to validate it. Send a real time anyway |
| `channelName` | string | yes | `WEB` |
| `serviceName` | string | yes | `API_PREAUTHORIZE`, `API_PREAUTHORIZE_COMMIT`, `API_CANCEL`, `API_CREDITACCOUNT` |
| `sessionId` | string | no | Sent by the legacy commit, cancel and credit calls; not by `WalletGateway` |
| `serviceParams.merchantUid` / `apiUserId` / `apiKey` | string | yes | From [Configuration](#configuration) |
| `serviceParams.*` | — | per service | See each service below |

## Response envelope

WaafiPay answers HTTP `200` for business failures too. **Decide on `errorCode`,
never on the HTTP status.**

```json
{
  "schemaVersion": "1.0",
  "timestamp": "2026-09-24 13:42:05.311",
  "responseId": "…",
  "responseCode": "2001",
  "errorCode": "0",
  "responseMsg": "RCS_SUCCESS",
  "params": {
    "state": "approved",
    "referenceId": "324922",
    "transactionId": "42750126",
    "txAmount": "500"
  }
}
```

**Response `200`: failure** (captured 2026-09-24, `API_CREDITACCOUNT`)

```json
{
  "schemaVersion": "1.0",
  "timestamp": "2026-09-24 15:29:28.658",
  "responseId": "78973012",
  "responseCode": "5222",
  "errorCode": "E10235",
  "responseMsg": "We are sorry this Operation is not Allowed at this moment"
}
```

A failure has **no `params` at all**. Read `params` only after checking
`errorCode`.

| Field | Meaning |
| --- | --- |
| `responseId` | Echoes our `requestId`. Use it to find the call in `api_logs` |
| `responseCode` | WaafiPay's result code, a string (`2001` success in their examples, `5222` here) |
| `errorCode` | `"0"` = success. A failure is a **string code** like `"E10235"`, not a number. Compare as strings: `(string) $body['errorCode'] === '0'`. The code's loose `!= 0` / `== 0` gives the right answer on PHP 8, but only because PHP 8 changed how strings compare to numbers |
| `responseMsg` | Reason text, e.g. `RCS_SUCCESS` or a decline reason. Log it; map it before showing a user |
| `params.state` | Transaction state, e.g. `approved`. **Compare case-insensitively** (see gap 3) |
| `params.referenceId` | Our `referenceId`, echoed back |
| `params.transactionId` | WaafiPay's transaction id. Needed to commit or cancel |
| `params.txAmount` | Amount WaafiPay processed |

This shape is taken from the fields the code reads (`errorCode`, `responseMsg`,
`params.state`, `params.referenceId`, `params.transactionId`, `params.txAmount`).
**No real response has been captured for this doc yet.** Copy one from `api_logs`
and replace the example (see [Responses still to capture](#responses-still-to-capture)).

---

## 1. `API_PREAUTHORIZE` — hold money in the customer's wallet

Sends a PIN prompt (USSD push) to the customer's Zaad phone. When the customer
enters their PIN, the amount is **held**, not yet taken. It is only taken by
[`API_PREAUTHORIZE_COMMIT`](#2-api_preauthorize_commit--take-the-held-money), and
released by [`API_CANCEL`](#3-api_cancel--release-a-hold).

**Request**

```json
{
  "schemaVersion": "1.0",
  "requestId": "66f2c1a8e4b1c3.12345678",
  "timestamp": "2026-09-24 13:42:03.078",
  "channelName": "WEB",
  "serviceName": "API_PREAUTHORIZE",
  "serviceParams": {
    "merchantUid": "<WAAFI_MERCHANT_UID>",
    "apiUserId": "<WAAFI_API_USER_ID>",
    "apiKey": "<WAAFI_API_KEY>",
    "paymentMethod": "MWALLET_ACCOUNT",
    "payerInfo": { "accountNo": "252634110101" },
    "transactionInfo": {
      "referenceId": "324922",
      "invoiceId": "781402",
      "amount": 301920,
      "currency": "SLSH",
      "description": "EXELO sale",
      "paymentBrand": "WAAFI"
    }
  }
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `paymentMethod` | string | yes | `MWALLET_ACCOUNT` |
| `payerInfo.accountNo` | string | yes | Customer number **with** country code, no `+`: `252634110101`. From E.164: `substr($e164, 1)`. Zaad numbers start `63` |
| `transactionInfo.referenceId` | string | yes | **Our** id for this payment. Stored as `invoices.invoice_id` and needed for commit and cancel |
| `transactionInfo.invoiceId` | string | yes | Our invoice number. `WalletGateway` sends a random 6-digit number and does not store it |
| `transactionInfo.amount` | number | yes | Whole shillings for `SLSH` |
| `transactionInfo.currency` | string | yes | `SLSH` (what EXELO bills in); `USD` if the merchant account allows it |
| `transactionInfo.description` | string | yes | Shown to the customer. `WalletGateway` sends `EXELO signup` for **every** payment, sales included (gap 6) |
| `transactionInfo.paymentBrand` | string | no | `WAAFI` |

**Response**

| Outcome | How to detect | Map to |
| --- | --- | --- |
| Held | `errorCode == 0` | Store `params.referenceId` as `invoices.invoice_id` and `params.transactionId` as `invoices.transaction_id`, status `Pending`, return `202` |
| Declined, bad number, low balance, timeout at the handset | `errorCode != 0` | Today: **all** mapped to `422 payment.wallet_invalid` with `responseMsg` (gap 2) |
| HTTP error / timeout | exception or non-2xx | `502 payment.provider_unavailable`. The customer may already have been prompted |

Like eDahab's `IssueInvoice`, the call appears to wait for the customer to enter
their PIN before answering. Confirm how long it waits against `API_TIMEOUT`.

## 2. `API_PREAUTHORIZE_COMMIT` — take the held money

Turns a hold into a payment. **This is what actually takes the money.**

**Request** (from the collection)

```json
{
  "schemaVersion": "1.0",
  "requestId": "6477777189303",
  "timestamp": "2026-09-24 13:43:10.000",
  "channelName": "WEB",
  "serviceName": "API_PREAUTHORIZE_COMMIT",
  "sessionId": "876879976876",
  "serviceParams": {
    "merchantUid": "<WAAFI_MERCHANT_UID>",
    "apiUserId": "<WAAFI_API_USER_ID>",
    "apiKey": "<WAAFI_API_KEY>",
    "transactionId": "42750126",
    "referenceId": "324922",
    "description": "Commit transaction"
  }
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `transactionId` | string | yes | `params.transactionId` from `API_PREAUTHORIZE` (`invoices.transaction_id`) |
| `referenceId` | string | yes | Our `referenceId` from `API_PREAUTHORIZE` (`invoices.invoice_id`) |
| `description` | string | yes | Free text |

**Response**

| Outcome | How to detect | Map to |
| --- | --- | --- |
| Paid | `errorCode == 0` and `params.state == "approved"` | Invoice `Paid`, keep `params.transactionId` as the provider reference |
| Anything else | — | Today: stays `Pending`, polled again until the charge expires |

**How it is used as built.** There is no separate "status" call for Zaad, so
`WalletGateway::status()` sends a **commit on every poll** of
[`GET /payments/charges/{id}`](payments.md#4-get-apiv1paymentschargescharge_id--check-a-payment).
The first commit after the customer approves takes the money; the invoice is then
`Paid` and polling stops. Things to confirm with WaafiPay, because the poll
depends on them:

- A commit sent **before** the customer has approved must fail harmlessly and
  must not cancel the hold.
- A commit sent **twice** (two tills polling, or a retry after a timeout) must not
  take the money twice. Our side is protected because the invoice settles once,
  but WaafiPay's behaviour is unknown.
- Every failure is treated as "still pending", so a real decline is only noticed
  when the charge expires (gap 2).

## 3. `API_CANCEL` — release a hold

Gives the held money back to the customer: the shopkeeper backed out, or the
payment was never committed. **Not in `WalletGateway`**, so a v1 charge that is
abandoned keeps the customer's money held until WaafiPay releases it itself.

**Request** (as the legacy code sends it)

```json
{
  "schemaVersion": "1.0",
  "requestId": "481920",
  "timestamp": "2026-09-24T13:45:00+00:00",
  "channelName": "WEB",
  "serviceName": "API_CANCEL",
  "sessionId": "550231",
  "serviceParams": {
    "merchantUid": "<WAAFI_MERCHANT_UID>",
    "apiUserId": "<WAAFI_API_USER_ID>",
    "apiKey": "<WAAFI_API_KEY>",
    "transactionInfo": {
      "referenceId": "324922",
      "invoiceId": "781402",
      "amount": 301920,
      "currency": "SLSH",
      "description": "Cancel transaction"
    }
  }
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `transactionInfo.referenceId` | string | yes | Our `referenceId` from `API_PREAUTHORIZE` |
| `transactionInfo.invoiceId` | string | yes | The `invoiceId` sent on `API_PREAUTHORIZE`. **`WalletGateway` does not store it today**, so it would have to be kept before cancel can be built |
| `transactionInfo.amount` / `currency` | — | yes | As held |
| `transactionInfo.description` | string | yes | Free text |

`errorCode == 0` means released; mark the invoice `Cancelled`.

> The legacy code sends `API_CANCEL` without WaafiPay's `transactionId`.
> WaafiPay's published API also has `API_PREAUTHORIZE_CANCEL`, which takes
> `transactionId` in `serviceParams` like the commit. Confirm with WaafiPay which
> service releases a pre-authorisation before building this; this doc has no
> captured cancel response.

## 4. `API_CREDITACCOUNT` — send money to a wallet (payout)

Moves money **out** of the EXELO WaafiPay merchant account into a Zaad account.
Used to pay a merchant their share, or to pay a supplier. **This spends real
money: it must only run behind authentication, the `pos` permission and a PIN
confirmation** (see [payouts](payments.md#7-post-apiv1paymentspayouts--send-money-to-a-phone-number)).

**Request** (from the collection)

```json
{
  "schemaVersion": "1.0",
  "requestId": "12113456678",
  "timestamp": "2026-09-24 13:50:03.078",
  "channelName": "WEB",
  "serviceName": "API_CREDITACCOUNT",
  "serviceParams": {
    "merchantUid": "<WAAFI_MERCHANT_UID>",
    "apiUserId": "<WAAFI_API_USER_ID>",
    "apiKey": "<WAAFI_API_KEY>",
    "paymentMethod": "MWALLET_ACCOUNT",
    "payerInfo": {
      "accountType": "MERCHANT",
      "accountNo": "467188"
    },
    "transactionInfo": {
      "referenceId": 1727185803,
      "invoiceId": 1727185804,
      "amount": "500",
      "currency": "SLSH",
      "description": "send to merchant"
    }
  }
}
```

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `paymentMethod` | string | yes | `MWALLET_ACCOUNT` |
| `payerInfo.accountType` | string | yes | Who **receives** the money (despite the name `payerInfo`). `MERCHANT`: a Zaad merchant code (6 digits, e.g. `467188`). `CUSTOMER`: a personal Zaad number, `252…`. The legacy code always sends `MERCHANT` |
| `payerInfo.accountNo` | string | yes | The merchant code or number, matching `accountType`. Legacy sends `merchants.other_merchant_code`, or the Zaad number when no code is set, **still with `MERCHANT`** (gap 7) |
| `transactionInfo.referenceId` | string | yes | **Our** unique id for this payout. Make it deterministic per payout (e.g. `pay_<ulid>`) and store it **before** calling, so a retry after a timeout can be matched rather than paid twice. The legacy code sends `transactionId` instead and no `referenceId` |
| `transactionInfo.invoiceId` | string | yes | Our invoice number |
| `transactionInfo.amount` | string / number | yes | Sent as a string (`"500"`) in the collection. Whole shillings |
| `transactionInfo.currency` | string | yes | `SLSH` |
| `transactionInfo.description` | string | yes | Free text |

**Response**

| Outcome | How to detect | Map to |
| --- | --- | --- |
| Sent | `errorCode == 0` | Record the payout as `sent`, keep `params.transactionId` as `provider_ref`, `params.txAmount` as the amount sent |
| Refused | `errorCode != 0` | `402 payment.declined`. Log `responseMsg`; show a generic reason |
| HTTP error / timeout | exception or non-2xx | `502 payment.provider_unavailable`. **Do not retry automatically**: the money may have left. Mark the payout `unknown` and reconcile |

**Captured attempt: refused** (2026-09-24)

A test payout of 500 SLSH to a personal Zaad number came back with
`errorCode: "E10235"`, `responseCode: "5222"`, *"We are sorry this Operation is
not Allowed at this moment"* (full response under
[Response envelope](#response-envelope)). The request had three problems. Any of
them may cause this error:

1. **Mixed credentials.** `merchantUid` and `apiUserId` belonged to one merchant
   account and `apiKey` to the other (the two accounts described under
   [Configuration](#configuration)). All three must come from the same account.
2. **No `payerInfo.accountType`.** Send `CUSTOMER` for a `252…` personal number,
   `MERCHANT` for a merchant code.
3. **The merchant account may not be allowed to pay out.** `API_CREDITACCOUNT`
   (disbursement) is often a separate permission on the WaafiPay account, and
   *"not allowed"* is the wording for a service that isn't enabled. The account
   also needs enough balance.

The request also carried `billTo`, `shipTo` and `items`. Those belong to
WaafiPay's hosted-checkout (purchase) requests; the payout ignores them, so
leave them out.

Retry with one matching set of credentials and `accountType` set. If it still
answers `E10235`, ask WaafiPay to enable `API_CREDITACCOUNT` for that API user.

The legacy `makeMerchantPaymentToWaafi()` wraps the call in a 5-attempt retry
loop, but every branch returns on the first answer, so it never actually retries.
Keep it that way when moving to `WalletGateway`: **one call, no automatic
retry** for money going out.

---

## Sequence: sale on Zaad (as built)

```
App                     EXELO server                               WaafiPay
 │ POST /payments/charges │                                           │
 │  rail: zaad ─────────► │ API_PREAUTHORIZE ───────────────────────► │ PIN prompt
 │                        │                                           │ ──► customer enters PIN
 │                        │ ◄──────── errorCode 0, transactionId      │ (money held)
 │ ◄── 202 pending ────── │ invoices row, status Pending              │
 │ GET /payments/charges/{id}                                         │
 │ ─────────────────────► │ API_PREAUTHORIZE_COMMIT ────────────────► │
 │ ◄── pending / paid ─── │ ◄──────── errorCode 0, state approved     │ (money taken)
 │                        │ on Paid: InvoicePaid → order created, cart cleared
```

## Postman: runtime variables

The collection body uses `{{referenceId}}` and `{{invoiceId}}` **without quotes**,
so they must be numbers. Set them in the request's **Scripts → Pre-request** tab,
and keep the credentials in environment variables (`waafiMerchantUid`,
`waafiApiUserId`, `waafiApiKey`, type *secret*) rather than in the body:

```javascript
// Fresh ids and timestamp for each run
const now = Date.now();
pm.environment.set('requestId', String(now));
pm.environment.set('referenceId', Math.floor(now / 1000));
pm.environment.set('invoiceId', Math.floor(now / 1000) + 1);
pm.environment.set('timestamp', new Date().toISOString().replace('T', ' ').replace('Z', ''));
```

```json
"requestId": "{{requestId}}",
"timestamp": "{{timestamp}}",
"serviceParams": {
  "merchantUid": "{{waafiMerchantUid}}",
  "apiUserId": "{{waafiApiUserId}}",
  "apiKey": "{{waafiApiKey}}",
  …
}
```

Unlike eDahab there is no hash, so Postman variables can be used anywhere in the
body. For a commit, copy `transactionId` and `referenceId` from the
`API_PREAUTHORIZE` response (or set them in that request's **Post-response**
script with `pm.environment.set('waafiTransactionId', pm.response.json().params.transactionId)`).

---

## Gaps and implementation plan

What is missing or wrong compared with the collection, and the smallest change
for each. ✅ = built (2026-09-25, Phases 0–2 of the plan in
[payment-flows.md](payment-flows.md#suggested-build-order)); the rest is not built yet.
Gap 2 waits for captured WaafiPay decline responses: without them, sorting a
decline from a bad number would be guesswork.

| # | Gap | Change | Files |
| --- | --- | --- | --- |
| 1 ✅ | Real WaafiPay credentials (two merchant accounts) are hard-coded as `env()` fallbacks in the legacy controller; eDahab's key is in `config/services.php` | Remove the fallbacks, use `config('exelo.providers.waafi.*')`; add the keys to `.env.example`; **rotate the keys** with WaafiPay and eDahab | `API\PaymentController`, `config/services.php`, `.env.example` |
| 2 | Every `API_PREAUTHORIZE` failure becomes `422 payment.wallet_invalid`, even a customer decline or low balance; every commit failure means "still pending" | Map `responseMsg` / `errorCode` to `payment.declined` (with `reason`) vs `payment.wallet_invalid`; mark the invoice `Failed` on a definite decline. Needs captured failure responses first | `app/Services/WalletGateway.php` |
| 3 ✅ | `zaadStatus()` checks `params.state === 'approved'` (exact, lower-case). WaafiPay's published examples use `APPROVED` | Compare with `strtolower()`; check real values in `api_logs` | `app/Services/WalletGateway.php` |
| 4 | No cancel in `WalletGateway`, so [`POST /payments/charges/{id}/cancel`](payments.md#6-post-apiv1paymentschargescharge_idcancel--cancel-a-pending-payment) can't release a Zaad hold | Store the `invoiceId` sent on pre-authorise (e.g. in `invoices.meta`); add `WalletGateway::cancel(Invoice $invoice)` once WaafiPay confirms `API_CANCEL` vs `API_PREAUTHORIZE_CANCEL` | `app/Services/WalletGateway.php`, `ChargeService` |
| 5 | `API_CREDITACCOUNT` exists only in the legacy controller | Add `WalletGateway::payout('zaad', …)` next to the eDahab payout ([edahab.md gap 2](edahab.md#gaps-and-implementation-plan)), used by the same v1 payout endpoint | `app/Services/WalletGateway.php`, `PayoutService` |
| 6 ✅ | Every Zaad prompt says `EXELO signup`, including sales and subscriptions | Pass a description per purpose (`EXELO sale`, `EXELO subscription`, …) into `issue()` | `app/Services/WalletGateway.php` and its callers |
| 7 | Legacy payout sends `accountType: "MERCHANT"` even when falling back to a personal number | Send `CUSTOMER` with a `252…` number, `MERCHANT` only with a merchant code | New payout code (don't patch the legacy one) |
| 8 ✅ | Legacy `connectToWaafiAPI()` checks `if ($waafiResponse['success'] = false)`, an **assignment**, so it never stops and commits even after a failed pre-authorise | Fix to `=== false`, or retire the route once the app uses v1 | `API\PaymentController` line 134 |

| 9 ✅ | Legacy `makeMerchantPaymentToWaafi()` reads `$responseData['params']` **before** checking `errorCode`. A failure has no `params` (see the captured `E10235` response), so a refused payout crashes with a 500 instead of returning the error | Read `params` only when `errorCode` is `"0"`; the new payout code must do the same | `API\PaymentController` line 1084 |
| 10 ✅ | `errorCode` is compared loosely with `0`, but failures are strings like `"E10235"` | Compare `(string) $errorCode === '0'` | `app/Services/WalletGateway.php` |

Suggested order: **1 first** (credentials in source), then 3, 2 and 10
(correctness of what is live), then 6, then 4 and 5 together with the eDahab
payout. 8 and 9 are small fixes if the legacy routes are still called.

## Responses still to capture

Copy these from `api_logs` (`url = https://api.waafipay.net/asm`) or a Postman run,
and paste them into this doc:

- `API_PREAUTHORIZE`: approved, customer declined / wrong PIN, low balance, no answer
- `API_PREAUTHORIZE_COMMIT`: after approval, **before** approval, and a second
  commit of the same transaction
- `API_CANCEL`: released, and cancelling an already committed payment
- `API_CREDITACCOUNT`: sent, and refused for low balance (refused with `E10235`
  is captured)

## Open questions for WaafiPay

- Which service releases a pre-authorisation: `API_CANCEL` or `API_PREAUTHORIZE_CANCEL`?
- Is committing twice, or committing before approval, safe?
- How long is a hold kept before WaafiPay releases it on its own?
- How long does `API_PREAUTHORIZE` wait for the customer's PIN?
- The list of `errorCode` / `responseMsg` values for declines, so they can be
  mapped to `payment.declined` reasons.
- Is `referenceId` enforced unique on `API_CREDITACCOUNT`, so a payout retry is safe?
- Is `API_CREDITACCOUNT` enabled for our API user? What does `E10235` / `5222`
  mean exactly?
- Is there a callback (needed before
  [webhooks](payments.md#9-post-apiv1webhookspaymentsprovider--provider-callbacks)
  can include Zaad)?

---

## Testing locally

- `EXELO_SIMULATE_PAYMENTS=true` with `APP_ENV=local` skips the handset for
  registration and subscription invoices (see [payments.md](payments.md#postman--curl-quick-start)).
- Every `WalletGateway` call is recorded in `api_logs` with `apiKey` removed.
- Tests must fake the provider with `Http::fake(['api.waafipay.net/*' => …])`;
  never hit the real API, since `API_PREAUTHORIZE_COMMIT` and `API_CREDITACCOUNT`
  move real money.
