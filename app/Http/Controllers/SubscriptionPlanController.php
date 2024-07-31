<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

class SubscriptionPlanController extends Controller
{
    public function index()
    {
        try {
            $plans = SubscriptionPlan::all();
            return SubscriptionPlanResource::collection($plans);
        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred while fetching subscription plans.'], 500);
        }
    }

    public function show($id)
    {
        try {
            $plan = SubscriptionPlan::findOrFail($id);
            return new SubscriptionPlanResource($plan);
        } catch (Exception $e) {
            return response()->json(['error' => 'Subscription plan not found.'], 404);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $plan = SubscriptionPlan::create($request->all());
            return new SubscriptionPlanResource($plan);
        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred while creating the subscription plan.'], 500);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'price' => 'numeric|min:0',
            'duration' => 'string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        try {
            $plan = SubscriptionPlan::findOrFail($id);
            $plan->update($request->all());
            return new SubscriptionPlanResource($plan);
        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred while updating the subscription plan.'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $plan = SubscriptionPlan::findOrFail($id);
            $plan->delete();
            return response()->json(['message' => 'Subscription plan deleted successfully.']);
        } catch (Exception $e) {
            return response()->json(['error' => 'An error occurred while deleting the subscription plan.'], 500);
        }
    }
}
