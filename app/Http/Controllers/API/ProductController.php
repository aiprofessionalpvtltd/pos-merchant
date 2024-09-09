<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductResource;
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
            'category_name' => 'nullable|string',
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

            dd($request->all());
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
            return $this->sendError('Product not found.');
        }

        return $this->sendResponse(new ProductResource($product), 'Product retrieved successfully.');
    }

    public function searchBarcode($barcode)
    {
        $product = Product::with('category', 'inventories')->where('bar_code',$barcode)->first();

         if (is_null($product)) {
            return $this->sendError('Product not found.');
        }

        return $this->sendResponse(new ProductResource($product), 'Product retrieved successfully.');
    }

    public function update(Request $request, Product $product)
    {
        $validator = Validator::make($request->all(), [
            'product_name' => 'required|string|max:255',
            'category_id' => 'required|exists:categories,id',
            'price' => 'required|numeric',
            'stock_limit' => 'required|integer',
            'alarm_limit' => 'required|integer',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // Image validation
            'bar_code' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {
            $input = $request->all();

            // Handle the image upload if exists
            if ($request->hasFile('image')) {
                // Delete old image if exists
                if ($product->image && file_exists(public_path($product->image))) {
                    unlink(public_path($product->image));
                }

                $image = $request->file('image');
                $imageName = time() . '.' . $image->getClientOriginalExtension();
                $image->move(public_path('images/products'), $imageName);
                $input['image'] = 'images/products/' . $imageName; // Store the image path
            }

            $product->update($input);
            DB::commit();

            return $this->sendResponse(new ProductResource($product), 'Product updated successfully.');
        } catch (\Exception $e) {
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

            $products = Product::with(['category' , 'inventories', 'merchant'])->where('merchant_id', $merchantID)->get();
            if ($products->isEmpty()) {
                return $this->sendResponse([],'No products found.');
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

}
