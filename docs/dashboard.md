# Dashboard & Reports

The home screen KPIs, the revenue chart, and the four report screens.

| Method | Path | Auth |
| --- | --- | --- |
| GET | [`/dashboard`](#get-dashboard) | Bearer |
| GET | [`/reports/sales`](#get-reportssales) | Bearer · `reports` |
| GET | [`/reports/inventory`](#get-reportsinventory) | Bearer · `reports` |
| GET | [`/reports/products`](#get-reportsproducts) | Bearer · `reports` |
| GET | [`/reports/catalogue`](#get-reportscatalogue) | Bearer · `reports` |

---

## GET /dashboard

Everything the home screen draws, in one call. Replaces
`GET /api/getProductStatistics`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `weeks` | int | `4` | How many weeks of daily revenue to return. Max `26`. |
| `history_limit` | int | `10` | Rows of recent transaction history |

### Why `weeks`

The revenue chart pages week by week so the shopkeeper can swipe back to last
week. The legacy endpoint returned a fixed seven days, so there was nothing to
swipe to. One parameter, one call, and the chart has history.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-08-24", "to": "2026-09-20", "timezone": "Africa/Mogadishu" },

    "revenue": {
      "total":     { "amount": 428050, "currency": "USD", "display": "$4,280.50" },
      "total_alt": { "amount": 34244000, "currency": "SLSH", "display": "34,244,000 SLSH" },
      "change_pct": 12.4,
      "trend": "up"
    },

    "series": {
      "granularity": "day",
      "weeks": [
        {
          "week_start": "2026-09-14",
          "label": "This week",
          "is_current": true,
          "total": { "amount": 428050, "currency": "USD", "display": "$4,280.50" },
          "days": [
            { "date": "2026-09-14", "day": "Mon",
              "sales": { "amount": 42000, "currency": "USD", "display": "$420.00" },
              "products_sold": 38, "order_count": 12 },
            { "date": "2026-09-15", "day": "Tue",
              "sales": { "amount": 61050, "currency": "USD", "display": "$610.50" },
              "products_sold": 52, "order_count": 18 }
          ]
        },
        {
          "week_start": "2026-09-07",
          "label": "Last week",
          "is_current": false,
          "total": { "amount": 268500, "currency": "USD", "display": "$2,685.00" },
          "days": [ ]
        }
      ]
    },

    "orders": {
      "pending_count": 4,
      "complete_count": 121,
      "pending_value": { "amount": 24180, "currency": "USD", "display": "$241.80" }
    },

    "products": {
      "total_sold": 1284,
      "total_sold_change_pct": 12.4,
      "in_shop": 123,
      "in_shop_change_pct": 3.1,
      "in_stock": 393,
      "in_stock_change_pct": -2.4,
      "new_in_shop": 24,
      "new_in_shop_change_pct": 8.0,
      "new_in_stock": 96,
      "new_in_stock_change_pct": 5.2
    },

    "alerts": { "alarm_count": 6, "restock_count": 11 },

    "top_selling": [
      {
        "product_id": 107,
        "product_name": "Bottled Water 12pk",
        "quantity_sold": 210,
        "price": { "amount": 410, "currency": "USD", "display": "$4.10" },
        "revenue": { "amount": 86100, "currency": "USD", "display": "$861.00" },
        "quantities": { "in_shop": 48, "in_stock": 160 },
        "image": { "thumb_url": "https://cdn.exelo.co/p/107/water_thumb.png" }
      }
    ],

    "transaction_history": [
      {
        "order_id": 10241,
        "name": "Amina Yusuf",
        "initial_name": "AY",
        "payment_method": "card",
        "order_date": "2026-09-14T09:42:00Z",
        "order_date_display": "14 Sep 2026, 09:42",
        "amount": { "amount": 6477, "currency": "USD", "display": "$64.77" },
        "status": "Complete"
      }
    ],

    "pending_transactions": [
      {
        "order_id": 10245,
        "name": "Amina Yusuf",
        "initial_name": "AY",
        "payment_method": "card",
        "order_date": "2026-09-15T08:14:00Z",
        "order_date_display": "15 Sep 2026, 08:14",
        "amount": { "amount": 8534, "currency": "USD", "display": "$85.34" }
      }
    ],

    "latest_clients": [
      { "order_id": 10241, "name": "Amina Yusuf", "initial_name": "AY", "payment_method": "card" }
    ],

    "generated_at": "2026-09-18T14:45:00Z"
  }
}
```

### Legacy field mapping

| Legacy | v1 |
| --- | --- |
| `weekly_summary.total_amount_from_transactions_usd` | `revenue.total` |
| `weekly_summary.total_amount_from_transactions_slsh` | `revenue.total_alt` |
| `weekly_summary.total_amount_from_transactions_percentage` | `revenue.change_pct` |
| `weekly_summary.weekly_sales_statistics[]` | `series.weeks[].days[]` |
| `weekly_sales_statistics[].total_sales_in_usd` | `series.weeks[].days[].sales` |
| `weekly_sales_statistics[].total_products_sold` | `series.weeks[].days[].products_sold` |
| `pending_order_count` / `complete_order_count` | `orders.pending_count` / `complete_count` |
| `total_products_in_shop` (+ `_percentage`) | `products.in_shop` (+ `_change_pct`) |
| `limit.alarm_limit_count` / `stock_limit_count` | `alerts.alarm_count` / `restock_count` |
| `top_selling[]` | `top_selling[]` |
| `transaction_history[]` | `transaction_history[]` |
| `transaction_history_three_months[]` | `GET /reports/sales?from=&to=` |
| `latest_client[]` | `latest_clients[]` |
| `pending_transaction[]` | `pending_transactions[]` |

The legacy series was a flat array of days that the client had to chunk into
weeks itself. v1 groups them, labels them, and marks the current week, so the
pager has no arithmetic to do.

`transaction_history_three_months` is dropped — a three-month history does not
belong in the dashboard payload on a slow connection. That view calls
`/reports/sales` with a date range.

### Offline behaviour

The client caches this whole payload and paints from it on next launch before
the network call returns, so the dashboard is never blank on a slow start. It
is a pure cache: safe to drop, always rebuildable.

---

## GET /reports/sales

Sales over a period. Replaces `GET /api/getTransactionReport`.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `from`, `to` | date | Current month | Inclusive |
| `group_by` | enum | `day` | `day` \| `week` \| `month` \| `payment_method` \| `employee` |
| `format` | enum | `json` | `json` \| `csv` \| `pdf` |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-09-01", "to": "2026-09-18" },
    "totals": {
      "gross":     { "amount": 428050, "currency": "USD", "display": "$4,280.50" },
      "vat":       { "amount": 21403,  "currency": "USD", "display": "$214.03" },
      "fees":      { "amount": 8561,   "currency": "USD", "display": "$85.61" },
      "net":       { "amount": 398086, "currency": "USD", "display": "$3,980.86" },
      "order_count": 121,
      "average_order": { "amount": 3537, "currency": "USD", "display": "$35.37" },
      "products_sold": 1284
    },
    "by_payment_method": [
      { "method": "cash",   "order_count": 64, "total": { "amount": 210400, "currency": "USD", "display": "$2,104.00" } },
      { "method": "zaad",   "order_count": 31, "total": { "amount": 121300, "currency": "USD", "display": "$1,213.00" } },
      { "method": "edahab", "order_count": 18, "total": { "amount": 68200,  "currency": "USD", "display": "$682.00" } },
      { "method": "card",   "order_count": 8,  "total": { "amount": 28150,  "currency": "USD", "display": "$281.50" } }
    ],
    "rows": [
      {
        "bucket": "2026-09-14",
        "label": "14 Sep",
        "order_count": 12,
        "products_sold": 38,
        "gross": { "amount": 42000, "currency": "USD", "display": "$420.00" },
        "net":   { "amount": 39060, "currency": "USD", "display": "$390.60" }
      }
    ]
  }
}
```

> **Bug this replaces.** The legacy client fetched the transaction report and
> saved it into the **inventory report** SQLite table, so the offline
> transaction report read an empty or unrelated table. Any client-side cache of
> this endpoint must write to its own table.

---

## GET /reports/inventory

Stock valuation and movement. Replaces `GET /api/getInventoryReport`.

**Query** — `from`, `to`, `category_id`, `format`.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-09-01", "to": "2026-09-18" },
    "totals": {
      "product_count": 318,
      "units_in_shop": 123,
      "units_in_stock": 393,
      "units_in_transportation": 0,
      "shop_value":  { "amount": 184200, "currency": "USD", "display": "$1,842.00" },
      "stock_value": { "amount": 612400, "currency": "USD", "display": "$6,124.00" },
      "total_value": { "amount": 796600, "currency": "USD", "display": "$7,966.00" }
    },
    "movement": {
      "added": 24,
      "sold": 1284,
      "transferred": 96,
      "adjusted": 11
    },
    "rows": [
      {
        "product_id": 101,
        "product_name": "Basmati Rice 5kg",
        "category": "Dry goods",
        "in_shop": 24,
        "in_stock": 86,
        "sold": 124,
        "unit_price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
        "value": { "amount": 203500, "currency": "USD", "display": "$2,035.00" }
      }
    ]
  }
}
```

---

## GET /reports/products

Product-level statistics. One endpoint replacing five:
`getSoldProducts`, `getNewShopProductsListing`, `getTotalProductsInShop`,
`getNewStockProductsListing` and `getTotalProductsInStock` — all the same report
with a different filter.

**Query**

| Param | Type | Default | Notes |
| --- | --- | --- | --- |
| `metric` | enum | `sold` | `sold` \| `in_shop` \| `in_stock` \| `new_shop` \| `new_stock` |
| `from`, `to` | date | Current month | |
| `category_id` | int | — | |
| `sort` | string | `-value` | |
| `page`, `per_page` | int | `1`, `50` | |

| `metric` | Returns |
| --- | --- |
| `sold` | Units sold in the period, ranked |
| `in_shop` | Everything currently on the shelf |
| `in_stock` | Everything currently in the back room |
| `new_shop` | Products added to the shelf in the period |
| `new_stock` | Products added to the back room in the period |

**Response `200`**

```json
{
  "success": true,
  "data": {
    "metric": "sold",
    "period": { "from": "2026-09-01", "to": "2026-09-18" },
    "totals": { "product_count": 8, "units": 1284,
      "value": { "amount": 428050, "currency": "USD", "display": "$4,280.50" } },
    "rows": [
      {
        "product_id": 107,
        "product_name": "Bottled Water 12pk",
        "bar_code": "WTR-012",
        "category": { "id": 4, "name": "Beverages" },
        "units": 210,
        "unit_price": { "amount": 410, "currency": "USD", "display": "$4.10" },
        "value": { "amount": 86100, "currency": "USD", "display": "$861.00" },
        "quantities": { "in_shop": 48, "in_stock": 160 },
        "image": { "thumb_url": "https://cdn.exelo.co/p/107/water_thumb.png" }
      }
    ]
  },
  "meta": { "pagination": { "page": 1, "per_page": 50, "total": 8, "total_pages": 1, "has_more": false } }
}
```

---

## GET /reports/catalogue

The full catalogue with sales attached — the product catalogue screen. Replaces
`GET /api/getAllProductsWithCategories`.

**Query** — `from`, `to`, `category_id`, `q`, paging, `format`.

**Response `200`**

```json
{
  "success": true,
  "data": {
    "period": { "from": "2026-09-01", "to": "2026-09-18" },
    "categories": [
      {
        "id": 1,
        "name": "Dry goods",
        "product_count": 4,
        "units_sold": 325,
        "revenue": { "amount": 182400, "currency": "USD", "display": "$1,824.00" },
        "products": [
          {
            "product_id": 101,
            "product_name": "Basmati Rice 5kg",
            "price": { "amount": 1850, "currency": "USD", "display": "$18.50" },
            "total_sold": 124,
            "quantities": { "in_shop": 24, "in_stock": 86 },
            "image": { "thumb_url": "https://cdn.exelo.co/p/101/rice_thumb.png" }
          }
        ]
      }
    ]
  }
}
```

Grouped by category rather than returned flat with a `category` string on each
row, which is how the screen actually renders it.

---

## Export formats

`format=csv` and `format=pdf` on any report return the file directly with the
appropriate `Content-Type`, bypassing the envelope. Large exports return `202`
with a job reference instead:

```json
{
  "success": true,
  "message": "Your report is being prepared",
  "data": {
    "job_id": "job_01JBXZ4N7P",
    "status": "processing",
    "poll": "/api/v1/jobs/job_01JBXZ4N7P"
  }
}
```

This keeps a merchant from holding a slow connection open for a minute while a
year of sales is rendered.
