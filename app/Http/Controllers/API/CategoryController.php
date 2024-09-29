<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Exception;


class CategoryController extends BaseController
{
    public function index()
    {
        try {
            $categories = Category::with('merchant')->latest()->get();
            if ($categories->isEmpty()) {
                return $this->sendResponse([],'No categories found.');
            }
            return $this->sendResponse(CategoryResource::collection($categories), 'Categories retrieved successfully.');
        } catch (Exception $e) {
            return $this->sendError('Error fetching categories.', $e->getMessage());
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

            $categories = Category::with('merchant')->where('merchant_id', $merchantID)->latest()->get();
            if ($categories->isEmpty()) {
                return $this->sendResponse([],'No categories found.');
            }
            return $this->sendResponse(CategoryResource::collection($categories), 'Categories retrieved successfully.');
        } catch (Exception $e) {
            return $this->sendError('Error fetching categories.', $e->getMessage());
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

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

            // Create the category with the merchant_id
            $category = Category::create([
                'name' => $request->name,
                'merchant_id' => $merchantID
            ]);

            DB::commit();

            return $this->sendResponse(new CategoryResource($category), 'Category created successfully.');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError('Error creating category.', $e->getMessage());
        }
    }

    public function show($id)
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

            $category = Category::with('merchant')->where('merchant_id', $merchantID)->find($id);

            if (is_null($category)) {
                return $this->sendError('Category not found.');
            }

            return $this->sendResponse(new CategoryResource($category), 'Category retrieved successfully.');
        } catch (Exception $e) {
            return $this->sendError('Error fetching category.', $e->getMessage());
        }
    }

    public function update(Request $request, $id)
    {
        $validator = $this->validateRequest($request,[
            'name' => 'required|string|max:255',
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

            // Find the category by ID
            $category = Category::find($id);

            // Check if category exists and if it belongs to the authenticated user's merchant
            if (is_null($category)) {
                return $this->sendError('Category not found.');
            }

            if ($category->merchant_id !== $merchantID) {
                return $this->sendError('You are not authorized to update this category.');
            }

            // Update the category
            $category->update($request->name);

            DB::commit();

            return $this->sendResponse(new CategoryResource($category), 'Category updated successfully.');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError('Error updating category.', $e->getMessage());
        }
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            $category = Category::find($id);

            if (is_null($category)) {
                return $this->sendError('Category not found.');
            }

            $category->delete();
            DB::commit();

            return $this->sendResponse([], 'Category deleted successfully.');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError('Error deleting category.', $e->getMessage());
        }
    }

    public function search(Request $request)
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

            // Get search input from the request
            $search = $request->input('search');

             // Query the Category model, searching by name if a search term is provided
            $categoriesQuery = Category::with('merchant')
                ->when($search, function ($query, $search) {
                    return $query->where('name', 'LIKE', "%$search%");
                })
                ->where('merchant_id', $merchantID)
                ->select('id', 'name') // Select only id and name
                ->latest()
                ->get();

            // Check if categories were found
            if ($categoriesQuery->isEmpty()) {
                return $this->sendResponse([], 'No categories found.');
            }

            return $this->sendResponse($categoriesQuery, 'Categories retrieved successfully.');
        } catch (Exception $e) {
            return $this->sendError('Error fetching categories.', $e->getMessage());
        }
    }

}
