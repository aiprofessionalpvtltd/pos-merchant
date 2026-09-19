# Legacy → v1 Route Mapping

Every endpoint the Flutter app calls today, and what replaces it.

**74 legacy paths → 63 v1 endpoints.** The reduction comes almost entirely from
collapsing duplicated flows: merchant vs employee auth, Gold vs Silver payments,
four transfer endpoints, five product-stat endpoints.

Legacy routes stay alive as aliases until telemetry shows no traffic on them
(Phase 5 of the [rollout](../unified-api-plan.md#19-phased-rollout)).

Base URL today: `https://exelo.aiprofessionals.co`. All v1 paths are relative to
`/api/v1`.

---

## Auth & session

| Legacy | v1 | Note |
| --- | --- | --- |
| `POST /api/login` | `POST /auth/lookup` | |
| `POST /api/login/verifyUser` | `POST /auth/pin/login` | Merged with the employee route |
| `POST /api/employee/verifyEmployee` | `POST /auth/pin/login` | |
| `POST /api/employee/getMerchantDetail` | `POST /auth/lookup` | |
| `POST /api/merchants/store-pin` | `POST /auth/pin` | Merged with the employee route |
| `POST /api/employee/store-pin` | `POST /auth/pin` | |
| `POST /api/merchants/change-pin` | `PATCH /auth/pin` | |
| `POST /api/login/verifyUserPin` | `POST /auth/pin/verify` | Now returns a confirmation token |
| `POST /api/user/forgot-password` | `POST /auth/pin/reset/request` | No longer echoes the OTP |
| `POST /api/user/verify-otp-reset-password` | `POST /auth/pin/reset/verify` | |
| `POST /api/user/reset-password` | `POST /auth/pin/reset` | |
| `GET /api/login/userinfo` | `GET /auth/session` | Now includes plan + permissions |
| `POST /api/logout` | `POST /auth/logout` | |

---

## Registration

| Legacy | v1 | Note |
| --- | --- | --- |
| `POST /api/login/checkInvoice` | `POST /registration/phone/check` | Merged |
| `POST /api/merchants/checkForDuplicatePhoneNumber` | `POST /registration/phone/check` | |
| `POST /api/merchant/transaction/process` | `GET /registration/quote` | Registration use only |
| `POST /api/merchant/invoice/issue` | `POST /registration/invoices` | Registration / verification `type` |
| `POST /api/zaad/issue` | `POST /registration/invoices` | `rail: "zaad"` |
| `POST /api/zaad/commit` | — | Server-side now; client only polls |
| `POST /api/merchant/invoice/status` | `GET /registration/invoices/{id}` | POST → GET |
| `POST /api/merchants` | `POST /merchants` | `state` + `city` first-class |
| `POST /api/merchants/verificationComplete` | `POST /merchants/{id}/verification/complete` | |
| *(hardcoded in client)* | `GET /geo/states` | The five states move server-side |

---

## Subscription

| Legacy | v1 | Note |
| --- | --- | --- |
| `GET /api/merchants/subscriptions/current` | `GET /subscription` | No longer needed at splash |
| `POST /api/merchants/subscriptions` | `POST /subscription/change` | |
| `GET /api/merchants/subscriptions/1/cancel` | `POST /subscription/cancel` | Was commented out; was a GET that mutated |
| — | `GET /plans` | New — plan catalogue |

---

## Payments

| Legacy | v1 | Note |
| --- | --- | --- |
| `POST /api/merchant/transaction/process` | `POST /payments/quote` | POS / subscription uses |
| `GET /api/merchants/getPhoneNumbersStatus` | `GET /payments/methods` | |
| `POST /api/merchant/invoice/issue` | `POST /payments/charges` | POS `type` |
| `POST /api/zaad/issue` | `POST /payments/charges` | `rail: "zaad"` |
| `POST /api/zaad/commit` | `POST /payments/charges/{id}/confirm` | Rarely needed now |
| `POST /api/merchant/invoice/payment` | `POST /payments/charges` | |
| `POST /api/merchant/invoice/zaad/payment` | `POST /payments/charges` | Differed only by path segment |
| `POST /api/merchant/invoice/status` | `GET /payments/charges/{id}` | |
| `POST /api/merchant/make-payment` | `POST /payments/payouts` | **Was unauthenticated** — now requires a token |
| CF `generateClientToken` | `POST /payments/card/session` | Leaves Firebase |
| CF `processPayment` | `POST /payments/charges` | `rail: "card"` |
| — | `POST /webhooks/payments/{provider}` | New — reduces polling |

---

## Cart & checkout

| Legacy | v1 | Note |
| --- | --- | --- |
| `GET /api/cart/cart-valid` | `GET /cart` | Merged |
| `GET /api/cart/checkout?cart_type=` | `GET /cart` | |
| `POST /api/cart/add` | `POST /cart/items` | Now idempotent |
| `POST /api/cart/update-cart-items` | `PATCH /cart/items/{product_id}` | |
| `DELETE /api/cart/delete-cart-items` | `DELETE /cart/items/{product_id}` | Id moves from body to path |
| `POST /api/cart/transactionByCash` | `POST /cart/pay` | `rail: "cash"` |
| `POST /api/cart/placeOrder` | `POST /cart/pay` | Merged — pay, order and clear are atomic |
| `POST /api/cart/placePendingOrder` | `POST /cart/hold` | Signature by file id, not base64 |
| — | `DELETE /cart` | New — cancel sale |
| — | `POST /cart/sync` | **New — fixes duplicate held lines** |

---

## Orders

| Legacy | v1 | Note |
| --- | --- | --- |
| `GET /api/order/allByStatus?order_status=Pending` | `GET /orders?status=pending` | |
| `GET /api/order/allByStatus?order_status=Complete` | `GET /orders?status=complete` | |
| `POST /api/cart/updateOrderStatusToComplete` | `PATCH /orders/{id}/status` | |
| `POST /api/cart/updateOrderStatusToPending` | `PATCH /orders/{id}/status` | |
| `POST /api/cart/paidOrder` | `POST /orders/{id}/pay` | |
| `DELETE /api/order/delete/{id}` | `DELETE /orders/{id}` | |
| `GET /api/order/getOrderDetailsForInvoice/{id}` | `GET /orders/{id}/receipt` | Adds `format=pdf` |
| `GET /api/order/getInvoiceDetailsForInvoice/{id}` | `GET /invoices/{id}/receipt` | |
| — | `GET /orders/{id}` | New — order detail without the receipt wrapper |
| — | `POST /orders` | New — replay an offline order |

---

## Inventory & catalogue

| Legacy | v1 | Note |
| --- | --- | --- |
| `GET /api/products/merchant` | `GET /products` | Adds `updated_since` + tombstones |
| `GET /api/inventory/products/shop` | `GET /products?type=shop` | |
| `GET /api/inventory/products/stock` | `GET /products?type=stock` | |
| `GET /api/inventory/products/{cat}/{type}` | `GET /products?category_id=&type=` | |
| `GET /api/products/barcode/{code}/{type}` | `GET /products/lookup?barcode=&type=` | |
| `POST /api/products` | `POST /products` | JSON + `client_uuid`; image by file id |
| `POST /api/products/{id}` | `PATCH /products/{id}` | Was a POST doing an update |
| `DELETE /api/products/{id}` | `DELETE /products/{id}` | Now a soft delete |
| `GET /api/categories/merchant` | `GET /categories` | |
| `POST /api/categories/search` | `GET /categories?q=` | Was a POST doing a search |
| `POST /api/categories/store` | `POST /categories` | |
| `POST /api/inventory/transfer/shop-to-stock` | `POST /inventory/transfers` | |
| `POST /api/inventory/transfer/stock-to-shop` | `POST /inventory/transfers` | |
| `POST /api/inventory/transfer/shop-to-transportation` | `POST /inventory/transfers` | |
| `POST /api/inventory/transfer/stock-to-transportation` | `POST /inventory/transfers` | Four → one |
| `POST /api/inventory/updateInventory` | `PATCH /inventory/{id}/quantities` | Now requires a reason |
| `GET /api/getProductsByAlarmLimit` | `GET /inventory/alerts?type=alarm` | |
| `GET /api/getProductsByStockLimit` | `GET /inventory/alerts?type=restock` | |
| `GET ${baseUrl}/public/...` | `GET /files/{id}` | Opaque ids, signed URLs, thumbnails |
| — | `POST /files` | New — decoupled uploads |
| — | `GET /products/{id}` | New |

---

## Employees & shifts

| Legacy | v1 | Note |
| --- | --- | --- |
| `GET /api/employee` | `GET /employees` | |
| `POST /api/employee` | `POST /employees` | Permission **keys**, not ids |
| `POST /api/employee/{id}` | `PATCH /employees/{id}` | |
| `DELETE /api/employee/{id}` | `DELETE /employees/{id}` | |
| `GET /api/employee/getPOSPermission` | `GET /permissions` | |
| `GET /api/employee/getEmployeeSaleCombine` | `GET /employees/summary` | |
| `GET /api/employee/getEmployeeSale/{id}` | `GET /employees/{id}` | |
| `GET /api/user/getShiftData` | `GET /shifts` | |
| `POST /api/user/shift` *(with `start_time`)* | `POST /shifts/start` | Split — was ambiguous |
| `POST /api/user/shift` *(with `end_time`)* | `POST /shifts/{id}/end` | |
| `PUT /api/user/shift/{id}` | `PATCH /shifts/{id}` | Now flags the edit |

---

## Dashboard & reports

| Legacy | v1 | Note |
| --- | --- | --- |
| `GET /api/getProductStatistics` | `GET /dashboard` | Adds `weeks` for chart paging |
| `GET /api/getTransactionReport` | `GET /reports/sales` | |
| `GET /api/getInventoryReport` | `GET /reports/inventory` | |
| `GET /api/getSoldProducts` | `GET /reports/products?metric=sold` | |
| `GET /api/getNewShopProductsListing` | `GET /reports/products?metric=new_shop` | |
| `GET /api/getTotalProductsInShop` | `GET /reports/products?metric=in_shop` | |
| `GET /api/getNewStockProductsListing` | `GET /reports/products?metric=new_stock` | |
| `GET /api/getTotalProductsInStock` | `GET /reports/products?metric=in_stock` | Five → one |
| `GET /api/getAllProductsWithCategories` | `GET /reports/catalogue` | Grouped by category |

---

## Merchant profile

| Legacy | v1 | Note |
| --- | --- | --- |
| `PUT /api/update-merchants` | `PATCH /merchant` | |
| — | `GET /merchant` | New |
| — | `GET`/`PATCH /merchant/wallets` | New |
| — | `GET`/`PATCH /merchant/settings` | New — VAT and exchange rate leave the client |

---

## Firebase

| Legacy | v1 | Note |
| --- | --- | --- |
| Firestore `config/encryption` | `GET /nfc/keys/current` | Was **one global key for all merchants** |
| Firestore `nfc_tags/admin` | `GET`/`POST /nfc/tags`, `POST /nfc/tags/{id}/verify` | Was one shared document; passwords now verified server-side |
| Firestore `users/{uid}` | — | Dead legacy login path; delete after confirming it is unused |
| Firestore `products/{docId}` | — | Dead backfill widget; drop |
| `FirebaseAuth.signInAnonymously` | — | Only existed to reach Firestore. Removing it also removes two 8s boot timeouts. |
| `verifyPhoneNumber` (phone OTP) | `POST /auth/pin/reset/request` | Dead path; OTP moves to the API |
| CF `generateClientToken` / `processPayment` | `/payments/*` | |
| CF `processSubscriptionPayment` | `POST /subscription/change` | Referenced by dead code; function not in the repo |

---

## Infrastructure

| Legacy | v1 | Note |
| --- | --- | --- |
| `GET {baseUrl}` (connectivity probe) | `GET /health` | Was fetching a full HTML page to test the link |
| — | `GET /sync/manifest` | New |
| — | `GET /sync/changes` | New — deltas and tombstones |
| — | `POST /sync/mutations` | **New — drains the offline queues** |

---

## Dead endpoints — no replacement needed

Present in the codebase but commented out or unreachable. Listed so they are
not accidentally reimplemented.

| Endpoint | Where | Status |
| --- | --- | --- |
| `GET /api/inventory/products/{categoryId}/{type}` | `lib/nobarcode_products.dart:285` | Superseded by `SyncHelper.fetchCategoriesByID` |
| `GET /api/merchants/subscriptions/1/cancel` | `lib/subscriptionpackages.dart:422` | Commented out |
| `GET /api/merchants/subscriptions/current` (inline copy) | `lib/subscriptionpackages.dart:458` | Duplicate of the service call |
| `POST /api/merchant/invoice/payment` (non-conditional) | `lib/payments.dart:2048` | Superseded by the `/zaad` variant |
| Old Dio `submitAndAddProduct` | `lib/add_products.dart:722` | Commented duplicate |
| Firestore barcode lookup (`shopproducts` / `stockproducts`) | `lib/transfer_page.dart:163` | Commented out |

---

## Client-side changes this mapping implies

Beyond swapping URLs:

1. **Money stops being parsed from strings.** Every amount arrives as an
   integer plus a currency.
2. **Permissions match on `key`, not display name.** The current code compares
   against `'Employee Management'`.
3. **Plan gating reads `plan`/`features`, not `subscription_plan_id == "2"`.**
4. **Image URLs come from the server.** Nothing is assembled from a base-URL
   constant.
5. **Every queued write carries an idempotency key** generated at user-intent
   time and persisted with the row.
6. **The local mirror honours tombstones** and prunes deleted products.
7. **Logout clears the full local footprint**, and is blocked while unsynced
   queues hold rows.
