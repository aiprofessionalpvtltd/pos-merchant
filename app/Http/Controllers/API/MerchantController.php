<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\UserResource;
use App\Models\Merchant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use function Lcobucci\JWT\Token\all;

class MerchantController extends BaseController
{
    public function index()
    {
        $merchants = Merchant::all();
        return $this->sendResponse(MerchantResource::collection($merchants), 'Merchants retrieved successfully.');
    }

    public function store(Request $request)
    {
        $validator = $this->validateRequest($request, [
            'name' => 'required|string|max:255',
            'address' => 'required|string|max:255',
            'country' => 'required|string|max:255',
            'city' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'phone_number' => 'required|string|max:15|unique:merchants,phone_number',
            'user_id' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $merchant = Merchant::create($request->all());
        return $this->sendResponse(new MerchantResource($merchant), 'Merchant registered successfully. Your request has been sent to the admin for approval.');
    }

    public function show($id)
    {
        $merchant = Merchant::find($id);

        if (is_null($merchant)) {
            return $this->sendError('Merchant not found.');
        }

        return $this->sendResponse($merchant, 'Merchant retrieved successfully.');
    }

    public function update(Request $request, Merchant $merchant)
    {
        $validator = $this->validateRequest($request, [
            'name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string|max:255',
            'country' => 'sometimes|required|string|max:255',
            'city' => 'sometimes|required|string|max:255',
            'state' => 'sometimes|required|string|max:255',
            'phone_number' => 'sometimes|required|string|max:15|unique:merchants,phone_number,' . $merchant->id,
            'user_id' => 'sometimes|required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $merchant->update($request->all());
        return $this->sendResponse(new MerchantResource($merchant), 'Merchant updated successfully.');
    }

    public function destroy(Merchant $merchant)
    {
        $merchant->delete();
        return $this->sendResponse([], 'Merchant deleted successfully.');
    }

    public function requestOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:15',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $merchant = Merchant::where('phone_number', $request->phone_number)->first();

        if (!$merchant) {
            return $this->sendError('Merchant not found.', 404);
        }

        // Generate OTP
        $otp = rand(1000, 9999);

        // Send OTP to the merchant's phone number using SMS
//        SMS::send("Your OTP code is: $otp", [], function($sms) use ($merchant) {
//            $sms->to($merchant->phone_number);
//        });

        // Save OTP to the database
        $merchant->otp = $otp;
        $merchant->otp_expires_at = now()->addMinutes(10); // OTP valid for 10 minutes
        $merchant->save();

        return $this->sendResponse(['merchant' => new MerchantResource($merchant)], 'OTP sent successfully.');
    }

    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:15',
            'otp' => 'required|integer|digits:4',
            'new_pin' => 'required|string|size:4|confirmed', // new_pin_confirmation is required
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $merchant = Merchant::where('phone_number', $request->phone_number)->first();

        if (!$merchant) {
            return $this->sendError('Merchant not found.', 404);
        }

        if ($merchant->otp !== (int)$request->otp || now()->greaterThan($merchant->otp_expires_at)) {
            return $this->sendError('Invalid or expired OTP.', 401);
        }

        $user = User::find($merchant->user_id);

        if (!$user) {
            return $this->sendError('User not found.', 404);
        }

        // Update the merchant's PIN code
        $user->pin = $request->new_pin;
        $user->password = Hash::make($request->new_pin);


        $user->save();

        // Clear the OTP fields
        $merchant->otp = null;
        $merchant->otp_expires_at = null;
        $merchant->save();

        return $this->sendResponse(['merchant'=>new MerchantResource($merchant), 'user'=>new UserResource($user)], 'PIN code updated successfully.');
    }


}
