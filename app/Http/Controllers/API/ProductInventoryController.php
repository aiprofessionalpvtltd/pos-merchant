<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\ProductInventoryResource;
use App\Http\Resources\ProductResource;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductInventory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ProductInventoryController extends BaseController
{
    public function index()
    {
        $inventories = ProductInventory::with('product')->get();
        return $this->sendResponse(ProductInventoryResource::collection($inventories), 'Product inventories retrieved successfully.');
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer',
            'type' => 'required|in:shop,stock',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            DB::beginTransaction();
            $inventory = ProductInventory::create($request->all());
            DB::commit();
            return $this->sendResponse(new ProductInventoryResource($inventory), 'Product inventory created successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Product inventory creation failed.', [$e->getMessage()]);
        }
    }

    public function show($id)
    {
        $inventory = ProductInventory::with('product')->find($id);

        if (is_null($inventory)) {
            return $this->sendError('Product inventory not found.');
        }

        return $this->sendResponse(new ProductInventoryResource($inventory), 'Product inventory retrieved successfully.');
    }

    public function update(Request $request, ProductInventory $inventory)
    {
        $validator = Validator::make($request->all(), [
            'quantity' => 'required|integer',
            'type' => 'required|in:shop,stock',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            DB::beginTransaction();
            $inventory->update($request->all());
            DB::commit();
            return $this->sendResponse(new ProductInventoryResource($inventory), 'Product inventory updated successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Product inventory update failed.', [$e->getMessage()]);
        }
    }

    public function destroy(ProductInventory $inventory)
    {
        try {
            DB::beginTransaction();
            $inventory->delete();
            DB::commit();
            return $this->sendResponse([], 'Product inventory deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Product inventory deletion failed.', [$e->getMessage()]);
        }
    }

    public function getProductsByType($type)
    {
        try {
            // Validate the type input (must be either 'stock' or 'shop')
            if (!in_array($type, ['stock', 'shop', 'transportation'])) {
                return $this->sendError('Invalid type provided. It must be either "stock" or "shop".');
            }

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

            // Retrieve products based on the type (stock or shop)
            $productInventories = ProductInventory::whereHas('product', function ($query) use ($merchantID) {
                $query->where('merchant_id', $merchantID);
            })->where('type', $type)->with('product')->get();

            // If no product inventories found
            if ($productInventories->isEmpty()) {
                return $this->sendResponse([], 'No products found for the specified type.');
            }

            // Map through the product inventories to structure the response
            $productsData = $productInventories->map(function ($inventory) {
                return [
                    'product_id' => $inventory->product->id,
                    'product_name' => $inventory->product->product_name,
                    'quantity' => $inventory->quantity,
                    'inventory_type' => $inventory->type,
                ];
            });

            // Return the product data
            return $this->sendResponse($productsData, 'Products retrieved successfully.');

        } catch (\Exception $e) {
            return $this->sendError('Error fetching products.', [$e->getMessage()]);
        }
    }

    public function getProductsByTypeWithCategory($categoryID, $type)
    {

        try {
            // Validate the type input (must be either 'stock' or 'shop')
            if (!in_array($type, ['stock', 'shop', 'transportation'])) {
                return $this->sendError('Invalid type provided. It must be either "stock" or "shop".');
            }

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

            // Retrieve products based on the type (stock or shop)
            $productInventories = ProductInventory::whereHas('product', function ($query) use ($merchantID, $categoryID) {
                $query->where('merchant_id', $merchantID)->where('category_id', $categoryID);
            })->where('type', $type)->with('product.category')->get();

            // If no product inventories found
            if ($productInventories->isEmpty()) {
                return $this->sendResponse([], 'No products found for the specified type.');
            }

            // Map through the product inventories to structure the response
            $productsData = $productInventories->map(function ($inventory) {
                return [
                    'product_id' => $inventory->product->id,
                    'price' => convertShillingToUSD($inventory->product->price),
                    'image' => Storage::url($inventory->product->image),
                    'category_id' => $inventory->product->category->id,
                    'category_name' => $inventory->product->category->name,
                    'product_name' => $inventory->product->product_name,
                    'quantity' => $inventory->quantity,
                    'inventory_type' => $inventory->type,
                ];
            });

            // Return the product data
            return $this->sendResponse($productsData, 'Products retrieved successfully.');

        } catch (\Exception $e) {
            return $this->sendError('Error fetching products.', [$e->getMessage()]);
        }
    }

    public function transferShopToStock(Request $request)
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation Error.', $validator->errors());
            }

            $productId = $request->input('product_id');
            $quantity = $request->input('quantity');

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
            $product = Product::where('merchant_id', $merchantID)->find($productId);

            if (!$product) {
                return $this->sendError('Product not found.', ['Product not found with ID ' . $productId]);
            }

            // Get shop inventory for the product
            $shopInventory = ProductInventory::where('product_id', $productId)
                ->where('type', 'shop')
                ->first();

            // Check if shop has enough quantity to transfer
            if (!$shopInventory || $shopInventory->quantity < $quantity) {
                return $this->sendError('Not enough quantity in shop to transfer.');
            }

            // Get stock inventory for the product (create if not exists)
            $stockInventory = ProductInventory::firstOrCreate(
                ['product_id' => $productId, 'type' => 'stock'],
                ['quantity' => 0] // Default quantity if stock entry doesn't exist
            );

            // Perform the transfer
            DB::beginTransaction();
            try {
                // Deduct quantity from shop
                $shopInventory->quantity -= $quantity;
                $shopInventory->save();

                // Add quantity to stock
                $stockInventory->quantity += $quantity;
                $stockInventory->save();

                DB::commit();

                return $this->sendResponse([], 'Transfer from shop to stock successful.');
            } catch (\Exception $e) {
                DB::rollBack();
                return $this->sendError('Error during transfer.', [$e->getMessage()]);
            }

        } catch (\Exception $e) {
            return $this->sendError('Error transferring product.', [$e->getMessage()]);
        }
    }

    public function transferStockToShop(Request $request)
    {
        try {
            // Validate request
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation Error.', $validator->errors());
            }

            $productId = $request->input('product_id');
            $quantity = $request->input('quantity');

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
            $product = Product::where('merchant_id', $merchantID)->find($productId);

            if (!$product) {
                return $this->sendError('Product not found.', ['Product not found with ID ' . $productId]);
            }

            // Get stock inventory for the product
            $stockInventory = ProductInventory::where('product_id', $productId)
                ->where('type', 'stock')
                ->first();

            // Check if stock has enough quantity to transfer
            if (!$stockInventory || $stockInventory->quantity < $quantity) {
                return $this->sendError('Not enough quantity in stock to transfer.');
            }

            // Get shop inventory for the product (create if not exists)
            $shopInventory = ProductInventory::firstOrCreate(
                ['product_id' => $productId, 'type' => 'shop'],
                ['quantity' => 0] // Default quantity if shop entry doesn't exist
            );

            // Perform the transfer
            DB::beginTransaction();
            try {
                // Deduct quantity from stock
                $stockInventory->quantity -= $quantity;
                $stockInventory->save();

                // Add quantity to shop
                $shopInventory->quantity += $quantity;
                $shopInventory->save();

                DB::commit();

                return $this->sendResponse([], 'Transfer from stock to shop successful.');
            } catch (\Exception $e) {
                DB::rollBack();
                return $this->sendError('Error during transfer.', [$e->getMessage()]);
            }

        } catch (\Exception $e) {
            return $this->sendError('Error transferring product.', [$e->getMessage()]);
        }
    }

    public function transferTransportationToShop(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation Error.', $validator->errors());
            }

            $productId = $request->input('product_id');
            $quantity = $request->input('quantity');
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
            $product = Product::where('merchant_id', $merchantID)->find($productId);

            if (!$product) {
                return $this->sendError('Product not found.', ['Product not found with ID ' . $productId]);
            }

            // Get transportation inventory for the product
            $transportationInventory = ProductInventory::where('product_id', $productId)
                ->where('type', 'transportation')
                ->first();

            if (!$transportationInventory || $transportationInventory->quantity < $quantity) {
                return $this->sendError('Not enough quantity in transportation to transfer.');
            }

            // Get shop inventory (create if not exists)
            $shopInventory = ProductInventory::firstOrCreate(
                ['product_id' => $productId, 'type' => 'shop'],
                ['quantity' => 0]
            );

            DB::beginTransaction();
            try {
                // Deduct from transportation
                $transportationInventory->quantity -= $quantity;
                $transportationInventory->save();

                // Add to shop
                $shopInventory->quantity += $quantity;
                $shopInventory->save();

                DB::commit();

                return $this->sendResponse([], 'Transfer from transportation to shop successful.');
            } catch (\Exception $e) {
                DB::rollBack();
                return $this->sendError('Error during transfer.', [$e->getMessage()]);
            }

        } catch (\Exception $e) {
            return $this->sendError('Error transferring product.', [$e->getMessage()]);
        }
    }

    public function transferTransportationToStock(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation Error.', $validator->errors());
            }

            $productId = $request->input('product_id');
            $quantity = $request->input('quantity');

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
            $product = Product::where('merchant_id', $merchantID)->find($productId);

            if (!$product) {
                return $this->sendError('Product not found.', ['Product not found with ID ' . $productId]);
            }

            // Get transportation inventory for the product
            $transportationInventory = ProductInventory::where('product_id', $productId)
                ->where('type', 'transportation')
                ->first();

            if (!$transportationInventory || $transportationInventory->quantity < $quantity) {
                return $this->sendError('Not enough quantity in transportation to transfer.');
            }

            // Get stock inventory (create if not exists)
            $stockInventory = ProductInventory::firstOrCreate(
                ['product_id' => $productId, 'type' => 'stock'],
                ['quantity' => 0]
            );

            DB::beginTransaction();
            try {
                // Deduct from transportation
                $transportationInventory->quantity -= $quantity;
                $transportationInventory->save();

                // Add to stock
                $stockInventory->quantity += $quantity;
                $stockInventory->save();

                DB::commit();

                return $this->sendResponse([], 'Transfer from transportation to stock successful.');
            } catch (\Exception $e) {
                DB::rollBack();
                return $this->sendError('Error during transfer.', [$e->getMessage()]);
            }

        } catch (\Exception $e) {
            return $this->sendError('Error transferring product.', [$e->getMessage()]);
        }
    }

    public function transferShopToTransportation(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation Error.', $validator->errors());
            }

            $productId = $request->input('product_id');
            $quantity = $request->input('quantity');

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
            $product = Product::where('merchant_id', $merchantID)->find($productId);

            if (!$product) {
                return $this->sendError('Product not found.', ['Product not found with ID ' . $productId]);
            }

            // Get shop inventory for the product
            $shopInventory = ProductInventory::where('product_id', $productId)
                ->where('type', 'shop')
                ->first();

            if (!$shopInventory || $shopInventory->quantity < $quantity) {
                return $this->sendError('Not enough quantity in shop to transfer.');
            }

            // Get transportation inventory (create if not exists)
            $transportationInventory = ProductInventory::firstOrCreate(
                ['product_id' => $productId, 'type' => 'transportation'],
                ['quantity' => 0]
            );

            DB::beginTransaction();
            try {
                // Deduct from shop
                $shopInventory->quantity -= $quantity;
                $shopInventory->save();

                // Add to transportation
                $transportationInventory->quantity += $quantity;
                $transportationInventory->save();

                DB::commit();

                return $this->sendResponse([], 'Transfer from shop to transportation successful.');
            } catch (\Exception $e) {
                DB::rollBack();
                return $this->sendError('Error during transfer.', [$e->getMessage()]);
            }

        } catch (\Exception $e) {
            return $this->sendError('Error transferring product.', [$e->getMessage()]);
        }
    }

    public function transferStockToTransportation(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|exists:products,id',
                'quantity' => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation Error.', $validator->errors());
            }

            $productId = $request->input('product_id');
            $quantity = $request->input('quantity');

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
            $product = Product::where('merchant_id', $merchantID)->find($productId);

            if (!$product) {
                return $this->sendError('Product not found.', ['Product not found with ID ' . $productId]);
            }

            // Get stock inventory for the product
            $stockInventory = ProductInventory::where('product_id', $productId)
                ->where('type', 'stock')
                ->first();

            if (!$stockInventory || $stockInventory->quantity < $quantity) {
                return $this->sendError('Not enough quantity in stock to transfer.');
            }

            // Get transportation inventory (create if not exists)
            $transportationInventory = ProductInventory::firstOrCreate(
                ['product_id' => $productId, 'type' => 'transportation'],
                ['quantity' => 0]
            );

            DB::beginTransaction();
            try {
                // Deduct from stock
                $stockInventory->quantity -= $quantity;
                $stockInventory->save();

                // Add to transportation
                $transportationInventory->quantity += $quantity;
                $transportationInventory->save();

                DB::commit();

                return $this->sendResponse([], 'Transfer from stock to transportation successful.');
            } catch (\Exception $e) {
                DB::rollBack();
                return $this->sendError('Error during transfer.', [$e->getMessage()]);
            }

        } catch (\Exception $e) {
            return $this->sendError('Error transferring product.', [$e->getMessage()]);
        }
    }

    public function updateInventory(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'shop_quantity' => 'required|integer|min:0',
            'stock_quantity' => 'required|integer|min:0',
        ]);

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

            // Find the product
            $product = Product::where('merchant_id', $merchantID)->find($request->product_id);

            if (!$product) {
                return $this->sendError('Product not found.', ['Product not found with ID ' . $id]);
            }

            // Check for existing inventories
            $shopInventory = $product->inventories()->firstWhere('type', 'shop');
            $stockInventory = $product->inventories()->firstWhere('type', 'stock');

            // Update or create shop inventory
            if ($shopInventory) {
                // Update existing shop inventory
                $shopInventory->quantity = $request->shop_quantity;
                $shopInventory->save();
            } else {
                // Create new shop inventory
                $product->inventories()->create([
                    'type' => 'shop',
                    'quantity' => $request->shop_quantity,
                ]);
            }

            // Update or create stock inventory
            if ($stockInventory) {
                // Update existing stock inventory
                $stockInventory->quantity = $request->stock_quantity;
                $stockInventory->save();
            } else {
                // Create new stock inventory
                $product->inventories()->create([
                    'type' => 'stock',
                    'quantity' => $request->stock_quantity,
                ]);
            }


            // Calculate instock and in shop quantities
            $product->in_stock_quantity = $product->inventories->where('type', 'stock')->sum('quantity');
            $product->in_shop_quantity = $product->inventories->where('type', 'shop')->sum('quantity');

            return $this->sendResponse(new ProductResource($product), 'Inventory updated or created successfully.');

        } catch (\Exception $e) {
            return $this->sendError('Error updating or creating inventory.', [$e->getMessage()]);
        }
    }

//    public function getSoldItems()
//    {
//
//        try {
//            // Fetch orders by the given type
//            $orders = Order::where('order_type', $validated['order_type'])
//                ->with('items.product') // Load related order items and products
//                ->get();
//
//            if ($orders->isEmpty()) {
//                return $this->sendError('No orders found for the specified type.');
//            }
//
//            // Prepare the response data
//            $data = $orders->map(function ($order) {
//                return [
//                    'order_id' => $order->id,
//                    'sub_total' => $order->sub_total,
//                    'vat' => $order->vat,
//                    'exelo_amount' => $order->exelo_amount,
//                    'total_price' => $order->total_price,
//                    'order_status' => $order->order_status,
//                    'order_items' => $order->items->map(function ($item) {
//                        return [
//                            'product_id' => $item->product_id,
//                            'product_name' => $item->product->product_name,
//                            'quantity' => $item->quantity,
//                            'price' => $item->price,
//                            'total_price' => $item->quantity * $item->price,
//                        ];
//                    }),
//                ];
//            });
//
//            return $this->sendResponse($data, 'Orders retrieved successfully.');
//        } catch (\Exception $e) {
//            return $this->sendError('Error retrieving orders.', $e->getMessage());
//        }
//    }


}
