<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductCatalogResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\TopSellingProductResource;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class DashboardController extends BaseController
{

    public function getOverallProductStatistics()
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            // Ensure the authenticated user exists and has a merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get merchant ID from authenticated user's merchant relation
            $merchantID = $authUser->merchant->id;

            // Define the date range (last 7 days)
            $sevenDaysAgo = now()->subDays(7);

            // Total products in shop (associated with this merchant)
            $totalProductsInShop = ProductInventory::whereHas('product', function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            })->where('type', 'shop')->sum('quantity');

            // Total products in stock (associated with this merchant)
            $totalProductsInStock = ProductInventory::whereHas('product', function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            })->where('type', 'stock')->sum('quantity');

            // Overall total quantity (sum of both stock and shop)
            $overallTotal = $totalProductsInShop + $totalProductsInStock;

            // Total sold products count (based on CartItem and related products of this merchant)
            $totalProductsSold = OrderItem::whereHas('product', function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            })->sum('quantity');

            // New products added to the shop in the last 7 days
            $newProductsInShop = ProductInventory::whereHas('product', function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            })->where('type', 'shop')->where('created_at', '>=', $sevenDaysAgo)->sum('quantity');

            // New products added to the stock in the last 7 days
            $newProductsInStock = ProductInventory::whereHas('product', function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            })->where('type', 'stock')->where('created_at', '>=', $sevenDaysAgo)->sum('quantity');

            // Total new products in the last 7 days
            $totalNewProducts = $newProductsInShop + $newProductsInStock;

            // Calculate percentages
            $shopPercentage = $overallTotal > 0
                ? ($totalProductsInShop / $overallTotal) * 100
                : 0;

            $stockPercentage = $overallTotal > 0
                ? ($totalProductsInStock / $overallTotal) * 100
                : 0;

            $soldPercentage = $overallTotal > 0
                ? ($totalProductsSold / $overallTotal) * 100
                : 0;

            $newProductShopPercentage = $overallTotal > 0
                ? ($newProductsInShop / $overallTotal) * 100
                : 0;

            $newProductStockPercentage = $overallTotal > 0
                ? ($newProductsInStock / $overallTotal) * 100
                : 0;

            // get Pending Order count
            $pendingOrder = Order::where('order_status','pending')->count();

            // get Complete Order count
            $completedOrder = Order::where('order_status','completed')->count();

            // Prepare response data
            $data = [
                'top_selling' => $this->getTopSellingProducts(),
                'weekly_summary' => $this->getWeeklySalesAndStatistics(),
                'limit' => $this->getProductLimitCounts(),
                'pending_orders' => $pendingOrder,
                'completed_orders' => $completedOrder,
                'total_products_in_shop' => $totalProductsInShop,
                'total_products_in_shop_percentage' => round($shopPercentage, 2) . '%',
                'total_products_in_stock' => $totalProductsInStock,
                'total_products_in_stock_percentage' => round($stockPercentage, 2) . '%',
                'overall_total' => $overallTotal,
                'total_products_sold' => $totalProductsSold,
                'total_products_sold_percentage' => round($soldPercentage, 2) . '%',
                'new_products_in_shop' => $newProductsInShop,
                'new_products_in_shop_percentage' => round($newProductShopPercentage,2). '%',
                'new_products_in_stock' => $newProductsInStock,
                'new_products_in_stock_percentage' => round($newProductStockPercentage,2). '%',

            ];

            // Return success response with the statistics
            return $this->sendResponse($data, 'Overall product statistics retrieved successfully.');

        } catch (\Exception $e) {
            return $this->sendError('Error fetching overall product statistics.', [$e->getMessage()]);
        }
    }


    public function getWeeklySalesAndStatistics()
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            // Ensure the authenticated user exists and has a merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get merchant ID from authenticated user's merchant relation
            $merchantID = $authUser->merchant->id;

            // Total amount from transactions table
            $totalAmountFromTransactions = Transaction::where('merchant_id', $merchantID)->sum('transaction_amount');

            // Set start of the week (Monday) and end of the week (Sunday)
            $startOfWeek = now()->startOfWeek();
            $endOfWeek = now()->endOfWeek();

            // Get orders within the week
            $weeklySalesData = Order::where('merchant_id', $merchantID)
                ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->selectRaw('DATE(created_at) as date, SUM(sub_total) as total_sales')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            // Get order items within the week and count the sold products
            $weeklyProductData = OrderItem::whereHas('order', function ($query) use ($merchantID, $startOfWeek, $endOfWeek) {
                $query->where('merchant_id', $merchantID)
                    ->whereBetween('created_at', [$startOfWeek, $endOfWeek]);
            })->selectRaw('DATE(created_at) as date, COUNT(*) as total_products')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            // Prepare response data
            $data = [];

            // Combine sales and product data
            foreach ($weeklySalesData as $sales) {
                $date = $sales->date;
                $totalSales = $sales->total_sales;

                // Find the matching product count for the date
                $totalProducts = optional($weeklyProductData->firstWhere('date', $date))->total_products ?? 0;

                // Format the date (e.g., Monday 11 Sept)
                $formattedDay = \Carbon\Carbon::parse($date)->format('D');
                $formattedDate = \Carbon\Carbon::parse($date)->format('j.m');

                $data[] = [
                    'day' => $formattedDay,
                    'date' => $formattedDate,
                    'total_sales' => $totalSales,
                    'total_products_sold' => $totalProducts,
                ];
            }

            // Response data including total transaction amount
            $response = [
                'total_amount_from_transactions_slsh' => $totalAmountFromTransactions,
                'total_amount_from_transactions_usd' => convertShillingToUSD($totalAmountFromTransactions),
                'weekly_sales_statistics' => $data,
            ];

            // Return success response
           return $response;

        } catch (\Exception $e) {
            return $this->sendError('Error fetching weekly sales and statistics.', [$e->getMessage()]);
        }
    }

    public function getTopSellingProducts()
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            // Ensure the authenticated user exists and has a merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get merchant ID from authenticated user's merchant relation
            $merchantID = $authUser->merchant->id;

             // Retrieve top-selling products based on the orders placed by the merchant
            $topSellingProducts = Product::whereHas('orderItems.order', function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            })
                ->withCount(['orderItems as total_quantity_sold' => function ($query) {
                    $query->select(\DB::raw('SUM(quantity)'));
                }])
                ->orderBy('total_quantity_sold', 'desc')
                ->take(10) // Limit to top 10 selling products
                ->get();

            // Return success response with top-selling products
//            return $this->sendResponse(TopSellingProductResource::collection($topSellingProducts), 'Top-selling products retrieved successfully.');
            return  TopSellingProductResource::collection($topSellingProducts);


        } catch (\Exception $e) {
            return $this->sendError('Error fetching top-selling products.', [$e->getMessage()]);
        }
    }

    public function getProductLimitCounts()
    {
        try {
            // Count products where any inventory's quantity is less than or equal to the alarm_limit
            $alarmLimitCount = Product::whereHas('inventories', function ($query) {
                $query->whereColumn('quantity', '<=', 'alarm_limit')->where('type', 'shop');
            })->count();

            // Count products where any inventory's quantity is less than or equal to the stock_limit
            $stockLimitCount = Product::whereHas('inventories', function ($query) {
                $query->whereColumn('quantity', '<=', 'stock_limit')->where('type', 'shop');
            })->count();

            // Return the response with both counts
            return [
                'alarm_limit_count' => $alarmLimitCount,
                'stock_limit_count' => $stockLimitCount
            ];

        } catch (\Exception $e) {
            return $this->sendError('Error fetching product limit counts.', [$e->getMessage()]);
        }
    }


    public function getAllProductsWithCategories()
    {
        try {
            // Get all products with their categories, images, and order items
            $products = Product::with(['category', 'orderItems', 'inventories'])->get();

            // Use the resource collection to transform the products
            return $this->sendResponse(ProductCatalogResource::collection($products), 'All products with categories retrieved successfully.');

        } catch (\Exception $e) {
            return $this->sendError('Error fetching products with categories.', [$e->getMessage()]);
        }
    }



    // Function to get products based on alarm limit
    public function getProductsByAlarmLimit()
    {
        try {
            // Get products with their inventories where quantity is less than or equal to alarm limit
            $products = Product::whereHas('inventories', function ($query) {
                $query->whereColumn('quantity', '<=', 'alarm_limit')->where('type', 'shop');
            })
                ->with(['inventories' => function($query) {
                    $query->select('id', 'product_id', 'type', 'quantity'); // Select relevant fields
                }])
                ->get(['id', 'product_name']); // Select only the necessary fields from Product

            // Transform the products to include shop and stock quantities
            $result = $products->map(function($product) {
                $shopQuantity = 0;
                $stockQuantity = 0;

                // Loop through inventories to aggregate quantities based on type
                foreach ($product->inventories as $inventory) {
                    if ($inventory->type === 'shop') {
                        $shopQuantity += $inventory->quantity;
                    } elseif ($inventory->type === 'stock') {
                        $stockQuantity += $inventory->quantity;
                    }
                }

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'shop' => $shopQuantity,
                    'stock' => $stockQuantity,
                ];
            });

            return $this->sendResponse($result, 'Products retrieved successfully for alarm limit.');

        } catch (\Exception $e) {
            return $this->sendError('Error fetching products for alarm limit.', [$e->getMessage()]);
        }
    }


// Function to get products based on stock limit
    public function getProductsByStockLimit()
    {
        try {
            // Get products with their inventories where quantity is less than or equal to stock limit
            $products = Product::whereHas('inventories', function ($query) {
                $query->whereColumn('quantity', '<=', 'stock_limit')->where('type', 'shop');
            })
                ->with(['inventories' => function($query) {
                    $query->select('id', 'product_id', 'type', 'quantity'); // Select relevant fields
                }])
                ->get(['id', 'product_name']); // Select only the necessary fields from Product

            // Transform the products to include shop and stock quantities
            $result = $products->map(function($product) {
                $shopQuantity = 0;
                $stockQuantity = 0;

                // Loop through inventories to aggregate quantities based on type
                foreach ($product->inventories as $inventory) {
                    if ($inventory->type === 'shop') {
                        $shopQuantity += $inventory->quantity;
                    } elseif ($inventory->type === 'stock') {
                        $stockQuantity += $inventory->quantity;
                    }
                }

                return [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'shop' => $shopQuantity,
                    'stock' => $stockQuantity,
                ];
            });

            return $this->sendResponse($result, 'Products retrieved successfully for stock limit.');

        } catch (\Exception $e) {
            return $this->sendError('Error fetching products for stock limit.', [$e->getMessage()]);
        }
    }





}
