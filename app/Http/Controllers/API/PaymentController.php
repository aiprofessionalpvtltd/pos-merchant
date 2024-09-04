<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymentController extends BaseController
{
    public function makePayment(Request $request)
    {
        // Validate incoming request
        $validator = $this->validateRequest($request, [
             'phoneNumber' => 'required|string',
            'transactionAmount' => 'required|numeric',
         ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {
            $phoneNo =  '+252'.$request->input('phoneNumber');
             // Check if a merchant with the provided phone number already exists
            $merchant = Merchant::where('phone_number', $phoneNo)->first();


            if (!$merchant) {
                return $this->sendError('Merchant mobile number is not registered', '');
            }

            $apiKey = 'pTH0GSyW5OspxyfDffrhSpNcN37yCGEl2SBZ4yv8q';
            $currency = 'SLSH'; // Hardcoded or set dynamically as per your logic

            // Generate a unique transaction ID
            $transactionId = 'txn_' . $this->generateTransactionCode() . '_' . now()->timestamp;

            // Secret key for hashing
            $secret = 'kgBnW9Paa7ZErxB4GFo81FaASDFTQKhiOLxryw';

            // Prepare request body
            $body = json_encode([
                'apiKey' => $apiKey,
                'phoneNumber' => $request->input('phoneNumber'),
                'transactionAmount' => $request->input('transactionAmount'),
                'transactionId' => $transactionId,
                'currency' => $currency,
            ]);

            // Generate the hash using SHA-256
            $hash = hash('sha256', $body . $secret);

            // Prepare payload
            $payload = [
                'apiKey' => $apiKey,
                'phoneNumber' => $request->input('phoneNumber'),
                'transactionAmount' => $request->input('transactionAmount'),
                'transactionId' => $transactionId,
                'currency' => $currency,
            ];

            // Construct endpoint with hash as query parameter
            $endPoint = 'https://www.edahab.net/api/api/agentPayment?hash=' . $hash;

//            dd($endPoint, $payload);
            // Send POST request
            $response = Http::post($endPoint, $payload);

            $responseData = $response->json();

            // Save the response data to the database
            $transaction = Transaction::create([
                'transaction_amount' => $request->input('transactionAmount'),
                'transaction_status' => $responseData['TransactionStatus'],
                'transaction_message' => $responseData['TransactionMesage'],
                'phone_number' => $responseData['PhoneNumber'],
                'transaction_id' => $responseData['TransactionId'],
                'merchant_id' =>$merchant->id,
            ]);
            DB::commit(); // Commit transaction
            // Load the merchant relationship for the resource
            $transaction->load('merchant');

            return $this->sendResponse(
                new TransactionResource($transaction),
                'Merchant payment processed successfully.'
            );

         } catch (\Exception $e) {
            DB::rollBack(); // Rollback transaction
            return $this->sendError('An error occurred while processing your request.', $e->getMessage());
        }
    }

    // Helper method to generate a unique transaction code
    private function generateTransactionCode()
    {
        // Implement your logic to generate a unique transaction code
        return uniqid();
    }
}
