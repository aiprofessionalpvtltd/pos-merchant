# Dashboard & Reports

The home screen KPIs, the revenue chart, and the four report screens.

> **Status: implemented**, except exports. All five endpoints are live and
> tested, and every response below is captured from the running API.
> `format=csv`/`format=pdf` and the async export job described in the first
> draft of this spec are **not built**: every endpoint returns JSON only for
> now. See [Implementation notes](#implementation-notes).

| Method | Path | Auth |
| --- | --- | --- |
| GET | `/dashboard` | Bearer |
| GET | `/reports/sales` | Bearer · `reports` |
| GET | `/reports/inventory` | Bearer · `reports` |
| GET | `/reports/products` | Bearer · `reports` |
| GET | `/reports/catalogue` | Bearer · `reports` |

---

## Complete endpoint list

Full URL = `{BASE_URL}/api/v1` + path.

**Headers**

| Header | Sent on | Value |
| --- | --- | --- |
| `Accept` | Every request | `application/json` |
| `Authorization` | Every request | `Bearer <token>` from [`POST /auth/pin/login`](auth.md#post-authpinlogin) |

| # | Method | Full path | Purpose | Needs | Query | Success | Errors |
| --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | GET | `/api/v1/dashboard` | Everything the home screen draws, in one call | Bearer | `weeks`, `history_limit` | `200` | `401`, `422` |
| 2 | GET | `/api/v1/reports/sales` | Sales over a period | `reports` | `from`, `to`, `group_by` | `200` | `403`, `422` |
| 3 | GET | `/api/v1/reports/inventory` | Stock valuation and movement | `reports` | `from`, `to`, `category_id` | `200` | `403`, `422` |
| 4 | GET | `/api/v1/reports/products` | Product-level stats, five metrics in one endpoint | `reports` | `metric`, `from`, `to`, `category_id`, `sort`, `page`, `per_page` | `200` | `403`, `422` |
| 5 | GET | `/api/v1/reports/catalogue` | The catalogue grouped by category with sales attached | `reports` | `from`, `to`, `category_id`, `q` | `200` | `403`, `422` |

**Status codes shared by every endpoint**

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `auth.token_invalid` | Missing, revoked or expired token: clear the session |
| `403` | `auth.permission_denied` | The user lacks the `reports` permission (`error.details.required_permission`) |
| `422` | `validation.failed` | Per-field messages in `error.details` |
| `429` | `rate_limited` | Slow down; honour `Retry-After` |

**Response envelope.** Every response carries `success`, `message`, `data` (or
`error`) and `meta.request_id` / `meta.server_time`; `GET /reports/products`
also adds `meta.pagination`. Branch on `error.code`, never on `message`. See
[errors.md](errors.md).

**Who can do what.** A shop owner holds every permission. `GET /dashboard`
needs only a valid token — any signed-in staff member reads it, since the
home screen is the first thing everyone sees. The four `/reports/*` endpoints
need the `reports` permission, so a cashier without it gets
`403 auth.permission_denied`.

**Money.** Every amount is `{ "amount": 11634, "currency": "USD", "display": "$116.34" }`
in minor units. `revenue.total_alt` and the `_alt` fields elsewhere show the
SLSH equivalent at the shop's own exchange rate.

**Default period.** Unless `from`/`to` are given, every report defaults to the
current calendar month to date.

---

## Concepts

### The dashboard's own period

`GET /dashboard` does not take `from`/`to` — it takes `weeks`, and the period
is always the trailing `weeks × 7` days ending today, in the shop's own
timezone. Everything in the response (`revenue`, `series`, `products.total_sold`,
`top_selling`) is scoped to that same window, so the KPIs and the chart never
disagree with each other.

### Real period-over-period comparison

Every `_change_pct` on the dashboard — `revenue.change_pct`,
`products.total_sold_change_pct`, `products.in_shop_change_pct`, and so on — is
a genuine comparison against the immediately preceding period of the same
length, not an estimate. `in_shop`/`in_stock` levels at the **start** of the
period are reconstructed from the stock-movement history (`inventory_histories`):
current level minus the net change recorded during the period. `100` means
there was nothing in the previous period to compare against (a new shop, or a
product with no history yet).

### Money actually received

Every revenue figure across all five endpoints (`revenue`, `series`, sales
totals, inventory `sold`, product `units`, catalogue `units_sold`) counts
**paid** orders (`paid_at` is set) within the period, not merely `Complete`
ones. `orders.pending_count`/`pending_value` on the dashboard is the only place
unpaid orders are counted, and separately from revenue.

### Stock movement, defined

`GET /reports/inventory`'s `movement` block and the dashboard's `new_in_shop`/
`new_in_stock` read the same `inventory_histories` table
[Inventory](inventory.md) writes to:

| Field | Counted from |
| --- | --- |
| `movement.added` / `new_in_shop` / `new_in_stock` | `kind: opening` rows into that location — genuinely new stock, not stock moved from elsewhere |
| `movement.sold` | `kind: sale` rows |
| `movement.transferred` | `kind: transfer` rows |
| `movement.adjusted` | `kind: adjustment` rows, summed as an absolute value |

### The five product metrics, one endpoint

`GET /reports/products` replaces five legacy endpoints with one `metric`
parameter:

| `metric` | Rows |
| --- | --- |
| `sold` (default) | Products sold in the period, ranked by value |
| `in_shop` | Everything currently on the shelf (a snapshot, not period-scoped) |
| `in_stock` | Everything currently in the back room (a snapshot) |
| `new_shop` | Products that received opening stock **on the shelf** during the period |
| `new_stock` | Products that received opening stock **in the back room** during the period |

`sort` (`value` or `units`, `-` prefix for descending) and paging apply to
every metric the same way.

---

## 1. GET `/api/v1/dashboard` — Everything the home screen draws

**Purpose:** One call for the whole home screen. Replaces
`GET /api/getProductStatistics`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `weeks` | int | `4` | How many weeks of daily revenue to return. 1 to 26. |
| `history_limit` | int | `10` | Rows in `transaction_history` and `pending_transactions`. 1 to 50. |

### Why `weeks`

The revenue chart pages week by week so the shopkeeper can swipe back to last
week. The legacy endpoint returned a fixed seven days, so there was nothing to
swipe to. One parameter, one call, and the chart has history.

**Response `200`** (`weeks=2&history_limit=3`, captured)

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-09-10", "to": "2026-09-23", "timezone": "Africa/Mogadishu" },

    "revenue": {
      "total": { "amount": 11634, "currency": "USD", "display": "$116.34" },
      "total_alt": { "amount": 930720, "currency": "SLSH", "display": "930,720 SLSH" },
      "change_pct": 100,
      "trend": "up"
    },

    "series": {
      "granularity": "day",
      "weeks": [
        {
          "week_start": "2026-09-21",
          "label": "This week",
          "is_current": true,
          "total": { "amount": 9051, "currency": "USD", "display": "$90.51" },
          "days": [
            { "date": "2026-09-21", "day": "Mon", "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }, "products_sold": 0, "order_count": 0 },
            { "date": "2026-09-22", "day": "Tue", "sales": { "amount": 3885, "currency": "USD", "display": "$38.85" }, "products_sold": 2, "order_count": 1 },
            { "date": "2026-09-23", "day": "Wed", "sales": { "amount": 5166, "currency": "USD", "display": "$51.66" }, "products_sold": 12, "order_count": 1 },
            { "date": "2026-09-24", "day": "Thu", "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }, "products_sold": 0, "order_count": 0 },
            { "date": "2026-09-25", "day": "Fri", "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }, "products_sold": 0, "order_count": 0 },
            { "date": "2026-09-26", "day": "Sat", "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }, "products_sold": 0, "order_count": 0 },
            { "date": "2026-09-27", "day": "Sun", "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }, "products_sold": 0, "order_count": 0 }
          ]
        },
        {
          "week_start": "2026-09-14",
          "label": "Last week",
          "is_current": false,
          "total": { "amount": 2583, "currency": "USD", "display": "$25.83" },
          "days": [
            { "date": "2026-09-14", "day": "Mon", "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }, "products_sold": 0, "order_count": 0 },
            { "date": "2026-09-15", "day": "Tue", "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }, "products_sold": 0, "order_count": 0 },
            { "date": "2026-09-16", "day": "Wed", "sales": { "amount": 2583, "currency": "USD", "display": "$25.83" }, "products_sold": 6, "order_count": 1 },
            { "date": "2026-09-17", "day": "Thu", "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }, "products_sold": 0, "order_count": 0 },
            { "date": "2026-09-18", "day": "Fri", "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }, "products_sold": 0, "order_count": 0 },
            { "date": "2026-09-19", "day": "Sat", "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }, "products_sold": 0, "order_count": 0 },
            { "date": "2026-09-20", "day": "Sun", "sales": { "amount": 0, "currency": "USD", "display": "$0.00" }, "products_sold": 0, "order_count": 0 }
          ]
        }
      ]
    },

    "orders": { "pending_count": 1, "complete_count": 3, "pending_value": { "amount": 1943, "currency": "USD", "display": "$19.43" } },

    "products": {
      "total_sold": 20, "total_sold_change_pct": 100,
      "in_shop": 67, "in_shop_change_pct": 100,
      "in_stock": 5, "in_stock_change_pct": 100,
      "new_in_shop": 92, "new_in_shop_change_pct": 100,
      "new_in_stock": 0, "new_in_stock_change_pct": 0
    },

    "alerts": { "alarm_count": 1, "restock_count": 3 },

    "top_selling": [
      {
        "product_id": 2087, "product_name": "Bottled Water 12pk", "quantity_sold": 18,
        "price": { "amount": 410, "currency": "USD", "display": "$4.10" },
        "revenue": { "amount": 7380, "currency": "USD", "display": "$73.80" },
        "quantities": { "in_shop": 42, "in_stock": 0 },
        "image": { "thumb_url": null }
      },
      {
        "product_id": 2088, "product_name": "Basmati Rice 5kg", "quantity_sold": 2,
        "price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
        "revenue": { "amount": 3700, "currency": "USD", "display": "$37.00" },
        "quantities": { "in_shop": 23, "in_stock": 5 },
        "image": { "thumb_url": null }
      }
    ],

    "transaction_history": [
      { "order_id": 471, "name": "Amina Yusuf", "initial_name": "AY", "payment_method": "cash",
        "order_date": "2026-09-23T09:45:29Z", "order_date_display": "23 Sep 2026, 12:45",
        "amount": { "amount": 5166, "currency": "USD", "display": "$51.66" }, "status": "Complete" }
    ],

    "pending_transactions": [
      { "order_id": 474, "name": "Layla Ahmed", "initial_name": "LA", "payment_method": null,
        "order_date": "2026-09-23T09:45:29Z", "order_date_display": "23 Sep 2026, 12:45",
        "amount": { "amount": 1943, "currency": "USD", "display": "$19.43" } }
    ],

    "latest_clients": [
      { "order_id": 471, "name": "Amina Yusuf", "initial_name": "AY", "payment_method": "cash" }
    ],

    "generated_at": "2026-09-23T09:45:29Z"
  }
}
```

(`transaction_history`, `pending_transactions` and `latest_clients` are
truncated above to one row each for space; the real response returns up to
`history_limit` and 5 respectively.)

| Field | Meaning |
| --- | --- |
| `revenue.change_pct` | Versus the immediately preceding `weeks`-long period; see [Concepts](#real-period-over-period-comparison) |
| `series.weeks[].label` | `"This week"`, `"Last week"`, or `null` for anything older |
| `orders.pending_value` | Total of unpaid orders, not part of `revenue` |
| `products.new_in_shop`/`new_in_stock` | Opening stock added to that location in the period; see [Concepts](#stock-movement-defined) |
| `alerts` | Same counts as [`GET /inventory/alerts`](inventory.md#11-get-apiv1inventoryalerts--low-stock-alerts) |
| `top_selling` | Top 5 products by quantity sold in the period |
| `pending_transactions[].payment_method` | `null` until the order is paid |

### Legacy field mapping

| Legacy | v1 |
| --- | --- |
| `weekly_summary.total_amount_from_transactions_usd` | `revenue.total` |
| `weekly_summary.total_amount_from_transactions_slsh` | `revenue.total_alt` |
| `weekly_summary.total_amount_from_transactions_percentage` | `revenue.change_pct` |
| `weekly_summary.weekly_sales_statistics[]` | `series.weeks[].days[]` |
| `pending_order_count` / `complete_order_count` | `orders.pending_count` / `complete_count` |
| `total_products_in_shop` (+ `_percentage`) | `products.in_shop` (+ `_change_pct`) |
| `limit.alarm_limit_count` / `stock_limit_count` | `alerts.alarm_count` / `restock_count` |
| `top_selling[]` | `top_selling[]` |
| `transaction_history[]` | `transaction_history[]` |
| `transaction_history_three_months` | Dropped — call [`GET /reports/sales`](#2-get-apiv1reportssales--sales-over-a-period) with a date range |
| `latest_client[]` | `latest_clients[]` |
| `pending_transaction[]` | `pending_transactions[]` |

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `422` | `validation.failed` | `weeks` over 26, or `history_limit` outside 1–50 |

## 2. GET `/api/v1/reports/sales` — Sales over a period

**Purpose:** Sales totals, broken down by payment method and by a bucket of
your choice. Replaces `GET /api/getTransactionReport`. Needs `reports`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `from`, `to` | date | Current month | `YYYY-MM-DD`, inclusive |
| `group_by` | enum | `day` | `day` \| `week` \| `month` \| `payment_method` \| `employee` |

**Response `200`** (default period, captured)

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-09-01", "to": "2026-09-23" },
    "totals": {
      "gross": { "amount": 11634, "currency": "USD", "display": "$116.34" },
      "vat":   { "amount": 554,   "currency": "USD", "display": "$5.54" },
      "fees":  { "amount": 0,     "currency": "USD", "display": "$0.00" },
      "net":   { "amount": 11080, "currency": "USD", "display": "$110.80" },
      "order_count": 3,
      "average_order": { "amount": 3878, "currency": "USD", "display": "$38.78" },
      "products_sold": 20
    },
    "by_payment_method": [
      { "method": "cash", "order_count": 3, "total": { "amount": 11634, "currency": "USD", "display": "$116.34" } }
    ],
    "rows": [
      { "bucket": "2026-09-16", "label": "16 Sep", "order_count": 1, "products_sold": 6,
        "gross": { "amount": 2583, "currency": "USD", "display": "$25.83" }, "net": { "amount": 2460, "currency": "USD", "display": "$24.60" } },
      { "bucket": "2026-09-22", "label": "22 Sep", "order_count": 1, "products_sold": 2,
        "gross": { "amount": 3885, "currency": "USD", "display": "$38.85" }, "net": { "amount": 3700, "currency": "USD", "display": "$37.00" } },
      { "bucket": "2026-09-23", "label": "23 Sep", "order_count": 1, "products_sold": 12,
        "gross": { "amount": 5166, "currency": "USD", "display": "$51.66" }, "net": { "amount": 4920, "currency": "USD", "display": "$49.20" } }
    ]
  }
}
```

`net = gross − vat − fees`. `average_order = gross ÷ order_count`.

**`group_by=payment_method`** — `rows[].bucket` is the rail name:

```json
{ "bucket": "cash", "label": "Cash", "order_count": 3, "products_sold": 20,
  "gross": { "amount": 11634, "currency": "USD", "display": "$116.34" },
  "net":   { "amount": 11080, "currency": "USD", "display": "$110.80" } }
```

**`group_by=week`** — `rows[].bucket` is the Monday of that week:

```json
{ "bucket": "2026-09-21", "label": "Week of 21 Sep", "order_count": 2, "products_sold": 14,
  "gross": { "amount": 9051, "currency": "USD", "display": "$90.51" }, "net": { "amount": 8620, "currency": "USD", "display": "$86.20" } }
```

`group_by=employee` groups by the signed-in user who took the sale, with the
employee's (or owner's) name as `label`.

> **Bug this replaces.** The legacy client fetched the transaction report and
> saved it into the **inventory report** SQLite table, so the offline
> transaction report read an empty or unrelated table. Any client-side cache of
> this endpoint must write to its own table.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `422` | `validation.failed` | `to` before `from`, or an unknown `group_by` |

## 3. GET `/api/v1/reports/inventory` — Stock valuation and movement

**Purpose:** What the shop's stock is worth right now, and how it moved during
the period. Replaces `GET /api/getInventoryReport`. Needs `reports`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `from`, `to` | date | Current month | Inclusive |
| `category_id` | int | — | Limits both the totals and the rows to one category |

**Response `200`** (captured)

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-09-01", "to": "2026-09-23" },
    "totals": {
      "product_count": 3,
      "units_in_shop": 67,
      "units_in_stock": 5,
      "units_in_transportation": 0,
      "shop_value":  { "amount": 63470, "currency": "USD", "display": "$634.70" },
      "stock_value": { "amount": 9250,  "currency": "USD", "display": "$92.50" },
      "total_value": { "amount": 72720, "currency": "USD", "display": "$727.20" }
    },
    "movement": { "added": 92, "sold": 20, "transferred": 5, "adjusted": 0 },
    "rows": [
      { "product_id": 2088, "product_name": "Basmati Rice 5kg", "category": null,
        "in_shop": 23, "in_stock": 5, "sold": 2,
        "unit_price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
        "value": { "amount": 51800, "currency": "USD", "display": "$518.00" } },
      { "product_id": 2087, "product_name": "Bottled Water 12pk", "category": "Beverages",
        "in_shop": 42, "in_stock": 0, "sold": 18,
        "unit_price": { "amount": 410, "currency": "USD", "display": "$4.10" },
        "value": { "amount": 17220, "currency": "USD", "display": "$172.20" } }
    ]
  }
}
```

`value` on a row is `unit_price × (in_shop + in_stock)`. `movement` is defined
under [Concepts](#stock-movement-defined).

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `422` | `validation.failed` | `to` before `from` |

## 4. GET `/api/v1/reports/products` — Product-level statistics

**Purpose:** One endpoint replacing five legacy ones: `getSoldProducts`,
`getNewShopProductsListing`, `getTotalProductsInShop`,
`getNewStockProductsListing` and `getTotalProductsInStock` — all the same
report with a different filter. Needs `reports`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `metric` | enum | `sold` | `sold` \| `in_shop` \| `in_stock` \| `new_shop` \| `new_stock` — see [Concepts](#the-five-product-metrics-one-endpoint) |
| `from`, `to` | date | Current month | Ignored by `in_shop`/`in_stock`, which are always a live snapshot |
| `category_id` | int | — | |
| `sort` | string | `-value` | `value` \| `-value` \| `units` \| `-units` |
| `page`, `per_page` | int | `1`, `50` | `per_page` is at most `200` |

**Response `200`** (`metric=sold`, captured)

```json
{
  "success": true,
  "data": {
    "metric": "sold",
    "period": { "from": "2026-09-01", "to": "2026-09-23" },
    "totals": { "product_count": 2, "units": 20, "value": { "amount": 11080, "currency": "USD", "display": "$110.80" } },
    "rows": [
      {
        "product_id": 2087, "product_name": "Bottled Water 12pk", "bar_code": "CAP-WTR-1",
        "category": { "id": 81, "name": "Beverages" },
        "units": 18,
        "unit_price": { "amount": 410, "currency": "USD", "display": "$4.10" },
        "value": { "amount": 7380, "currency": "USD", "display": "$73.80" },
        "quantities": { "in_shop": 42, "in_stock": 0 },
        "image": { "thumb_url": null }
      },
      {
        "product_id": 2088, "product_name": "Basmati Rice 5kg", "bar_code": "CAP-RCE-1",
        "category": null,
        "units": 2,
        "unit_price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
        "value": { "amount": 3700, "currency": "USD", "display": "$37.00" },
        "quantities": { "in_shop": 23, "in_stock": 5 },
        "image": { "thumb_url": null }
      }
    ]
  },
  "meta": {
    "request_id": "req_01M36TFV61X4QYY7BJ3225RBVX", "server_time": "2026-09-23T09:45:29Z",
    "pagination": { "page": 1, "per_page": 50, "total": 2, "total_pages": 1, "has_more": false }
  }
}
```

`units` means "sold" for `metric=sold`/`new_shop`/`new_stock`, and "currently on
hand at that location" for `metric=in_shop`/`in_stock`. `value = units × unit_price`
either way, which is what `sort=value` orders by.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `422` | `validation.failed` | An unknown `metric` or `sort` |

## 5. GET `/api/v1/reports/catalogue` — The catalogue with sales attached

**Purpose:** The product catalogue screen: every category, its products, and
how much each sold in the period. Replaces `GET /api/getAllProductsWithCategories`.
Needs `reports`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `from`, `to` | date | Current month | |
| `category_id` | int | — | One category instead of all |
| `q` | string | — | Filters products by name within each category |

**Response `200`** (captured)

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-09-01", "to": "2026-09-23" },
    "categories": [
      {
        "id": 81,
        "name": "Beverages",
        "product_count": 1,
        "units_sold": 18,
        "revenue": { "amount": 7380, "currency": "USD", "display": "$73.80" },
        "products": [
          {
            "product_id": 2087,
            "product_name": "Bottled Water 12pk",
            "price": { "amount": 410, "currency": "USD", "display": "$4.10" },
            "total_sold": 18,
            "quantities": { "in_shop": 42, "in_stock": 0 },
            "image": { "thumb_url": null }
          }
        ]
      }
    ]
  }
}
```

Grouped by category rather than returned flat with a `category` string on each
row, which is how the screen actually renders it. A category with no products
matching `q` is still listed, with an empty `products` array.

**Errors**

| Status | Code | Meaning |
| --- | --- | --- |
| `422` | `validation.failed` | `to` before `from` |

---

## Postman / curl quick start

```bash
BASE=https://your-host/api/v1

curl "$BASE/dashboard?weeks=2&history_limit=5" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl "$BASE/reports/sales?group_by=week" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl "$BASE/reports/inventory?category_id=81" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl "$BASE/reports/products?metric=new_shop&sort=-units" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"

curl "$BASE/reports/catalogue?q=rice" -H 'Accept: application/json' -H "Authorization: Bearer $TOKEN"
```

---

## Implementation notes

Built entirely on tables the other v1 modules already write to — no new
migration. `DashboardService` and `ReportService` query `orders`, `order_items`,
`products`, `product_inventories`, `inventory_histories` and `categories`
directly.

- **`from`/`to` default to the current calendar month** on every `/reports/*`
  endpoint; `GET /dashboard` computes its own trailing `weeks`-day window
  instead (see [Concepts](#the-dashboards-own-period)).
- **Revenue counts paid orders only** (`paid_at` set), not merely `Complete`
  ones — a `Complete` order that was never paid does not appear in any revenue
  figure. See [Concepts](#money-actually-received).
- **`change_pct` is a real period-over-period comparison**, reconstructed from
  `inventory_histories` for stock levels rather than estimated; `100` means
  there was nothing in the prior period to compare against.
- **`products_sold` on every sales-report row** is scoped to that row's own
  bucket and period — this was the one figure that needed its own query per
  bucket (day/week/month, payment method or employee) to get right; a naive
  join double-counts orders with more than one line.
- **Images** resolve the same way as [inventory.md](inventory.md): `image_file_id`
  through the [Files module](files.md) first, the legacy `image` path as a
  fallback.
- **`GET /dashboard` needs no permission**, only a valid token — the home
  screen is the first thing every signed-in user sees, staff included.
- **Not built:** `format=csv`, `format=pdf`, and the async `job_id`/`GET /jobs/{id}`
  export flow described in the first draft of this spec. Every endpoint above
  returns JSON only; a `format` other than `json` is rejected with
  `422 validation.failed`.
- **Legacy routes** (`/api/getProductStatistics`, `/api/getTransactionReport`,
  `/api/getInventoryReport`, `/api/getSoldProducts` and the other four
  product-listing endpoints, `/api/getAllProductsWithCategories`) keep working
  alongside.
