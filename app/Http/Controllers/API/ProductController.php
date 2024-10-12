<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductCatalogResource;
use App\Http\Resources\ProductResource;
use App\Http\Resources\TopSellingProductResource;
use App\Models\Category;
use App\Models\InventoryHistory;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductInventory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ProductController extends BaseController
{
    public function index()
    {
        // Retrieve products with their category and inventories relationships
        $products = Product::with('category', 'inventories')->get();

        // Loop through each product to calculate the quantities for stock, shop, and transportation
        foreach ($products as $product) {
            $product->in_stock_quantity = $product->inventories->where('type', 'stock')->sum('quantity');
            $product->in_shop_quantity = $product->inventories->where('type', 'shop')->sum('quantity');
            $product->in_transportation_quantity = $product->inventories->where('type', 'transportation')->sum('quantity');
        }

        // Return the response with the ProductResource collection, including the extra data
        return $this->sendResponse(ProductResource::collection($products), 'Products retrieved successfully.');
    }

    public function store(Request $request)
    {
        // Validation for both Product and Inventory fields
        $validator = Validator::make($request->all(), [
            'product_name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'price' => 'required|numeric',
            'vat' => 'required',
            'stock_limit' => 'required|integer',
            'alarm_limit' => 'required|integer',
            'image' => 'required|image|mimes:jpeg,png,jpg,gif', // Image validation
            'bar_code' => 'nullable|string|max:255',
            'quantity' => 'required|integer', // Inventory quantity
            'type' => 'required|in:shop,stock' // Inventory type: shop or stock
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Handle the product image upload
            $input = $request->all();
            if ($request->hasFile('image')) {
                $input['image'] = $request->file('image')->store('products', 'public');
            }


            // Check if the product already exists by product_name and category_id
            $product = Product::where('bar_code', $request->input('bar_code'))
                ->where('category_id', $request->input('category_id'))
                ->first();


            $input['price'] = $request->price;
            $input['vat'] = $request->vat * 100; // Assume this is 0, 5, or 10 as per your formula

            // Calculate final price with VAT
            $input['total_price'] = $request->price + ($request->price * $request->vat);


            if ($product) {
                // If the product exists, update it
                $product->update($input);
            } else {
                // If the product does not exist, create a new one
                $input['merchant_id'] = $merchantID;
                $product = Product::create($input);
            }

            // Check if there's an existing inventory by product_id and type
            $existingInventory = ProductInventory::where('product_id', $product->id)
                ->where('type', $request->input('type'))
                ->first();

            // Create a new inventory record
            $inventoryData = [
                'product_id' => $product->id,
                'quantity' => $request->input('quantity'),
                'type' => $request->input('type'),
            ];

            if ($existingInventory) {
                // Update the existing inventory
                $existingInventory->quantity += $request->input('quantity'); // Increment quantity
                $existingInventory->save();
            } else {

                ProductInventory::create($inventoryData);
            }

            // add entry to inventory history
            InventoryHistory::create($inventoryData);

            // Load the relationships
            $product->load(['category', 'inventories', 'merchant']);

            DB::commit();

            return $this->sendResponse(new ProductResource($product), 'Product and Inventory created or updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Product creation or update failed.', [$e->getMessage()]);
        }
    }

    public function show($id)
    {
        // Get authenticated user
        $authUser = auth()->user();

        if ($authUser->user_type == 'employee') {
            $authUser->merchant = $authUser->employee->merchant;
        }

        // Ensure the authenticated user has a merchant relation
        if (!$authUser || !$authUser->merchant) {
            return $this->sendError('Merchant not found for the authenticated user.');
        }

        // Get the merchant's ID
        $merchantID = $authUser->merchant->id;

        $product = Product::with('category', 'inventories')->where('merchant_id', $merchantID)->find($id);

        if (is_null($product)) {
            return $this->sendError('Single Product not found.');
        }

        return $this->sendResponse(new ProductResource($product), 'Product retrieved successfully.');
    }

    public function showByType($id, $type)
    {
        // Get authenticated user
        $authUser = auth()->user();

        if ($authUser->user_type == 'employee') {
            $authUser->merchant = $authUser->employee->merchant;
        }

        // Ensure the authenticated user has a merchant relation
        if (!$authUser || !$authUser->merchant) {
            return $this->sendError('Merchant not found for the authenticated user.');
        }

        // Get the merchant's ID
        $merchantID = $authUser->merchant->id;

        // Validate the type input (must be either 'stock', 'shop', or 'transportation')
        if (!in_array($type, ['stock', 'shop', 'transportation'])) {
            return $this->sendError('Invalid type provided. It must be either "stock", "shop", or "transportation".');
        }

        // Find the product with the category and inventories relationship
        $product = Product::with(['category', 'inventories' => function ($query) use ($type) {
            // Filter the inventories based on the provided type
            $query->where('type', $type);
        }])->where('merchant_id', $merchantID)->find($id);

        // Check if the product exists and has inventories of the specified type
        if (is_null($product) || $product->inventories->isEmpty()) {
            return $this->sendError('Product not found or no inventories for the specified type.');
        }

        // Return the product with the filtered inventories
        return $this->sendResponse(new ProductResource($product), 'Product retrieved successfully.');
    }

    public function searchBarcodeWithType($barcode, $type)
    {
        // Get authenticated user
        $authUser = auth()->user();

        if ($authUser->user_type == 'employee') {
            $authUser->merchant = $authUser->employee->merchant;
        }

        // Ensure the authenticated user has a merchant relation
        if (!$authUser || !$authUser->merchant) {
            return $this->sendError('Merchant not found for the authenticated user.');
        }

        // Get the merchant's ID
        $merchantID = $authUser->merchant->id;

        $product = Product::with(['category', 'inventories' => function ($query) use ($type) {
            // Filter the inventories based on the provided type
            $query->where('type', $type);
        }])->where('merchant_id', $merchantID)
            ->where('bar_code', $barcode)->first();

        if (is_null($product)) {
            return $this->sendError('Product not found on bar code.');
        }

        // Calculate instock and in shop quantities
        $product->in_stock_quantity = $product->inventories->where('type', 'stock')->sum('quantity');
        $product->in_shop_quantity = $product->inventories->where('type', 'shop')->sum('quantity');
        $product->in_transportation_quantity = $product->inventories->where('type', 'transportation')->sum('quantity');

        return $this->sendResponse(new ProductResource($product), 'Product retrieved successfully.');
    }

    public function searchBarcode($barcode)
    {
        // Get authenticated user
        $authUser = auth()->user();

        if ($authUser->user_type == 'employee') {
            $authUser->merchant = $authUser->employee->merchant;
        }

        // Ensure the authenticated user has a merchant relation
        if (!$authUser || !$authUser->merchant) {
            return $this->sendError('Merchant not found for the authenticated user.');
        }

        // Get the merchant's ID
        $merchantID = $authUser->merchant->id;

        $product = Product::with('category', 'inventories')
            ->where('merchant_id', $merchantID)
            ->where('bar_code', $barcode)->first();

        if (is_null($product)) {
            return $this->sendError('Product not found on bar code.');
        }

        // Calculate instock and in shop quantities
        $product->in_stock_quantity = $product->inventories->where('type', 'stock')->sum('quantity');
        $product->in_shop_quantity = $product->inventories->where('type', 'shop')->sum('quantity');
        $product->in_transportation_quantity = $product->inventories->where('type', 'transportation')->sum('quantity');

        return $this->sendResponse(new ProductResource($product), 'Product retrieved successfully.');
    }

    public function update(Request $request, $id)
    {
        // Validate the incoming request data
        $validator = Validator::make($request->all(), [
            'product_name' => 'nullable|string|max:255',
            'price' => 'nullable|numeric',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif', // Optional image validation
        ]);

        // Return validation errors if the validation fails
        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        // Begin a database transaction
        DB::beginTransaction();

        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Find the product by ID
            $product = Product::where('merchant_id', $merchantID)->find($id);

            if (!$product) {
                return $this->sendError('Product not found.', ['Product not found with ID ' . $id]);
            }

            // Prepare an array to store the updated fields
            $input = [];

            // Check if 'product_name' is provided, then update
            if ($request->filled('product_name')) {
                $input['product_name'] = $request->product_name;
            }

            // Check if 'price' is provided, then update
            if ($request->filled('price')) {
                $input['price'] = $request->price;
                $input['vat'] = $product->vat; // Assume this is 0, 5, or 10 as per your formula

                // Calculate final price with VAT
                $input['total_price'] = $request->price + ($request->price * $product->vat / 100);
            }


            // Check if a new image has been uploaded
            if ($request->hasFile('image')) {
                // Remove the existing image if it exists
                if ($product->image && file_exists(public_path($product->image))) {
                    unlink(public_path($product->image));
                }

                // Store the new image
                $image = $request->file('image');
                $input['image'] = $image->store('products', 'public');
            }

            // Update the product only with the fields that are provided
            if (!empty($input)) {
                $product->update($input);
            }

            // Retrieve the updated product data
            $productData = Product::where('merchant_id', $merchantID)->find($product->id);

            // Commit the transaction
            DB::commit();

            // Return a success response with the updated product
            return $this->sendResponse(new ProductResource($productData), 'Product updated successfully.');
        } catch (\Exception $e) {
            // Rollback the transaction on error
            DB::rollBack();
            return $this->sendError('Product update failed.', [$e->getMessage()]);
        }
    }

    public function destroy(Product $product)
    {
        try {
            DB::beginTransaction();
            $product->delete();
            DB::commit();
            return $this->sendResponse([], 'Product deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Product deletion failed.', [$e->getMessage()]);
        }
    }

    public function getByMerchant()
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }


            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            $products = Product::with(['category', 'inventories', 'merchant'])
                ->where('merchant_id', $merchantID)->get();

            if ($products->isEmpty()) {
                return $this->sendResponse([], 'No products found.');
            }

            // Prepare the products with the additional data
            $productsData = $products->map(function ($product) {
                // Calculate instock and in shop quantities
                $inStockQuantity = $product->inventories->where('type', 'stock')->sum('quantity');
                $inShopQuantity = $product->inventories->where('type', 'shop')->sum('quantity');
                $inTransportationQuantity = $product->inventories->where('type', 'transportation')->sum('quantity');

                // Return the product data with additional fields
                return [
                    'id' => $product->id,
                    'product_name' => $product->product_name,
                    'price' => $product->price,
                    'price_in_usd' => convertShillingToUSD($product->price),
                    'vat' => convertVATPercentagetoDecimal($product->vat),
                    'total_price' => $product->total_price,
                    'total_price_in_usd' => convertShillingToUSD($product->total_price),
                    'in_stock_quantity' => $inStockQuantity,
                    'in_shop_quantity' => $inShopQuantity,
                    'in_transportation_quantity' => $inTransportationQuantity,
                    'category' => new CategoryResource($product->category), // Assuming you have CategoryResource
                ];
            });

            return $this->sendResponse($productsData, 'Products retrieved successfully.');

        } catch (\Exception $e) {
            return $this->sendError('Error fetching products.', $e->getMessage());
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

            // Calculate percentages
            $shopPercentage = $overallTotal > 0
                ? ($totalProductsInShop / $overallTotal) * 100
                : 0;

            $stockPercentage = $overallTotal > 0
                ? ($totalProductsInStock / $overallTotal) * 100
                : 0;

            // Prepare response data
            $data = [
                'total_products_in_shop' => $totalProductsInShop,
                'total_products_in_stock' => $totalProductsInStock,
                'overall_total' => $overallTotal,
                'shop_percentage' => round($shopPercentage, 2) . '%',
                'stock_percentage' => round($stockPercentage, 2) . '%',
            ];

            // Return success response with the statistics
            return $this->sendResponse($data, 'Overall product statistics retrieved successfully.');

        } catch (\Exception $e) {
            return $this->sendError('Error fetching overall product statistics.', [$e->getMessage()]);
        }
    }

    public function getProductsByCategory($category_id)
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Validate if the category exists
            $category = Category::where('merchant_id', $merchantID)->find($category_id);

            if (!$category) {
                return $this->sendError('Category not found.');
            }

            // Get products based on category
            $products = Product::where('category_id', $category_id)
                ->with(['category', 'inventories'])
                ->get();

            if ($products->isEmpty()) {
                return $this->sendResponse([], 'No products found in this category.');
            }


            // Return response with product data
            return $this->sendResponse(ProductResource::collection($products), 'Products retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error fetching products.', [$e->getMessage()]);
        }
    }

    public function getAllProductsWithCategories(Request $request)
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

            // Get start and end dates from the request query parameters (optional)
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            // Validate the date format using Carbon
            if ($startDate) {
                $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
            }

            if ($endDate) {
                $endDate = \Carbon\Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
            }

             // Get all products with their categories, images, and order items
            $products = Product::with(['category', 'orderItems', 'inventories' => function ($inventoryQuery) use ($startDate, $endDate) {
                // Filter inventories for 'inventories' type
                // Apply date range filter if both dates are provided
                if ($startDate && $endDate) {
                    $inventoryQuery->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate]);
                }
            }])
                ->where('merchant_id', $merchantID)
                ->get();


            // Use the resource collection to transform the products
            return $this->sendResponse(ProductCatalogResource::collection($products), 'All products with categories retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error fetching products with categories.', [$e->getMessage()]);
        }
    }

    public function getSoldProducts(Request $request)
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

            // Get start and end dates from the request query parameters (optional)
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            // If only start date is provided, set end date to today's date
            if ($startDate && !$endDate) {
                $endDate = \Carbon\Carbon::now()->endOfDay();
            }


            // If both start and end dates are provided, validate the format using Carbon
            if ($startDate && $endDate) {
                $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
                $endDate = \Carbon\Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
            }else {
                // Set start and end dates to the current month's start and end
                $startDate = \Carbon\Carbon::now()->startOfMonth()->startOfDay();
                $endDate = \Carbon\Carbon::now()->endOfMonth()->endOfDay();
            }


            // Fetch sold products grouped by product_id and sold date (without time)
            $soldProducts = OrderItem::with(['product' => function ($query) use ($merchantID) {
                // Load the products along with their inventories
                $query->where('merchant_id', $merchantID)
                    ->with(['category', 'inventories' => function ($inventoryQuery) {
                        // Filter inventories for 'shop' and 'stock' types
                        $inventoryQuery->whereIn('type', ['shop', 'stock']);
                    }]);
            }])
                ->select('product_id', DB::raw('DATE(created_at) as sold_date'), DB::raw('SUM(quantity) as total_sold'))
                ->groupBy('product_id', DB::raw('DATE(created_at)'))
                ->orderBy('sold_date', 'desc');

            // Apply date range filter only if both dates are present
            if ($startDate && $endDate) {
                $soldProducts->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate]);
            }

            // Execute the query
            $soldProducts = $soldProducts->get();

            // Prepare the result set as a nested array grouped by sold date
            $data = [];
            foreach ($soldProducts as $soldProduct) {
                $product = $soldProduct->product;

                if (!$product) {
                    continue; // Skip if product is not found
                }

                // Find in-shop and in-stock quantities
                $inShopQuantity = $product->inventories
                        ->firstWhere('type', 'shop')->quantity ?? 0;
                $inStockQuantity = $product->inventories
                        ->firstWhere('type', 'stock')->quantity ?? 0;

                // Add product details to the nested structure
                $data[] = [
                    'product_id' => $product->id,
                    'product_name' => $product->product_name,
                    'category_name' => $product->category->name ?? 'Uncategorized',
                    'category_id' => $product->category->id ?? null,
                    'price' => convertShillingToUSD($product->total_price * $soldProduct->total_sold), // Multiply price by total sold
                    'in_shop_quantity' => $inShopQuantity,
                    'in_stock_quantity' => $inStockQuantity,
                    'total_sold' => $soldProduct->total_sold,
                    'sold_date' => $soldProduct->sold_date, // Grouped sold date
                ];
            }

            // Return success response with the data
            return $this->sendResponse(['start_date' => $startDate  ,'end_date' => $endDate , 'result' =>$data], 'Sold product listings retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving sold product listings.', [$e->getMessage()]);
        }
    }

    public function getTotalProductsInShop(Request $request)
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

            // Get start and end dates from the request query parameters (optional)
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            // If both start and end dates are provided, validate the format using Carbon
            if ($startDate && $endDate) {
                $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
                $endDate = \Carbon\Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
            }else {
                // Set start and end dates to the current month's start and end
                $startDate = \Carbon\Carbon::now()->startOfMonth()->startOfDay();
                $endDate = \Carbon\Carbon::now()->endOfMonth()->endOfDay();
            }

            // Fetch products available in shop for the merchant, filtering by inventories created_at date
            $productsInShop = Product::with(['category', 'inventories' => function ($inventoryQuery) use ($startDate, $endDate) {
                // Filter inventories for 'shop' type and apply date range filter on 'created_at'
                $inventoryQuery->where('type', 'shop');
                // Apply date range filter if both dates are provided
                if ($startDate && $endDate) {
                    $inventoryQuery->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate]);
                }
            }])
                ->where('merchant_id', $merchantID)
                ->get();

            // Prepare the result set, filtering out products with zero in-shop quantities if needed
            $data = $productsInShop->filter(function ($product) {
                // Check in-shop quantities
                $inShopQuantity = $product->inventories->sum('quantity');
                // Filter out products with zero shop quantities
                return $inShopQuantity > 0;
            })->map(function ($product) {
                // Calculate in-shop quantities
                $inShopQuantity = $product->inventories->sum('quantity');

                return [
                    'product_name' => $product->product_name,
                    'category_name' => $product->category->name ?? 'Uncategorized',
                    'category_id' => $product->category->id ?? null,
                    'price' => $product->price,
                    'price_in_usd' => convertShillingToUSD($product->price),
                    'vat' => convertVATPercentagetoDecimal($product->vat),
                    'total_price' => $product->total_price,
                    'total_price_in_usd' => convertShillingToUSD($product->total_price),
                    'in_shop_quantity' => $inShopQuantity,
                ];
            });

            // Return success response with the data
            return $this->sendResponse(['start_date' => $startDate  ,'end_date' => $endDate , 'result' =>$data->values()], 'Total products in shop retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving total products in shop.', [$e->getMessage()]);
        }
    }

    public function getTotalProductsInStock(Request $request)
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

            // Get start and end dates from the request query parameters (optional)
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            // Initialize date filtering logic
            if ($startDate && $endDate) {
                // If both start and end dates are provided, validate and use them
                $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
                $endDate = \Carbon\Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
            }else {
                // Set start and end dates to the current month's start and end
                $startDate = \Carbon\Carbon::now()->startOfMonth()->startOfDay();
                $endDate = \Carbon\Carbon::now()->endOfMonth()->endOfDay();
            }

            // Fetch products available in stock for the merchant
            $productsInStock = Product::with(['category', 'inventories' => function ($inventoryQuery) use ($startDate, $endDate) {
                // Filter inventories for 'stock' type
                $inventoryQuery->where('type', 'stock');
                // Apply date range filter if both dates are provided
                if ($startDate && $endDate) {
                    $inventoryQuery->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate]);
                }
            }])
                ->where('merchant_id', $merchantID)
                ->get();

            // Prepare the result set, filtering out products with zero stock
            $data = $productsInStock->filter(function ($product) {
                // Check in-stock quantities
                $inStockQuantity = $product->inventories->sum('quantity');
                // Filter out products with zero stock
                return $inStockQuantity > 0;
            })->map(function ($product) {
                // Calculate in-stock quantities
                $inStockQuantity = $product->inventories->sum('quantity');

                return [
                    'product_name' => $product->product_name,
                    'category_name' => $product->category->name ?? 'Uncategorized',
                    'category_id' => $product->category->id ?? null,
                    'price' => $product->price,
                    'price_in_usd' => convertShillingToUSD($product->price),
                    'vat' => convertVATPercentagetoDecimal($product->vat),
                    'total_price' => $product->total_price,
                    'total_price_in_usd' => convertShillingToUSD($product->total_price),
                    'in_stock_quantity' => $inStockQuantity,
                ];
            });

            // Return success response with the data
            return $this->sendResponse(['start_date' => $startDate  ,'end_date' => $endDate , 'result' =>$data->values()], 'Total products in stock retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving total products in stock.', [$e->getMessage()]);
        }
    }

    public function getNewShopProductsListing(Request $request)
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

            // Get start and end dates from the request query parameters (optional)
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            // Initialize date filtering logic
            if ($startDate && $endDate) {
                // If both start and end dates are provided, validate and use them
                $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
                $endDate = \Carbon\Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
            } else {
                // Calculate the date 7 days ago if no specific date range is provided
                $sevenDaysAgo = now()->subDays(7);
                $startDate = $sevenDaysAgo->startOfDay(); // Start 7 days ago
                $endDate = now()->endOfDay(); // Up until today
            }

            // Fetch new products added within the specified date range, grouped by product
            $newProducts = ProductInventory::whereHas('product', function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            })
                ->whereBetween('created_at', [$startDate, $endDate]) // Apply date range filter
                ->where('type', 'shop')
                ->select('product_id', DB::raw('SUM(quantity) as total_quantity'))
                ->groupBy('product_id')
                ->with(['product.category']) // Eager load category
                ->get();

            // Prepare the result set
            $data = $newProducts->map(function ($inventory) {
                $product = $inventory->product;

                return [
                    'product_name' => $product->product_name,
                    'category_name' => $product->category->name ?? 'Uncategorized',
                    'category_id' => $product->category->id ?? null,
                    'price' => $product->price,
                    'price_in_usd' => convertShillingToUSD($product->price),
                    'vat' => convertVATPercentagetoDecimal($product->vat),
                    'total_price' => $product->total_price,
                    'total_price_in_usd' => convertShillingToUSD($product->total_price),
                    'in_shop_quantity' => $inventory->total_quantity,
                ];
            });

            // Return success response with the data
            return $this->sendResponse(['start_date' => $startDate  ,'end_date' => $endDate , 'result' =>$data], 'New products listing retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving new products listing.', [$e->getMessage()]);
        }
    }

    public function getNewStockProductsListing(Request $request)
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

            // Get start and end dates from the request query parameters (optional)
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            // Initialize date filtering logic
            if ($startDate && $endDate) {
                // If both start and end dates are provided, validate and use them
                $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
                $endDate = \Carbon\Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
            } else {
                // Calculate the date 7 days ago if no specific date range is provided
                $sevenDaysAgo = now()->subDays(7);
                $startDate = $sevenDaysAgo->startOfDay(); // Start 7 days ago
                $endDate = now()->endOfDay(); // Up until today
            }

            // Fetch new products added within the specified date range, grouped by product
            $newProducts = ProductInventory::whereHas('product', function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            })
                ->whereBetween('created_at', [$startDate, $endDate]) // Apply date range filter
                ->where('type', 'stock')
                ->select('product_id', DB::raw('SUM(quantity) as total_quantity'))
                ->groupBy('product_id')
                ->with(['product.category']) // Eager load category
                ->get();

            // Prepare the result set
            $data = $newProducts->map(function ($inventory) {
                $product = $inventory->product;

                return [
                    'product_name' => $product->product_name,
                    'category_name' => $product->category->name ?? 'Uncategorized',
                    'category_id' => $product->category->id ?? null,
                    'price' => $product->price,
                    'price_in_usd' => convertShillingToUSD($product->price),
                    'vat' => convertVATPercentagetoDecimal($product->vat),
                    'total_price' => $product->total_price,
                    'total_price_in_usd' => convertShillingToUSD($product->total_price),
                    'in_stock_quantity' => $inventory->total_quantity,
                ];
            });

            // Return success response with the data
            return $this->sendResponse(['start_date' => $startDate  ,'end_date' => $endDate , 'result' =>$data], 'New products listing retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving new products listing.', [$e->getMessage()]);
        }
    }

    public function getInventoryReport(Request $request)
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            // Check if the user is an employee and fetch the associated merchant
            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user exists and has a merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get merchant ID from authenticated user's merchant relation
            $merchantID = $authUser->merchant->id;

            // Get start and end dates from the request query parameters (optional)
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            // Validate the date format using Carbon
            if ($startDate) {
                $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
            }

            if ($endDate) {
                $endDate = \Carbon\Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
            }

            // Fetch inventory inventories for the merchant based on the product's merchant_id
            $inventoryReport = InventoryHistory::with(['product' => function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            }])
                ->when($startDate, function ($query) use ($startDate) {
                    return $query->where('created_at', '>=', $startDate);
                })
                ->when($endDate, function ($query) use ($endDate) {
                    return $query->where('created_at', '<=', $endDate);
                })
                ->get()
                ->filter(function ($inventory) {
                    return $inventory->product; // Keep only inventories that have associated products
                });

            // Prepare the result set
            $reportData = $inventoryReport->map(function ($inventory) {
                return [
                    'date' => $inventory->created_at->format('Y-m-d'),
                    'product_name' => $inventory->product->product_name ?? 'Unknown Product',
                    'quantity' => $inventory->quantity,
                    'instock' => $inventory->type == 'stock' ? $inventory->quantity : 0,
                    'inshop' => $inventory->type == 'shop' ? $inventory->quantity : 0,
                ];
            });

            // Return success response with the report data
            return $this->sendResponse($reportData, 'Inventory report retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving inventory report.', [$e->getMessage()]);
        }
    }

    public function getInventorySummary(Request $request)
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            // Check if the user is an employee and fetch the associated merchant
            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user exists and has a merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get merchant ID from authenticated user's merchant relation
            $merchantID = $authUser->merchant->id;

            // Get start and end dates from the request query parameters (optional)
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            // Validate the date format using Carbon
            if ($startDate) {
                $startDate = \Carbon\Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
            }

            if ($endDate) {
                $endDate = \Carbon\Carbon::createFromFormat('Y-m-d', $endDate)->endOfDay();
            }

            // Fetch inventory inventories for the merchant based on product's merchant_id
            $inventorySummary = InventoryHistory::with(['product' => function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            }])
                ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                    return $query->where('created_at', '>=', $startDate)
                        ->where('created_at', '<=', $endDate);
                })
                ->get();

            // Prepare the summary report
            $summaryData = $inventorySummary->groupBy('type')->map(function ($items, $type) use ($startDate, $endDate) {
                return $items->map(function ($inventory) use ($startDate, $endDate) {
                    // Calculate total sold within the specified date range
                    $totalSold = $inventory->product->orderItems()
                        ->when($startDate && $endDate, function ($query) use ($startDate, $endDate) {
                            return $query->where('created_at', '>=', $startDate)
                                ->where('created_at', '<=', $endDate);
                        })
                        ->sum('quantity');

                    return [
                        'product_name' => $inventory->product->product_name ?? 'Unknown Product',
                        'inshop_quantity' => $inventory->type == 'shop' ? $inventory->quantity : 0,
                        'instock_quantity' => $inventory->type == 'stock' ? $inventory->quantity : 0,
                        'in_transportation' => $inventory->type == 'transportation' ? $inventory->quantity : 0,
                        'total_sold' => $totalSold, // Total sold within the specified date range
                    ];
                });
            });

            // Flatten the summary data for response
            $flattenedSummary = [];
            foreach ($summaryData as $type => $items) {
                foreach ($items as $item) {
                    $flattenedSummary[] = [
                        'type' => $type,
                        'product_name' => $item['product_name'],
                        'inshop_quantity' => $item['inshop_quantity'],
                        'instock_quantity' => $item['instock_quantity'],
                        'in_transportation' => $item['in_transportation'],
                        'total_sold' => $item['total_sold'],
                    ];
                }
            }

            // Return success response with the summary data
            return $this->sendResponse($flattenedSummary, 'Inventory summary retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving inventory summary.', [$e->getMessage()]);
        }
    }

    public function getCombinedInventoryReportAndSummary(Request $request)
    {
        try {
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');


            // Call the existing inventory report function
            $inventoryReport = $this->getInventoryReport($request)->getData(true);
            $inventoryReport = $inventoryReport['data'];

            // Call the existing inventory summary function
            $inventorySummary = $this->getInventorySummary($request)->getData(true);
            $inventorySummary = $inventorySummary['data'];

            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->user_type = $authUser->employee->role;
            }

            $downloadedBy = strtoupper($authUser->name . ' (' .  $authUser->user_type .')');

            // Prepare the combined response
            $combinedResponse = [
                'downloaded_by' => $downloadedBy, // Get the response data
                'date_range' => $startDate . ' - ' . $endDate, // Get the response data
                'inventory_report' => $inventoryReport, // Get the response data
                'inventory_summary' => $inventorySummary // Get the response data
            ];

            // Return the combined response
            return $this->sendResponse($combinedResponse, 'Combined inventory report and summary retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving combined inventory report and summary.', [$e->getMessage()]);
        }
    }



}
