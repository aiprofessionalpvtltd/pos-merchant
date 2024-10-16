<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductCatalogResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\TopSellingProductResource;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class DashboardController extends BaseController
{

    public function mainDashboard()
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }


            // Ensure the authenticated user exists and has a merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get merchant ID from authenticated user's merchant relation
            $merchantID = $authUser->merchant->id;

            $pendingCount = Order::where('merchant_id', $merchantID)->where('order_status', 'Pending')->count();
            $completeCount = Order::where('merchant_id', $merchantID)->where('order_status', 'Complete')->count();


            // Prepare response data
            $data = [
                'pending_order_count' => $pendingCount,
                'complete_order_count' => $completeCount,


            ];

            // Return success response with the statistics
            return $this->sendResponse($data, 'Overall product statistics retrieved successfully.');

        } catch (\Exception $e) {
            return $this->sendError('Error fetching overall product statistics.', [$e->getMessage()]);
        }
    }


    public function getOverallProductStatistics()
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }


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

            $pendingCount = Order::where('merchant_id', $merchantID)->where('order_status', 'Pending')->count();
            $completeCount = Order::where('merchant_id', $merchantID)->where('order_status', 'Complete')->count();


            if ($authUser->user_type == 'merchant') {
                $currentSubscriptionID = $authUser->merchant->currentSubscription->subscription_plan_id;
            } else {
                $currentSubscriptionID = $authUser->employee->merchant->currentSubscription->subscription_plan_id;
            }

            if ($currentSubscriptionID == 2) {
                $transactionHistories = $this->getTransactionForSilver()->getData(true);
                $transactionHistories = $transactionHistories['data'];

                $weeklyStatistics = $this->getWeeklySalesAndStatisticsSilver()->getData(true);
                $weeklyStatistics = $weeklyStatistics['data'];

                $latestClient = $this->getLatestClientsForSilverMerchant()->getData(true);
                $latestClient = $latestClient['data'];

            } else {
                $transactionHistories = $this->getTransactionForSilver()->getData(true);
                $transactionHistories = $transactionHistories['data'];

                $weeklyStatistics = $this->getWeeklySalesAndStatistics()->getData(true);
                $weeklyStatistics = $weeklyStatistics['data'];

                $latestClient = $this->getLatestClientsForNormalMerchant()->getData(true);
                $latestClient = $latestClient['data'];
            }


            $pendingTransaction = $this->getPendingOrders()->getData(true);
            $pendingTransaction = $pendingTransaction['data'];

            // Prepare response data
            $data = [
                'top_selling' => $this->getTopSellingProducts(),
                'weekly_summary' => $weeklyStatistics,
                'limit' => $this->getProductLimitCounts(),
                'transaction_history' => $transactionHistories,
                'pending_transaction' => $pendingTransaction,
                'latest_client' => $latestClient,
                'pending_order_count' => $pendingCount,
                'complete_order_count' => $completeCount,
                'total_products_in_shop' => $totalProductsInShop,
                'total_products_in_shop_percentage' => number_format($shopPercentage, 2),  // Format to 2 decimal places
                'total_products_in_stock' => $totalProductsInStock,
                'total_products_in_stock_percentage' => number_format($stockPercentage, 2), // Format to 2 decimal places
                'overall_total' => $overallTotal,
                'total_products_sold' => $totalProductsSold,
                'total_products_sold_percentage' => number_format($soldPercentage, 2),      // Format to 2 decimal places
                'new_products_in_shop' => $newProductsInShop,
                'new_products_in_shop_percentage' => number_format($newProductShopPercentage, 2),  // Format to 2 decimal places
                'new_products_in_stock' => $newProductsInStock,
                'new_products_in_stock_percentage' => number_format($newProductStockPercentage, 2), // Format to 2 decimal places
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

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }


            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchantID = $authUser->merchant->id;

            // Set start and end of the week
            $startOfWeek = now()->startOfWeek();
            $endOfWeek = now()->endOfWeek();


            // Get total transaction amount for the current week for invoices (no order_id)
            $weeklyTransactions = Transaction::where('merchant_id', $merchantID)
                ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->get();

            // Calculate total transaction amount from these transactions
            $totalAmountFromTransactions = $weeklyTransactions->sum('transaction_amount');

            // Calculate the total transaction amount for all time (only invoices without order_id)
            $totalAmountAllTime = Transaction::where('merchant_id', $merchantID)
                ->sum('transaction_amount');

            // Calculate the percentage of weekly transactions from total transactions
            $totalAmountPercentage = $totalAmountAllTime > 0
                ? ($totalAmountFromTransactions / $totalAmountAllTime) * 100
                : 0;

            // Get invoices for the week (no order associated)
            $weeklyInvoiceData = Transaction::with('order')->where('merchant_id', $merchantID)
                ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->selectRaw('DATE(created_at) as date, SUM(transaction_amount) as total_sales')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            $soldProductsData = OrderItem::whereHas('order', function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            })
                ->select(DB::raw('DATE(created_at) as sold_date'),
                    DB::raw('SUM(quantity) as total_products'))
                ->groupBy(DB::raw('DATE(created_at)'))
                ->whereBetween(DB::raw('DATE(created_at)'), [$startOfWeek, $endOfWeek])
                ->get();


            // Prepare response data for the week
            $data = $this->prepareWeeklySalesData($startOfWeek, $endOfWeek, $weeklyInvoiceData, $soldProductsData);

            // Response data including transaction details
            $response = [
                'total_amount_from_transactions_slsh' => round($totalAmountFromTransactions, 2),
                'total_amount_from_transactions_usd' => convertShillingToUSD($totalAmountFromTransactions),
                'total_amount_from_transactions_percentage' => round($totalAmountPercentage, 2),
                'weekly_sales_statistics' => $data,
            ];

            return $this->sendResponse($response, 'Weekly sales and statistics (Normal) retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error fetching weekly sales and statistics.', [$e->getMessage()]);
        }
    }

    public function getWeeklySalesAndStatisticsSilver()
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }


            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchantID = $authUser->merchant->id;

            // Set start and end of the week
            $startOfWeek = now()->startOfWeek();
            $endOfWeek = now()->endOfWeek();

            // Get total transaction amount for the current week for invoices (no order_id)
            $weeklyTransactions = Transaction::where('merchant_id', $merchantID)
//                ->whereNull('order_id') // Only transactions without order_id (for silver merchants)
                ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->get();

            // Calculate total transaction amount from these transactions
            $totalAmountFromTransactions = $weeklyTransactions->sum('transaction_amount');

            // Calculate the total transaction amount for all time (only invoices without order_id)
            $totalAmountAllTime = Transaction::where('merchant_id', $merchantID)
//                ->whereNull('order_id') // Only transactions without order_id
                ->sum('transaction_amount');

            // Calculate the percentage of weekly transactions from total transactions
            $totalAmountPercentage = $totalAmountAllTime > 0
                ? ($totalAmountFromTransactions / $totalAmountAllTime) * 100
                : 0;

            // Get invoices for the week (no order associated)
            $weeklyInvoiceData = Transaction::where('merchant_id', $merchantID)
//                ->whereNull('order_id') // Only invoices with no order_id
                ->whereBetween('created_at', [$startOfWeek, $endOfWeek])
                ->selectRaw('DATE(created_at) as date, SUM(transaction_amount) as total_sales')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            // Prepare response data for the week
            $data = $this->prepareWeeklySalesData($startOfWeek, $endOfWeek, $weeklyInvoiceData, null);

            // Response data including transaction details
            $response = [
                'total_amount_from_transactions_slsh' => round($totalAmountFromTransactions, 2),
                'total_amount_from_transactions_usd' => convertShillingToUSD($totalAmountFromTransactions),
                'total_amount_from_transactions_percentage' => round($totalAmountPercentage, 2),
                'weekly_sales_statistics' => $data,
            ];

            return $this->sendResponse($response, 'Weekly sales and statistics (Silver) retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error fetching weekly sales and statistics.', [$e->getMessage()]);
        }
    }

    protected function prepareWeeklySalesData($startOfWeek, $endOfWeek, $salesData, $productData = null)
    {
        $data = [];
        $daysOfWeek = \Carbon\CarbonPeriod::create($startOfWeek, $endOfWeek); // Monday to Sunday

        foreach ($daysOfWeek as $day) {
            $dayString = $day->toDateString();
            $formattedDay = date('D', strtotime($dayString));
            $formattedDate = date('j.m', strtotime($dayString));

            // Find sales for the day
            $salesForDay = optional($salesData->firstWhere('date', $dayString))->total_sales ?? 0;

            if ($productData) {
                // Find total products sold for the day
                $totalProductsForDay = optional($productData->firstWhere('sold_date', $dayString))->total_products ?? 0;

            }

            // Format data for each day of the week
            $data[] = [
                'day' => $formattedDay,
                'date' => $formattedDate,
                'total_sales' => $salesForDay,
                'total_sales_in_usd' => convertShillingToUSD($salesForDay),
                'total_products_sold' => $totalProductsForDay ?? 0,
            ];
        }

        return $data;
    }


    public function getTopSellingProducts()
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }


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
            return TopSellingProductResource::collection($topSellingProducts);


        } catch (\Exception $e) {
            return $this->sendError('Error fetching top-selling products.', [$e->getMessage()]);
        }
    }

    public function getProductLimitCounts()
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }


            // Ensure the authenticated user exists and has a merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get merchant ID from authenticated user's merchant relation
            $merchantID = $authUser->merchant->id;

            // Count products where any inventory's quantity is less than or equal to the alarm_limit
            $alarmLimitCount = Product::whereHas('inventories', function ($query) {
                $query->whereColumn('quantity', '<=', 'alarm_limit')->where('type', 'shop');
            })->where('merchant_id', $merchantID)->count();

            // Count products where any inventory's quantity is less than or equal to the stock_limit
            $stockLimitCount = Product::whereHas('inventories', function ($query) {
                $query->whereColumn('quantity', '<=', 'stock_limit')->where('type', 'shop');
            })->where('merchant_id', $merchantID)->count();

            // Return the response with both counts
            return [
                'alarm_limit_count' => $alarmLimitCount,
                'stock_limit_count' => $stockLimitCount
            ];

        } catch (\Exception $e) {
            return $this->sendError('Error fetching product limit counts.', [$e->getMessage()]);
        }
    }


    // Function to get products based on alarm limit
    public function getProductsByAlarmLimit()
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }


            // Ensure the authenticated user exists and has a merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get merchant ID from authenticated user's merchant relation
            $merchantID = $authUser->merchant->id;
            // Get products with their inventories where quantity is less than or equal to alarm limit
            $products = Product::whereHas('inventories', function ($query) {
                $query->whereColumn('quantity', '<=', 'alarm_limit')->where('type', 'shop');
            })
                ->with(['inventories' => function ($query) {
                    $query->select('id', 'product_id', 'type', 'quantity'); // Select relevant fields
                }])
                ->where('merchant_id', $merchantID)
                ->get(['id', 'product_name']); // Select only the necessary fields from Product

            // Transform the products to include shop and stock quantities
            $result = $products->map(function ($product) {
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
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }


            // Ensure the authenticated user exists and has a merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get merchant ID from authenticated user's merchant relation
            $merchantID = $authUser->merchant->id;

            // Get products with their inventories where quantity is less than or equal to stock limit
            $products = Product::whereHas('inventories', function ($query) {
                $query->whereColumn('quantity', '<=', 'stock_limit')->where('type', 'shop');
            })
                ->with(['inventories' => function ($query) {
                    $query->select('id', 'product_id', 'type', 'quantity'); // Select relevant fields
                }])->where('merchant_id', $merchantID)
                ->get(['id', 'product_name']); // Select only the necessary fields from Product

            // Transform the products to include shop and stock quantities
            $result = $products->map(function ($product) {
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

    public function getTransactionForSilver()
    {
        try {
            // Get the authenticated merchant
            $authUser = auth()->user();

            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchant = $authUser->merchant;
            $merchantID = $merchant->id;

            // Fetch transaction where order_id is null
            $transactions = Transaction::with('order')->where('merchant_id', $merchantID)
//                ->whereNull('order_id') // Only fetch invoices without an order
                ->orderBy('id', 'desc') // Order by creation date descending
                ->limit(5) // Limit to 5 latest invoices
                ->get();

//            dd($transactions);
            // Format the response
            $transactionData = $transactions->map(function ($transaction) {
                return [
                    'invoice_id' => $transaction->id,
                    'order_id' => $transaction->order->id ?? null, // No order details
                    'name' => $transaction->phone_number, // Assuming mobile number stored in the invoice
                    'order_date' => dateInsert($transaction->created_at), // Use invoice creation date
                    'invoice_amount' => convertShillingToUSD($transaction->transaction_amount), // Use invoice amount
                    'name_initial' => 'N/A' // Since no order details exist, initials not applicable
                ];
            });

            return $this->sendResponse($transactionData, 'transaction without orders fetched successfully.');
        } catch (\Exception $e) {
            return $this->sendError('An error occurred while fetching transaction.', ['error' => $e->getMessage()]);
        }
    }


    public function getInvoicesWithOrders()
    {
        try {
            // Get the authenticated merchant ID
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchantID = $authUser->merchant->id;

            // Fetch invoices with their associated orders
            $invoices = Invoice::with('order')
                ->where('merchant_id', $merchantID)
                ->where('type', 'POS')
//                ->whereNotNull('order_id') // Only fetch invoices with an order
                ->orderBy('created_at', 'desc') // Order by creation date descending
                ->limit(5) // Limit to 5 latest invoices
                ->get();

            // Format the response
            $invoiceData = $invoices->map(function ($invoice) {
                $order = $invoice->order ?? '';
                return [
                    'invoice_id' => $invoice->id,
                    'order_id' => $order->id ?? null,
                    'name' => $order->name ? $order->name : $invoice->mobile_number,
                    'order_date' => $order ? dateInsert($order->created_at) : 'N/A',
                    'invoice_amount' => convertShillingToUSD($order->total_price),
                    'name_initial' => $this->getInitials($order ? ($order->name) : 'N/A')
                ];
            });

            return $this->sendResponse($invoiceData, 'Invoices with orders fetched successfully.');
        } catch (\Exception $e) {
            return $this->sendError('An error occurred while fetching invoices.', ['error' => $e->getMessage()]);
        }
    }

    public function getPendingOrders()
    {
        try {
            // Get the authenticated merchant ID
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchantID = $authUser->merchant->id;

            // Fetch pending orders for the authenticated merchant
            $pendingOrders = Order::where('merchant_id', $merchantID)
                ->where('order_status', 'Pending')
                ->orderBy('created_at', 'desc') // Order by creation date descending
                ->limit(5) // Limit to 5 latest pending orders
                ->get();

            // Format the response
            $orderData = $pendingOrders->map(function ($order) {
                return [
                    'order_id' => $order->id,
                    'name' => $order->name ?? $order->mobile_number,
                    'order_date' => dateInsert($order->created_at),
                    'invoice_amount' => convertShillingToUSD($order->total_price ?? 0),
                    'invoice_amount_in_usd' => convertShillingToUSD($order->total_price ?? 0),
                    'name_initial' => $this->getInitials($order->name ?? 'N A')
                ];
            });

            return $this->sendResponse($orderData, 'Pending orders fetched successfully.');
        } catch (\Exception $e) {
            return $this->sendError('An error occurred while fetching pending orders.', ['error' => $e->getMessage()]);
        }
    }

    public function getLatestClients()
    {
        try {
            // Get the authenticated merchant ID
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchantID = $authUser->merchant->id;

            // Fetch the latest clients based on orders for the authenticated merchant
            $latestClients = Order::where('merchant_id', $merchantID)
                ->orderBy('created_at', 'desc') // Order by creation date descending
                ->limit(5) // Limit to 5 latest clients
                ->get();

            // Format the response
            $clientData = $latestClients->map(function ($order) {
                return [
                    'name' => $order->name ?? $order->mobile_number,
                    'name_initial' => $this->getInitials($order->name ?? 'N A')
                ];
            });

            return $this->sendResponse($clientData, 'Latest clients fetched successfully.');
        } catch (\Exception $e) {
            return $this->sendError('An error occurred while fetching latest clients.', ['error' => $e->getMessage()]);
        }
    }

    public function getLatestClientsForNormalMerchant()
    {
        try {
            // Get the authenticated merchant
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchantID = $authUser->merchant->id;

            // Fetch the latest clients based on invoices having an order_id for the authenticated merchant
            $latestClients = Order::whereHas('invoice', function ($query) {
                $query->whereNotNull('order_id');
            })
                ->where('merchant_id', $merchantID)
                ->orderBy('id', 'desc') // Order by creation date descending
                ->limit(5) // Limit to 5 latest clients
                ->get();

//            dd($latestClients);
            // Format the response
            $clientData = $latestClients->map(function ($order) {
                return [
                    'name' => $order->name ?? $order->invoice->mobile_number ?? 'N/A',
                    'order_id' => $order->id ??  null,
                    'name_initial' => $this->getInitials($order->name ?? 'Not Available')
                ];
            });

            return $this->sendResponse($clientData, 'Latest clients for normal merchants fetched successfully.');
        } catch (\Exception $e) {
            return $this->sendError('An error occurred while fetching the latest clients for normal merchants.', ['error' => $e->getMessage()]);
        }
    }

    public function getLatestClientsForSilverMerchant()
    {
        try {
            // Get the authenticated merchant
            $authUser = auth()->user();

            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchantID = $authUser->merchant->id;

            // Fetch the latest invoices with status 'Paid' for the silver merchant
            $latestClients = Invoice::where('merchant_id', $merchantID)
                ->where('status', 'Paid') // Only 'Paid' invoices
                ->whereNull('order_id')
                ->orderBy('id', 'desc') // Order by invoice creation date
                ->limit(5) // Limit to 5 latest clients
                ->get();

            // Format the response
            $clientData = $latestClients->map(function ($invoice) {
                // Show first_name + last_name if present, otherwise mobile_number
                $name = $invoice->first_name && $invoice->last_name
                    ? $invoice->first_name . ' ' . $invoice->last_name
                    : $invoice->mobile_number;

                return [
                    'name' => $name,
                    'order_id' => null,
                    'name_initial' => $this->getInitials($name ?? 'Not Available')
                ];
            });

            return $this->sendResponse($clientData, 'Latest clients for silver merchants fetched successfully.');
        } catch (\Exception $e) {
            return $this->sendError('An error occurred while fetching the latest clients for silver merchants.', ['error' => $e->getMessage()]);
        }
    }


}
