<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MerchantTransactionController extends BaseController
{
    public function verifyTransaction(Request $request)
    {
        $request->validate([
            'edahabNumber' => 'required|string',
            'amount' => 'required|numeric|min:1'
        ]);

        $payload = [
            'apiKey' => config('services.edahab.api_key'),
            'edahabNumber' => $request->edahabNumber,
            'amount' => $request->amount,
            'agentCode' => config('services.edahab.agent_code'),
            'currency' => config('services.edahab.currency'),
            'pin' => config('services.edahab.pin'),
        ];

//        // Check the amount and include PIN if necessary
//        if ($payload['amount'] > 500) { // Assuming 500 is the threshold
//            $payload['pin'] = 'YOUR_PIN_HERE'; // Replace with actual PIN handling
//        }

        try {
            $response = Http::post('https://edahab.net/api/api/issueinvoice', $payload);

            dd($response->json());
            if ($response->successful()) {
                $responseData = $response->json();
                // Log successful response for debugging
                Log::info('Transaction verified successfully', ['response' => $responseData]);

                return $this->sendResponse($responseData, 'Transaction verified successfully.');
            } else {
                // Log response status and body for debugging
                Log::error('Failed to verify transaction', [
                    'status' => $response->status(),
                    'response' => $response->json()
                ]);

                return $this->sendError('Failed to verify transaction.', $response->json(), $response->status());
            }
        } catch (\Exception $e) {
            // Log exception for debugging
            Log::error('An error occurred during the transaction verification process', [
                'error' => $e->getMessage()
            ]);

            return $this->sendError('An error occurred during the transaction verification process.', ['error' => $e->getMessage()]);
        }
    }
}
