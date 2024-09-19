<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
                'weekly_summary' => $this->getWeeklySalesAndStatistics(),
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


}
