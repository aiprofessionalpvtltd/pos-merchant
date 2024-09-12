<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Category;
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
        $products = Product::with('category', 'inventories')->get();
        return $this->sendResponse(ProductResource::collection($products), 'Products retrieved successfully.');
    }

    public function store(Request $request)
    {
        // Validation for both Product and Inventory fields
        $validator = Validator::make($request->all(), [
            'product_name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'price' => 'required|numeric',
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

            if ($existingInventory) {
                // Update the existing inventory
                $existingInventory->quantity += $request->input('quantity'); // Increment quantity
                $existingInventory->save();
            } else {
                // Create a new inventory record
                $inventoryData = [
                    'product_id' => $product->id,
                    'quantity' => $request->input('quantity'),
                    'type' => $request->input('type'),
                ];

                ProductInventory::create($inventoryData);
            }

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
        $product = Product::with('category', 'inventories')->find($id);

        if (is_null($product)) {
            return $this->sendError('Single Product not found.');
        }

        return $this->sendResponse(new ProductResource($product), 'Product retrieved successfully.');
    }

    public function showByType($id, $type)
    {
        // Validate the type input (must be either 'stock', 'shop', or 'transportation')
        if (!in_array($type, ['stock', 'shop', 'transportation'])) {
            return $this->sendError('Invalid type provided. It must be either "stock", "shop", or "transportation".');
        }

        // Find the product with the category and inventories relationship
        $product = Product::with(['category', 'inventories' => function ($query) use ($type) {
            // Filter the inventories based on the provided type
            $query->where('type', $type);
        }])->find($id);

        // Check if the product exists and has inventories of the specified type
        if (is_null($product) || $product->inventories->isEmpty()) {
            return $this->sendError('Product not found or no inventories for the specified type.');
        }

        // Return the product with the filtered inventories
        return $this->sendResponse(new ProductResource($product), 'Product retrieved successfully.');
    }


    public function searchBarcode($barcode)
    {
        $product = Product::with('category', 'inventories')->where('bar_code', $barcode)->first();

        if (is_null($product)) {
            return $this->sendError('Product not found on bar code.');
        }

        // Calculate instock and in shop quantities
        $product->in_stock_quantity = $product->inventories->where('type', 'stock')->sum('quantity');
        $product->in_shop_quantity = $product->inventories->where('type', 'shop')->sum('quantity');

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
            // Find the product by ID
            $product = Product::find($id);

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
            $productData = Product::find($product->id);

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

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            $products = Product::with(['category', 'inventories', 'merchant'])->where('merchant_id', $merchantID)->get();
            if ($products->isEmpty()) {
                return $this->sendResponse([], 'No products found.');
            }

            // Prepare the products with the additional data
            $productsData = $products->map(function ($product) {
                // Calculate instock and in shop quantities
                $inStockQuantity = $product->inventories->where('type', 'stock')->sum('quantity');
                $inShopQuantity = $product->inventories->where('type', 'shop')->sum('quantity');

                // Return the product data with additional fields
                return [
                    'id' => $product->id,
                    'product_name' => $product->product_name,
                    'price' => $product->price,
                    'in_stock_quantity' => $inStockQuantity,
                    'in_shop_quantity' => $inShopQuantity,
                    'category' => new CategoryResource($product->category), // Assuming you have CategoryResource
                ];
            });

            return $this->sendResponse($productsData, 'Products retrieved successfully.');

        } catch (Exception $e) {
            return $this->sendError('Error fetching products.', $e->getMessage());
        }
    }

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
            // Validate if the category exists
            $category = Category::find($category_id);

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


}
