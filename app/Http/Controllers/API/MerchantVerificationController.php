<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\UserResource;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use App\Http\Resources\MerchantResource;
use Illuminate\Testing\Fluent\Concerns\Has;
use Spatie\Permission\Models\Role;

class MerchantVerificationController extends BaseController
{
    /**
     * Verify merchant data.
     *
     * @param int $merchant_id
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyMerchant($merchant_id)
    {
        $merchant = Merchant::find($merchant_id);

        if (!$merchant) {
            return $this->sendError('Merchant not found.');
        }

        if ($merchant->is_approved) {
            return $this->sendResponse(new MerchantResource($merchant), 'Merchant is already approved.');
        }

        return $this->sendResponse(new MerchantResource($merchant), 'Merchant data for verification.');
    }


    /**
     * Approve the merchant and create a new user account.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function approveMerchant(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required|exists:merchants,id',
            'is_approved' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            $merchant = Merchant::find($request->merchant_id);


            if (!$merchant) {
                return $this->sendError('Merchant not found.');
            }

            if ($merchant->is_approved) {
                $user = User::find($merchant->user_id);
                return $this->sendResponse(['merchant' => new MerchantResource($merchant), 'user' => new UserResource($user)], 'Merchant is already approved.');
            }

            if ($request->is_approved) {
                // Generate a dynamic email
                $email = strtolower(str_replace(' ', '.', $merchant->name)) . '@example.com';

                // Check if the generated email already exists
                while (User::where('email', $email)->exists()) {
                    $email = strtolower(str_replace(' ', '.', $merchant->name)) . '+' . rand(1, 1000) . '@example.com';
                }

                // Create a new user account linked to the merchant
                $user = User::create([
                    'name' => $merchant->name,
                    'email' => $email,
                    'password' => Hash::make('1234'), // You may want to set a default or random password
                    'user_type' => 'merchant',
                ]);

                $user->assignRole(Role::where('name', 'Merchant')->first());

                // Update merchant approval status and link to the new user
                $merchant->is_approved = true;
                $merchant->user_id = $user->id;
                $merchant->save();

                return $this->sendResponse(['merchant' => new MerchantResource($merchant), 'user' => new UserResource($user)], 'Merchant approved and user account created.');
            } else {
                return $this->sendError('Merchant approval denied.');
            }
        } catch (\Exception $e) {
            return $this->sendError('An error occurred during the approval process.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Store a 4-digit PIN code for the user.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function storePin(Request $request)
    {
        // Validate the request data
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:15',
            'pin' => 'required|string|size:4',
            'repeat_pin' => 'required|string|same:pin',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {
            // Normalize the phone number
            $phoneNumber = str_replace(' ', '', $request->phone_number);

            // Check if the merchant exists
            $merchant = Merchant::where('phone_number', $phoneNumber)->first();

            if (!$merchant) {
                return $this->sendError('Merchant mobile number is not registered', '');
            }

            // Fetch the user associated with the merchant
            $user = $merchant->user;

            // Update the user's PIN and password
            $user->pin = $request->pin;
            $user->password = Hash::make($request->pin);
            $user->save();

            // Generate the access token
            $token = $user->createToken('PassportAuth')->accessToken;

            // Load the merchant's current subscription with the subscription plan
            $merchant->load(['currentSubscription.subscriptionPlan']);

            DB::commit();

            // Prepare the response data
            return $this->sendResponse([
                'user' => new UserResource($user),
                'merchant' => new MerchantResource($merchant),
                'token' => $token,
                'user_type' => $user->user_type,
                'short_name' => $this->getInitials($user->name),
                'phone_number' => $merchant->phone_number,
                'profile' => [
                    'first_name' => $merchant->first_name,
                    'last_name' => $merchant->last_name,
                    'phone_number' => $merchant->phone_number,
                    'business_name' => $merchant->business_name,
                    'merchant_code' => $merchant->merchant_code,
                    'location' => $merchant->location,
                ]
            ], 'Merchant Login successful.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('An error occurred while storing the PIN code.', ['error' => $e->getMessage()]);
        }
    }


    public function changePin(Request $request)
    {
        // Validate the request data
        $validator = Validator::make($request->all(), [
            'old_pin' => 'required|string|size:4',
            'new_pin' => 'required|string|size:4',
            'repeat_pin' => 'required|string|same:new_pin',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {
            // Fetch the authenticated user
            // Assuming the authenticated user is a merchant
            $authUser = auth()->user();

            // Determine the message and check for re-subscription eligibility
            $reSubscriptionEligible = false;

            if (!$authUser) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }


            $authUser = auth()->user();


            // Check if the old PIN matches the current PIN
            if ($authUser->pin !== $request->old_pin) {
                return $this->sendError('Old PIN does not match.', []);
            }

            // Update the user's PIN and password
            $authUser->pin = $request->new_pin;
            $authUser->password = Hash::make($request->new_pin);
            $authUser->save();

            DB::commit();

            // Return success response
            return $this->sendResponse([
                'user' => new UserResource($authUser),
                'message' => 'PIN changed successfully.',
            ], 'PIN updated.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('An error occurred while changing the PIN.', ['error' => $e->getMessage()]);
        }
    }

}
