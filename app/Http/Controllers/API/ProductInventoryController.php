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
}
