<?php

namespace App\Http\Controllers\API;

use App\Http\Resources\MerchantResource;
use App\Http\Resources\UserResource;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\API\BaseController;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class PassportAuthController extends BaseController
{
    /**
     * Handle login and return a token.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */


    public function login(Request $request)
    {

        $this->validateRequest($request, [
            'phone_number' => 'required|string|max:20',
        ]);

        $phoneNumber = str_replace(' ', '', $request->phone_number);
        $merchant = Merchant::where('phone_number', $phoneNumber)->first();

        if (!$merchant) {
            return $this->sendError('Phone number not found.', 404);
        }


        $user = $merchant->user;

        // Check if the user has a PIN
        $isPin = !is_null($user->pin);

        // Return response
        return $this->sendResponse([
            'user' => new UserResource($user),
            'merchant' => new MerchantResource($merchant),
            'user_type' => $user->user_type,
            'is_pin' => $isPin,
            'short_name' => $this->getInitials($user->name)
        ], 'Enter the PIN Code');

    }

    public function verifyUser(Request $request)
    {
        try {
            // Validate the request based on user type
            $this->validateRequest($request, [
                'phone_number' => 'required|string|max:15',
                'pin' => 'required|string|size:4',
            ]);

            $phoneNumber = str_replace(' ', '', $request->phone_number);
            $merchant = Merchant::where('phone_number', $phoneNumber)->first();

            if (!$merchant) {
                return $this->sendError('Phone number not found.', '', 404);
            }

            if (!Hash::check($request->pin, $merchant->user->password)) {
                return $this->sendError('Invalid PIN code.', '', 401);
            }

            $user = $merchant->user;
            $token = $user->createToken('PassportAuth')->accessToken;
            $merchant->load(['currentSubscription.subscriptionPlan']); // Load both subscription and subscriptionPlan relationships

            $currentSubscription = $merchant->currentSubscription;
            $noSubscription = new \stdClass();
            // If currentSubscription is null, set default values
            if (!$currentSubscription) {
                // Create a new stdClass object
                $noSubscription->subscription_plan_id = 1; // Default to Silver
                $noSubscription->reSubscriptionEligible = true; // Eligible for re-subscription
                // Add the current subscription back to the merchant for the resource
                $merchant->currentSubscription = $noSubscription;
            }



             return $this->sendResponse([
                'user' => new UserResource($user),
                'merchant' => new MerchantResource($merchant),
                'token' => $token,
                'user_type' => $user->user_type,
                'short_name' => $this->getInitials($user->name)
            ], 'Merchant Login successful.');

        } catch (\Exception $e) {
            return $this->sendError('An error occurred during the verification process.', ['error' => $e->getMessage()]);
        }
    }



    /**
     * Get the authenticated user information.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function userInfo()
    {
        $user = Auth::user();

        return $this->sendResponse($user, 'User information retrieved successfully.');
    }


    public function logout(Request $request)
    {
//        return $this->sendResponse([], 'i m here');
        // Validate that the user is authenticated
        if (!Auth::check()) {
            return $this->sendError('User not authenticated.', [], 401);
        }

        // Retrieve the authenticated user
        $user = Auth::user();

        // Revoke all tokens for the authenticated user
        $user->tokens->each(function ($token) {
            $token->revoke();
        });

        // Optionally, if you are using a refresh token or personal access tokens
        // and want to ensure they are also revoked, you can clear them here.

        // Return a successful response
        return $this->sendResponse([], 'User logout successful.');
    }

}
