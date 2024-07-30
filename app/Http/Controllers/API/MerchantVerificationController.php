<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\UserResource;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use App\Http\Resources\MerchantResource;

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
                return $this->sendResponse(['merchant'=>new MerchantResource($merchant), 'user'=>new UserResource($user)], 'Merchant is already approved.');
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

                // Update merchant approval status and link to the new user
                $merchant->is_approved = true;
                $merchant->user_id = $user->id;
                $merchant->save();

                return $this->sendResponse(['merchant'=>new MerchantResource($merchant), 'user'=>new UserResource($user)], 'Merchant approved and user account created.');
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
     * @return \Illuminate\Http\JsonResponse
     */
    public function storePin(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'merchant_id' => 'required|exists:merchants,id',
            'pin' => 'required|string|size:4'
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            $merchant = Merchant::find($request->merchant_id);

            if (!$merchant) {
                return $this->sendError('Merchant not found.');
            }

            $user = $merchant->user;

            if (!$user) {
                return $this->sendError('User not found for the merchant.');
            }

            // Update the user's PIN and password
            $user->pin = $request->pin;
            $user->password = Hash::make($request->pin);
            $user->save();

            return $this->sendResponse(['merchant'=>new MerchantResource($merchant), 'user'=>new UserResource($user)], 'PIN code stored and password updated.');
        } catch (\Exception $e) {
            return $this->sendError('An error occurred while storing the PIN code.', ['error' => $e->getMessage()]);
        }
    }

}
