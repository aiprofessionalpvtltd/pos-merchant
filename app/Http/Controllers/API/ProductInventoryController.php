<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\ProductInventoryResource;
use App\Models\ProductInventory;
use Illuminate\Http\Request;
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
            if (!in_array($type, ['stock', 'shop' ,'transportation'])) {
                return $this->sendError('Invalid type provided. It must be either "stock" or "shop".');
            }

            // Get authenticated user
            $authUser = auth()->user();

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

}
