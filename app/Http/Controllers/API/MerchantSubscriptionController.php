<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\MerchantSubscriptionResource;
use App\Models\MerchantSubscription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Exception;
use function League\Uri\UriTemplate\first;

class MerchantSubscriptionController extends BaseController
{
    public function index()
    {
        try {
            $subscriptions = MerchantSubscription::with('subscriptionPlan')->get();
            return $this->sendResponse(MerchantSubscriptionResource::collection($subscriptions), 'Subscriptions retrieved successfully.');
        } catch (Exception $e) {
            return $this->sendError('An error occurred while fetching subscriptions.', ['error' => $e->getMessage()]);
        }
    }

    public function current(Request $request) // Add Request parameter
    {
        try {
            // Assuming the authenticated user is a merchant
            $authUser = auth()->user();

            // Determine the message and check for re-subscription eligibility
            $reSubscriptionEligible = false;

            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Load the merchant's current subscription using the relationship defined in the Merchant model
            $merchant = $authUser->merchant->load('currentSubscription');

            // Get the current subscription
            $currentSubscription = $merchant->currentSubscription;
            $noSubscription = new \stdClass();
            if (!$currentSubscription) {
                $noSubscription->subscription_plan_id = 2; // Default to Silver
                $noSubscription->reSubscriptionEligible = true; // Eligible for re-subscription

                // Create a new Request instance and merge the subscription plan ID
                $newRequest = new Request($request->all()); // Copy existing request data
                $newRequest->merge(['subscription_plan_id' => $noSubscription->subscription_plan_id]); // Add the subscription plan ID

                // Call the store method with the new request
               $newSubscription =  $this->store($newRequest);

                 return $newSubscription;
              }

            // Load the subscription plan relation (to access the package name and id)
            $currentSubscription->load('subscriptionPlan');

            // Get the package name and ID
            $packageName = $currentSubscription->subscriptionPlan->name;
            $subscriptionPlanId = $currentSubscription->subscription_plan_id;

            // Determine whether to show the end date
            $showEndDate = $subscriptionPlanId != 2; // If plan is not Silver (ID != 1), show the end date

            if ($currentSubscription->is_canceled && $currentSubscription->end_date && $currentSubscription->end_date >= now()) {

                $message = "Subscription ({$packageName}) is canceled but " . ($showEndDate ? "valid until " . showDate($currentSubscription->end_date) : "still active.");
                $reSubscriptionEligible = false; // Eligible for re-subscription if it's canceled but still valid

            } elseif (!$currentSubscription->is_canceled) {

                $message = "Subscription ({$packageName}) is active" . ($showEndDate ? " and valid until " . showDate($currentSubscription->end_date) : ".");
                $reSubscriptionEligible = true;

            } else {

                $message = "Subscription ({$packageName}) is canceled and no longer valid.";
                $reSubscriptionEligible = true; // Eligible for re-subscription if it's fully canceled

            }

            $currentSubscription->reSubscriptionEligible = $reSubscriptionEligible;

            // Return the response with the merged data
            return $this->sendResponse(new MerchantSubscriptionResource($currentSubscription), $message);

        } catch (Exception $e) {
            return $this->sendError('An error occurred while fetching the current subscription.', ['error' => $e->getMessage()]);
        }
    }


    public function canceled()
    {
        try {
            // Get the authenticated user and ensure they have a merchant
            $authUser = auth()->user();

            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Load the canceled subscriptions for the merchant
            $merchant = $authUser->merchant->load('canceledSubscriptions');

            // Get the canceled subscriptions
            $canceledSubscriptions = $merchant->canceledSubscriptions;

            if ($canceledSubscriptions->isEmpty()) {
                return $this->sendError('No canceled subscriptions found.');
            }

            $canceledSubscriptions->load('subscriptionPlan');

            return $this->sendResponse(MerchantSubscriptionResource::collection($canceledSubscriptions), 'Canceled subscriptions retrieved successfully.');
        } catch (Exception $e) {
            return $this->sendError('An error occurred while fetching the canceled subscriptions.', ['error' => $e->getMessage()]);
        }
    }

    public function store(Request $request)
    {
         // Validate the input
        $validator = Validator::make($request->all(), [
            'subscription_plan_id' => 'required|exists:subscription_plans,id',
        ]);

        // If validation fails, return an error response
        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            // Get authenticated user
            $authUser = auth()->user();

            // Check if the authenticated user has an associated merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant ID
            $merchantID = $authUser->merchant->id;

            // Load the merchant's current subscription using the relationship defined in the Merchant model
            $merchant = $authUser->merchant->load('currentSubscription');

            // Get the current subscription
            $currentSubscription = $merchant->currentSubscription;

            // Check if the merchant is already subscribed to the requested plan and the subscription is not canceled
            if ($currentSubscription && $currentSubscription->subscription_plan_id == $request->subscription_plan_id && $currentSubscription->is_canceled == 0) {
                return $this->sendError('You are already subscribed to this package.');
            }


             // If the current subscription exists and is not the requested one, delete the all subscriptions
            if ($currentSubscription == null) {
                MerchantSubscription::where('merchant_id', $merchantID)->delete();
            }

            // Create a new subscription for the merchant
            $newSubscription = MerchantSubscription::create([
                'merchant_id' => $merchantID,
                'subscription_plan_id' => $request->subscription_plan_id, // Use the validated plan ID from the request
                'start_date' => now(),
                'end_date' => now()->addMonth(),
                'transaction_status' => 'Paid',
            ]);

            // Load the subscription plan relationship
            $newSubscription->load(['subscriptionPlan']);

            // Return a success response with the new subscription details
            return $this->sendResponse(new MerchantSubscriptionResource($newSubscription), 'Subscription created successfully.');
        } catch (Exception $e) {
            // Return an error response if something goes wrong
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


    public function cancel(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            // Get authenticated user
            $authUser = auth()->user();

            // Check if the authenticated user has an associated merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant ID
            $merchantID = $authUser->merchant->id;


            // Fetch the subscription by ID
            $subscription = MerchantSubscription::where('subscription_plan_id',$id)->where('merchant_id', $merchantID)->first();

            // Check if the subscription exists
            if (!$subscription) {
                 return $this->sendError('Subscription not found.');
            }

            // Check if the subscription is already canceled
            if ($subscription->is_canceled) {
                return $this->sendError('Subscription is already canceled.');
            }

            // Mark the subscription as canceled and set the cancellation date
            $subscription->is_canceled = true;
            $subscription->canceled_at = now();
            $subscription->save();

            // Check if the subscription has an end date
            if ($subscription->end_date) {
                $endDateMessage = "You can continue using this subscription until " . showDate($subscription->end_date);
            } else {
                $endDateMessage = "You can continue using this subscription until the end of your current billing cycle.";
            }

            // Commit the transaction if everything is successful
            DB::commit();
            // Return the response with the subscription and usage information
            return $this->sendResponse(new MerchantSubscriptionResource($subscription), 'Subscription canceled successfully. ' . $endDateMessage);

        } catch (Exception $e) {
            // Rollback the transaction if any error occurs
            DB::rollBack();

            // Handle any exceptions and return an error message
            return $this->sendError('An error occurred while canceling the subscription.', ['error' => $e->getMessage()]);
        }
    }


}
