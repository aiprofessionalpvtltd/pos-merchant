<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PaymentController extends BaseController
{

    public function routePaymentAPI(Request $request)
    {
        // Define Dahab prefixes
        $dahabPrefixes = ['65', '66', '62'];

        $phoneNumber = $request->input('edahab_number');

        // Extract the first two digits of the phone number
        $prefix = substr($phoneNumber, 0, 2);

        // Check if the prefix matches any of the Dahab prefixes
        if (in_array($prefix, $dahabPrefixes)) {
            // Connect to Dahab API
            return $this->connectToDahabAPI($request);
        } else {
            // Connect to Waafi API
            return $this->connectToWaafiAPI($request);
        }
    }

    protected function connectToDahabAPI($request)
    {
        $responses = []; // Variable to store all responses

        $merchantPayment = false;

        if ($request->merchant_payment) {
            $merchantPayment = true;
        }

        // Step 1: Call the issueInvoice function and store the response
        $issueInvoiceResponse = $this->issueInvoice($request);

        // Convert JsonResponse to an associative array
        $issueInvoiceData = $issueInvoiceResponse->getData(true);

        // Check if the issueInvoice call was successful
        if (isset($issueInvoiceData['success']) && $issueInvoiceData['success']) {
            // Add the issueInvoice response to the responses array
            $responses['issueInvoice'] = $issueInvoiceData;

            // Extract the invoice_id from the issueInvoiceData
            $invoiceId = $issueInvoiceData['data']['invoice_id'];

            // Add the invoice_id to the request
            $request->merge(['invoice_id' => $invoiceId]);

            // Step 2: Call the checkInvoiceStatus function and store the response
            $checkInvoiceStatusResponse = $this->checkInvoiceStatus($request);

            // Convert JsonResponse to an associative array
            $checkInvoiceStatusData = $checkInvoiceStatusResponse->getData(true);

            // Add the checkInvoiceStatus response to the responses array
            $responses['checkInvoiceStatus'] = $checkInvoiceStatusData;

            // Step 3: If merchantPayment is true, call the makeMerchantPayment function
            if ($merchantPayment) {

                $makeMerchantPaymentResponse = $this->makeMerchantPayment($request);

                // Convert JsonResponse to an associative array
                $makeMerchantPaymentData = $makeMerchantPaymentResponse->getData(true);

                // Add the makeMerchantPayment response to the responses array
                $responses['makeMerchantPayment'] = $makeMerchantPaymentData;
            }
        } else {
            // If issueInvoice failed, add the error response
            $responses['error'] = 'Failed to issue invoice';
        }

        // Return all the responses as one combined array
        return response()->json($responses);
    }

    protected function connectToWaafiAPI($request)
    {
        // Example data to send

        // Call the Waafi API using the helper function
        $waafiResponse = $this->callWaafiAPIForPreAuthorize($request);

        dd($waafiResponse);
        // Convert JsonResponse to an associative array
//        $waafiResponse = $waafiResponse->getData(true);

        // Add the checkInvoiceStatus response to the responses array
        $responses['waafiResponse'] = $waafiResponse;


        if ($waafiResponse['success'] = false) {
            return response()->json([
                'error' => $waafiResponse['error'],
                'message' => 'Failed to call Waafi API for Pre Authorize'
            ], 500);
        }

        $waafiCommitResponse = $this->connectToWaafiCommitAPI($request);

        // Convert JsonResponse to an associative array
//        $waafiCommitResponse = $waafiCommitResponse->getData(true);

        // Add the checkInvoiceStatus response to the responses array
        $responses['waafiCommitResponse'] = $waafiCommitResponse;

        dd($responses);
        // Return the response from Waafi API to the caller
        return response()->json($responses);
    }


    // Process the transaction and calculate fees
    public function processTransaction(Request $request)
    {
        //        // Validate incoming request
        $validator = $this->validateRequest($request, [
            'transaction_amount' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $transactionAmount = $request->transaction_amount;

        // Add 2% to the amount entered by the customer (customer fee)
        $customerFee = $transactionAmount * 0.02;
        $totalCustomerCharge = $transactionAmount + $customerFee;

        // Deduct 1% from the amount to be sent to the merchant (Exelo fee)
        $exeloFee = $transactionAmount * 0.01;
        $amountSentToMerchant = $transactionAmount - $exeloFee;

        // Exelo keeps both the customer fee and the Exelo fee (1% deducted from merchant)
        $amountKeptByExelo = $customerFee + $exeloFee;

        return response()->json([
            'customer_fee' => $customerFee,
            'exelo_fee' => $exeloFee,
            'amount_kept_by_exelo' => $amountKeptByExelo,
            'amount_sent_to_merchant' => $amountSentToMerchant,
            'total_customer_charge' => $totalCustomerCharge,
        ]);
    }

    // Generate transaction hash
    private function generateHash($body, $secret)
    {
        return hash('sha256', $body . $secret);
    }

    // Issue an invoice
    public function issueInvoice(Request $request)
    {

        // Fetch api_key and agent_code from .env
        $apiKey = env('EXELO_API_KEY');
        $agentCode = env('EXELO_AGENT_CODE');
        $secret = env('SECRET_KEY');

        // Inputs from the request
        $phoneNumber = $request->input('edahab_number');
        $totalCustomerCharge = $request->input('total_customer_charge');
        $currency = $request->input('currency');
        $iteration = 1;

        $transactionId = 'txn_' . $iteration . '_' . round(microtime(true) * 1000);

// Remove the country code and spaces
        $edahabNumber = str_replace(['+252', ' '], '', $phoneNumber);

        $payload = [
            "apiKey" => $apiKey,
            "EdahabNumber" => $edahabNumber,
            "Amount" => $totalCustomerCharge,
            "AgentCode" => $agentCode,
            "transactionId" => $transactionId,
            "Currency" => $currency
        ];

        $bodyStr = json_encode($payload);
        $hashValue = $this->generateHash($bodyStr, $secret);
        $url = "https://edahab.net/api/api/IssueInvoice?hash=$hashValue";

        try {
            // Start database transaction
            DB::beginTransaction();

            // Make API request to issue invoice
            $response = Http::timeout(60)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

            if ($response->status() === 200) {
                $invoiceData = $response->json();

                // Simulating database insertion of the transaction details
                Invoice::create([
                    'invoice_id' => $invoiceData['InvoiceId'],
                    'mobile_number' => $edahabNumber,
                    'transaction_id' => $transactionId,
                    'hash' => $hashValue,
                    'amount' => $totalCustomerCharge,
                    'currency' => $currency,
                ]);


                // Commit the transaction if everything is successful
                DB::commit();

                return $this->sendResponse(
                    [
                        'invoice_id' => $invoiceData['InvoiceId'],
                        'transaction_id' => $transactionId,
                        'hash' => $hashValue
                    ],
                    'Invoice issued successfully.'
                );


            } else {
                // Rollback the transaction if the API request fails
                DB::rollBack();
                Log::error('Failed to issue invoice', ['response' => $response->body()]);

                return $this->sendError('error', 'Failed to issue invoice', 500);
            }
        } catch (\Exception $e) {
            // Rollback the transaction if any exception occurs
            DB::rollBack();
            Log::error('Error in issueInvoice: ' . $e->getMessage());
            return $this->sendError('error', 'An error occurred while issuing the invoice', 500);
        }
    }

    // Check invoice status
    public function checkInvoiceStatus(Request $request)
    {
        // Get api_key from the request and secret from .env
        $apiKey = env('EXELO_API_KEY'); // From .env
        $secret = env('SECRET_KEY'); // Secret from .env
        $invoiceId = $request->input('invoice_id');

        $payload = [
            "apiKey" => $apiKey,
            "invoiceId" => $invoiceId
        ];

        $bodyStr = json_encode($payload);
        $hashValue = $this->generateHash($bodyStr, $secret);
        $url = "https://edahab.net/api/api/checkInvoiceStatus?hash=$hashValue";

        try {
            // Start database transaction
            DB::beginTransaction();
            // Send the API request
            $response = Http::timeout(60)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

            if ($response->status() === 200) {
                $responseData = $response->json();
                $invoiceStatus = $responseData['InvoiceStatus'];  // Assuming the API returns 'InvoiceStatus'
                $eTransactionId = $responseData['TransactionId'];  // Assuming the API returns 'InvoiceStatus'

                if ($invoiceStatus) {
                    // Update the invoice status in the database
                    $invoice = Invoice::where('invoice_id', $invoiceId)->first();

                    if ($invoice) {
                        $invoice->update([
                            'status' => $invoiceStatus, // Assuming 'status' column exists in the table
                            'e_transaction_id' => $eTransactionId
                        ]);
                    }
                    DB::commit();

                    return $this->sendResponse(
                        [
                            'invoice_status' => $invoiceStatus,
                            'e_transaction_id' => $eTransactionId
                        ],
                        'Invoice status updated successfully'
                    );

                } else {
                    DB::rollBack();
                    return $this->sendError('error', 'Failed to retrieve invoice status from API', 500);
                }
            } else {
                DB::rollBack();
                return $this->sendError('error', 'Failed to check invoice status', 500);

            }
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in checkInvoiceStatus: ' . $e->getMessage());
            return $this->sendError('error', 'An error occurred while checking the invoice status', 500);
        }
    }

    // Initiate merchant payment
    public function makeMerchantPayment(Request $request)
    {
        $apiKey = $request->input('api_key');
        $amountSentToMerchant = (int)$request->input('amount_sent_to_merchant');
        $currency = $request->input('currency');
        $transactionId = 'mp_' . round(microtime(true) * 1000);


        if ($request->phone_number) {
            $phoneNumber = $request->input('phone_number');
            $phoneNo = '+252' . $request->input('phone_number');
            // Check if a merchant with the provided phone number already exists
            $merchant = Merchant::where('phone_number', $phoneNo)->first();
        } else {
            $apiKey = env('EXELO_API_KEY'); // From .env
            $authUser = auth()->user();
            $phoneNumber = $authUser->merchant->phone_number;
            $merchant = Merchant::where('phone_number', $phoneNumber)->first();
        }


        if (!$merchant) {
            return $this->sendError('Merchant mobile number is not registered', '');
        }


        $payload = [
            "apiKey" => $apiKey,
//            "phoneNumber" => str_replace('+252', '', $phoneNumber),
            "phoneNumber" => 6656566565665,
            "transactionAmount" => $amountSentToMerchant,
            "transactionId" => $transactionId,
            "currency" => $currency
        ];

        $bodyStr = json_encode($payload);
        $secret = 'kgBnW9Paa7ZErxB4GFo81FaASDFTQKhiOLxryw';
        $hashValue = $this->generateHash($bodyStr, $secret);
        $url = "https://edahab.net/api/api/agentPayment?hash=$hashValue";

        $response = Http::timeout(60)->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

        if ($response->status() === 200) {
            $responseData = $response->json();

            if ($responseData['TransactionId'] == null) {
                return response()->json(['error' => $responseData['TransactionMesage']], 500);
            }

            // Save the response data to the database
            $transaction = Transaction::create([
                'transaction_amount' => $amountSentToMerchant,
                'transaction_status' => $responseData['TransactionStatus'],
                'transaction_message' => $responseData['TransactionMesage'],
                'phone_number' => $responseData['PhoneNumber'],
                'transaction_id' => $responseData['TransactionId'],
                'merchant_id' => $merchant->id,
            ]);


            DB::commit(); // Commit transaction
            // Load the merchant relationship for the resource
            $transaction->load('merchant');

            return $this->sendResponse(
                new TransactionResource($transaction),
                'Merchant payment processed successfully.'
            );


//            return response()->json($response->json());
        } else {
            return response()->json(['error' => 'Failed to make merchant payment'], 500);
        }
    }

    protected function callWaafiAPIForPreAuthorize($request)
    {

        // Generate referenceId and invoiceId
        $referenceId = rand(100000, 999999); // 6-digit random number
        $invoiceId = rand(100000, 999999);   // 6-digit random number

        $accountNo = '252' . $request->input('edahab_number'); // Phone number
        $amount = $request->input('total_customer_charge');  // Amount to be paid
        $currency = $request->input('currency', 'SLSH');  // Currency

        // Prepare the payload for the Waafi API request
        $payload = [
            "schemaVersion" => "1.0",
            "requestId" => uniqid('', true), // Unique request ID
            "timestamp" => now()->format('Y-m-d'), // Current timestamp in Y-m-d format
            "channelName" => "WEB",
            "serviceName" => "API_PREAUTHORIZE",
            "serviceParams" => [
                "merchantUid" => env('WAAFI_MERCHANT_UID', 'M0912269'),
                "apiUserId" => env('WAAFI_API_USER_ID', '1000297'),
                "apiKey" => env('WAAFI_API_KEY', 'API-1901083745AHX'),
                "paymentMethod" => "MWALLET_ACCOUNT",
                "payerInfo" => [
                    "accountNo" => $accountNo
                ],
                "transactionInfo" => [
                    "referenceId" => $referenceId,
                    "invoiceId" => $invoiceId,
                    "amount" => $amount,
                    "currency" => $currency,
                    "description" => 'wan diray',
                    "paymentBrand" => "WAAFI"
                ]
            ]
        ];


        try {
            // Send the API request using Guzzle (Http facade)
            $response = Http::timeout(60)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.waafipay.net/asm', $payload);

            // Return the response body as JSON
            if ($response->successful()) {
                $invoiceData = $response->json();

                if ($invoiceData['errorCode'] != 'E101071') {
                    // Simulating database insertion of the transaction details
                    Invoice::create([
                        'invoice_id' => $invoiceData['referenceId'],
                        'mobile_number' => $accountNo,
                        'transaction_id' => $invoiceData['transactionId'],
                        'hash' => 0,
                        'amount' => $invoiceData['txAmount'],
                        'currency' => $currency,
                        'status' => $invoiceData['state'],
                    ]);

                    return [
                        'success' => true,
                        'message' => 'Failed to call Waafi API',
                        'status' => $invoiceData['errorCode'],
                        'data' => [
                            'referenceId' => $invoiceData['referenceId'],
                            'transactionId' => $invoiceData['transactionId'],
                            'status' => $invoiceData['state'],
                            'mobile_number' => $accountNo,

                        ]
                    ];

                } else {
                    return [
                        'success' => false,
                        'message' => 'Failed to call Waafi API',
                        'status' => $invoiceData['errorCode'],
                        'mobile_number' => $accountNo,
                        'error' => $invoiceData['responseMsg']
                    ];
                }


            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to call Waafi API',
                    'status' => $response->status(),
                    'error' => $response->body()
                ];
            }
        } catch (\Exception $e) {
            // Handle exceptions and log errors
            \Log::error('Waafi API Error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'An error occurred while calling the Waafi API',
                'error' => $e->getMessage()
            ];
        }


    }

    protected function connectToWaafiCommitAPI($request)
    {
        // Generate a random requestId and current timestamp
        $requestId = rand(100000, 999999); // Generate random request ID
        $sessionId = rand(100000, 999999); // Generate random request ID
        $timestamp = now()->toIso8601String(); // Current timestamp in ISO format

        // Get the necessary data from the request or environment
        $merchantUid = env('WAAFI_MERCHANT_UID', 'M0912269'); // Replace with actual merchantUid
        $apiUserId = env('WAAFI_API_USER_ID', '1000297'); // Replace with actual apiUserId
        $referenceId = $request->input('reference_id');
        $transactionId = $request->input('transactionId');
        $invoiceId = $request->input('invoice_id');
        $amount = $request->input('amount', '100'); // Default amount
        $currency = $request->input('currency', 'USD'); // Default currency

        // Build the payload
        $payload = [
            "schemaVersion" => "1.0",
            "requestId" => $requestId,
            "timestamp" => $timestamp,
            "channelName" => "WEB",
            "serviceName" => "API_PREAUTHORIZE_COMMIT",
            "sessionId" => $sessionId,
            "serviceParams" => [
                "merchantUid" => $merchantUid,
                "apiUserId" => $apiUserId,
                "transactionId" => $transactionId,
                "referenceId" => $referenceId,
                "description" => "Commit transaction",
            ]
        ];

        try {
            // Make the HTTP request to Waafi API
            $response = Http::timeout(60)
                ->withHeaders([
                    'Content-Type' => 'application/json'
                ])
                ->post('https://api.waafipay.net/asm', $payload);

            // Check if the response is successful
            if ($response->successful()) {
                // Return the response data
//                return response()->json($response->json(), 200);
                $responseData = $response->json();
                $invoiceResponse = $responseData['params'];  // Assuming the API returns 'InvoiceStatus'
                $eTransactionId = $responseData['params']['referenceId'];
                if ($invoiceResponse) {
                    // Update the invoice status in the database
                    $invoice = Invoice::where('invoice_id', $invoiceId)->first();

                    if ($invoice) {
                        $invoice->update([
                            'status' => $invoiceResponse['state'], // Assuming 'status' column exists in the table
                            'e_transaction_id' => $invoiceResponse['transactionId']
                        ]);
                    }
                    DB::commit();

                    return $this->sendResponse(
                        [
                            'status' => $invoiceResponse['state'], // Assuming 'status' column exists in the table
                            'e_transaction_id' => $invoiceResponse['transactionId']
                        ],
                        'Invoice status updated successfully'
                    );

                }

            } else {
                // Log and return the error response
                \Log::error('API Commit failed', ['response' => $response->body()]);
                return response()->json([
                    'error' => 'Failed to commit the transaction',
                    'details' => $response->body()
                ], 500);
            }
        } catch (\Exception $e) {
            // Log the error and return a failure response
            \Log::error('API Commit exception', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'An error occurred while committing the transaction',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
