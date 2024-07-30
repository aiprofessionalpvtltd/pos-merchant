<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Validator;
use App\Http\Resources\MerchantResource;


class MerchantConfirmationController extends BaseController
{
    /**
     * Send confirmation text/USSD to the merchant's phone and save the status.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sendConfirmation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:15',
            'confirmation_method' => 'required|string|in:text,ussd'
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            $merchant = Merchant::where('phone_number', $request->phone_number)->first();

            if (!$merchant) {
                return $this->sendError('Merchant not found.');
            }

            if ($merchant->confirmation_status) {
                return $this->sendResponse(new MerchantResource($merchant), 'Merchant already confirmed.');
            }
            
            // Simulate sending confirmation text/USSD to the merchant's phone
            $confirmationStatus = $this->sendConfirmationToPhone($merchant->phone_number, $request->confirmation_method);

            // Update merchant confirmation status
            $merchant->confirmation_status = $confirmationStatus ? true : false;
            $merchant->save();

            // Notify POS Mobile App through an API call (simulation)
            $this->notifyPOSApp($merchant);

            return $this->sendResponse(new MerchantResource($merchant), 'Confirmation process completed.');
        } catch (\Exception $e) {
            return $this->sendError('An error occurred during the confirmation process.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Simulate sending confirmation to the phone number.
     *
     * @param string $phoneNumber
     * @param string $method
     * @return bool
     */
    private function sendConfirmationToPhone($phoneNumber, $method)
    {
        // Here you would integrate with an SMS/USSD gateway
        // For now, we'll simulate a successful confirmation
        return true;
    }

    /**
     * Simulate notifying the POS Mobile App.
     *
     * @param \App\Models\Merchant $merchant
     * @return void
     */
    private function notifyPOSApp($merchant)
    {
        // Here you would make an API call to notify the POS Mobile App
        // For now, we'll simulate a successful notification
    }
}
