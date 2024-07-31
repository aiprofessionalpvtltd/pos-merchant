<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\MerchantSubscriptionResource;
use App\Models\MerchantSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Exception;

class MerchantSubscriptionController extends BaseController
{
    public function index()
    {
        try {
            $subscriptions = MerchantSubscription::all();
            return $this->sendResponse(MerchantSubscriptionResource::collection($subscriptions), 'Subscriptions retrieved successfully.');
        } catch (Exception $e) {
            return $this->sendError('An error occurred while fetching subscriptions.', ['error' => $e->getMessage()]);
        }
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required|exists:merchants,id',
            'subscription_plan_id' => 'required|exists:subscription_plans,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'transaction_status' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            $subscription = MerchantSubscription::create($request->all());
            return $this->sendResponse(new MerchantSubscriptionResource($subscription), 'Subscription created successfully.');
        } catch (Exception $e) {
            return $this->sendError('An error occurred while creating the subscription.', ['error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        try {
            $subscription = MerchantSubscription::findOrFail($id);
            return $this->sendResponse(new MerchantSubscriptionResource($subscription), 'Subscription retrieved successfully.');
        } catch (Exception $e) {
            return $this->sendError('Subscription not found.', ['error' => $e->getMessage()]);
        }
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required|exists:merchants,id',
            'subscription_plan_id' => 'required|exists:subscription_plans,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'transaction_status' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            $subscription = MerchantSubscription::findOrFail($id);
            $subscription->update($request->all());
            return $this->sendResponse(new MerchantSubscriptionResource($subscription), 'Subscription updated successfully.');
        } catch (Exception $e) {
            return $this->sendError('An error occurred while updating the subscription.', ['error' => $e->getMessage()]);
        }
    }

    public function destroy($id)
    {
        try {
            $subscription = MerchantSubscription::findOrFail($id);
            $subscription->delete();
            return $this->sendResponse([], 'Subscription deleted successfully.');
        } catch (Exception $e) {
            return $this->sendError('An error occurred while deleting the subscription.', ['error' => $e->getMessage()]);
        }
    }

    public function cancel(Request $request, $id)
    {
        try {
            $subscription = MerchantSubscription::findOrFail($id);

            if ($subscription->is_canceled) {
                return $this->sendError('Subscription already canceled.');
            }

            $subscription->is_canceled = true;
            $subscription->canceled_at = now();
            $subscription->save();

            return $this->sendResponse(new MerchantSubscriptionResource($subscription), 'Subscription canceled successfully.');
        } catch (Exception $e) {
            return $this->sendError('An error occurred while canceling the subscription.', ['error' => $e->getMessage()]);
        }
    }

}
