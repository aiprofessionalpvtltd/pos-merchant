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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends BaseController
{

    public function routePaymentAPI(Request $request)
    {
        // Define Dahab prefixes
        $dahabPrefixes = ['65', '66', '62'];

        $phoneNumber = $request->input('edahab_number');

        $phoneNumber = str_replace(['+252', ' '], '', $phoneNumber);

        // Extract the first two digits of the phone number
        $prefix = substr($phoneNumber, 0, 2);

        // Check if the prefix matches any of the Dahab prefixes
        if (in_array($prefix, $dahabPrefixes)) {
            // Connect to Dahab API
            $finalResponse = $this->connectToDahabAPI($request);

            $finalResponse = $finalResponse->getData(true);

            if ($finalResponse['error']) {
                Log::error('error in payment proceeding in final response');

                return $this->sendError($finalResponse['error'], '');
            }

            return $this->sendResponse($finalResponse, 'Payment Proceed Successfully');
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

            if ($checkInvoiceStatusData['error']) {
                $responses['error'] = $checkInvoiceStatusData['error'];
            } else {
                $responses['checkInvoiceStatus'] = $checkInvoiceStatusData;
            }


            // Step 3: If merchantPayment is true, call the makeMerchantPayment function
//            if ($merchantPayment) {
//
//                $makeMerchantPaymentResponse = $this->makeMerchantPayment($request);
//
//                // Convert JsonResponse to an associative array
//                $makeMerchantPaymentData = $makeMerchantPaymentResponse->getData(true);
//
//                if ($makeMerchantPaymentData['error']) {
//                    $responses['error'] = $makeMerchantPaymentData['error'];
//                } else {
//                    // Add the makeMerchantPayment response to the responses array
//                    $responses['makeMerchantPayment'] = $makeMerchantPaymentData;
//                }
//
//            }
        } else {
            // If issueInvoice failed, add the error response
            Log::error('Failed to issue invoice');
            $responses['error'] = $issueInvoiceData['error'];
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
        $type = $request->input('type');
        $iteration = 1;

        $transactionId = 'txn_' . $iteration . '_' . round(microtime(true) * 1000);

// Remove the country code and spaces
        $edahabNumber = str_replace(['+252', ' '], '', $phoneNumber);

        // Check if a merchant with the provided phone number already exists
        $merchantCount = Merchant::where('phone_number', $edahabNumber)->count();

        if ($merchantCount > 0) {
            Log::error('Merchant mobile number is already registered');

            return $this->sendError('Merchant mobile number is already registered', '');
        }

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
            $response = Http::timeout(env('API_TIMEOUT'))->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

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
                    'status' => 'Pending',
                    'e_transaction_id' => $transactionId,
                    'type' => $type,
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

                return $this->sendError('error', 'Failed to issue invoice ' . $response->body(), 500);
            }
        } catch (\Exception $e) {
            // Rollback the transaction if any exception occurs
            DB::rollBack();
            Log::error('Error in issueInvoice: ' . $e->getMessage());
            return $this->sendError('error', 'An error occurred while issuing the invoice ' . $e->getMessage(), 500);
        }
    }

    // Check invoice status
    public function checkInvoiceStatus(Request $request)
    {
        // Get api_key from the request and secret from .env
        $apiKey = env('EXELO_API_KEY'); // From .env
        $secret = env('SECRET_KEY'); // Secret from .env
        $invoiceId = $request->input('invoice_id');
        $maxAttempts = 5; // Max attempts (5 attempts * 5 seconds = 25 seconds)
        $attempts = 0; // Initialize attempt counter

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

            while ($attempts < $maxAttempts) {
                // Send the API request
                $response = Http::timeout(env('API_TIMEOUT'))->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

                if ($response->status() === 200) {
                    $responseData = $response->json();
                    $invoiceStatus = $responseData['InvoiceStatus'];  // Assuming the API returns 'InvoiceStatus'
                    $eTransactionId = $responseData['TransactionId'];  // Assuming the API returns 'TransactionId'

                    // Get the current invoice from the database
                    $invoice = Invoice::where('invoice_id', $invoiceId)->first();

                    if ($invoice) {
                        if ($invoice->status == 'Paid') {
                            return $this->sendResponse(
                                [
                                    'invoice_status' => $invoice->status,
                                    'e_transaction_id' => $invoice->e_transaction_id
                                ],
                                'Invoice status retrieved successfully from database (Paid)'
                            );
                        }

                        // If the API response is 'Paid', update the invoice and return success
                        if ($invoiceStatus == 'Paid') {
                            $invoice->update([
                                'status' => $invoiceStatus, // Assuming 'status' column exists in the table
                                'e_transaction_id' => $eTransactionId
                            ]);

                            DB::commit();

                            return $this->sendResponse(
                                [
                                    'invoice_status' => $invoiceStatus,
                                    'e_transaction_id' => $eTransactionId
                                ],
                                'Invoice status updated successfully'
                            );
                        }

                        // If the status is still 'Pending', just sleep for 5 seconds and try again
                        if ($invoiceStatus == 'Pending') {
                            DB::rollBack();  // No need to commit, since no changes were made
                            sleep(5); // Wait for 5 seconds before checking again
                            $attempts++;
                            continue; // Repeat the loop
                        }
                    } else {
                        DB::rollBack();
                        return $this->sendError('error', 'Invoice not found in the database', 404);
                    }
                } else {
                    DB::rollBack();
                    Log::error('Failed to issue invoice', ['response' => $response->body()]);

                    return $this->sendError('error', 'Failed to check invoice status from API ' . $response->body(), 500);
                }
            }

            // If max attempts are reached and the status is not 'Paid'
            return $this->sendError('error', 'Failed to get invoice status as "Paid" within the timeout', 500);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error in checkInvoiceStatus: ' . $e->getMessage());
            return $this->sendError('error', 'An error occurred while checking the invoice status ' . $e->getMessage(), 500);
        }
    }

    // Initiate merchant payment
    public function makeMerchantPayment(Request $request)
    {
        $apiKey = $request->input('api_key');
        $amountSentToMerchant = $request->input('amount_sent_to_merchant');
        $currency = $request->input('currency');
        $transactionId = 'mp_' . round(microtime(true) * 1000);


        if ($request->phone_number) {
            // for Zaad payment
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
            "phoneNumber" => str_replace('+252', '', $phoneNumber),
            "transactionAmount" => $amountSentToMerchant,
            "transactionId" => $transactionId,
            "currency" => $currency
        ];

//        return response()->json($payload,200);

        $bodyStr = json_encode($payload);
        $secret = 'kgBnW9Paa7ZErxB4GFo81FaASDFTQKhiOLxryw';
        $hashValue = $this->generateHash($bodyStr, $secret);
        $url = "https://edahab.net/api/api/agentPayment?hash=$hashValue";

        $response = Http::timeout(env('API_TIMEOUT'))->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

        if ($response->status() === 200) {
            $responseData = $response->json();

            if ($responseData['TransactionId'] == null) {
                return $this->sendError($responseData['TransactionMesage'], '', 500);
//                return response()->json(['error' => $responseData['TransactionMesage']], 500);
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

        } else {
            Log::error('Failed to make merchant payment', ['response' => $response->body()]);

            return response()->json(['error' => 'Failed to make merchant payment ' . $response->body()], 500);
        }
    }

    protected function callWaafiAPIForPreAuthorize(Request $request)
    {

        // Generate referenceId and invoiceId
        $referenceId = rand(100000, 999999); // 6-digit random number
        $invoiceId = rand(100000, 999999);   // 6-digit random number

        $accountNo = '252' . $request->input('edahab_number'); // Phone number
        $amount = $request->input('total_customer_charge');  // Amount to be paid
        $currency = $request->input('currency', 'SLSH');  // Currency
        $type = $request->input('type', 'POS');  // Type of invoice

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
            $response = Http::timeout(env('API_TIMEOUT'))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.waafipay.net/asm', $payload);

//            $invoiceData = [
//                "schemaVersion" => "1.0",
//                "timestamp" => "2024-09-06 11:46:41.426",
//                "responseId" => "7897342012",
//                "responseCode" => "2001",
//                "errorCode" => "0",
//                "responseMsg" => "RCS_SUCCESS",
//                "params" => [
//                    "state" => "APPROVED",
//                    "referenceId" => "582591",
//                    "transactionId" => "42630206",
//                    "txAmount" => "500.0"
//                ]
//            ];

            // Return the response body as JSON
            if ($response->successful()) {
//            if (true) {
                $invoiceData = $response->json();

                if ($invoiceData['errorCode'] == 0) {
                    // Simulating database insertion of the transaction details
                    $invoice = Invoice::create([
                        'invoice_id' => $invoiceData['params']['referenceId'],
                        'mobile_number' => str_replace('252', '', $accountNo),
                        'transaction_id' => $invoiceData['params']['transactionId'],
                        'hash' => 0,
                        'amount' => $invoiceData['params']['txAmount'],
                        'currency' => $currency,
                        'status' => 'Pending',
                        'type' => $type,
                    ]);

                    return [
                        'success' => true,
                        'message' => 'Zaad Invoice Issue Successful.',
                        'status' => $invoiceData['errorCode'],
                        'data' => [
                            'referenceId' => $invoiceData['params']['referenceId'],
                            'transactionId' => $invoiceData['params']['transactionId'],
                            'amount' => $invoiceData['params']['txAmount'],
                            'status' => $invoiceData['params']['state'],
                            'invoice_id' => $invoice->invoice_id,
                            'mobile_number' => str_replace('252', '', $accountNo),
                        ]
                    ];

                } else {
                    return [
                        'success' => false,
                        'message' => 'Failed to Zaad Issue Invoice',
                        'status' => $invoiceData['errorCode'],
                        'mobile_number' => $accountNo,
                        'error' => $invoiceData['responseMsg']
                    ];
                }


            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to Zaad Issue Invoice',
                    'status' => $response->status(),
                    'error' => $response->body()
                ];
            }
        } catch (\Exception $e) {
            // Handle exceptions and log errors
            \Log::error('Failed to Zaad Issue Invoice' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to Zaad Issue Invoice',
                'error' => $e->getMessage()
            ];
        }


    }

    protected function connectToWaafiCommitAPI(Request $request)
    {
        // Generate random requestId, sessionId and timestamp
        $requestId = rand(100000, 999999);
        $sessionId = rand(100000, 999999);
        $timestamp = now()->toIso8601String(); // Current timestamp in ISO format

        // Fetch the required values from environment or input
        $merchantUid = env('WAAFI_MERCHANT_UID', 'M0913698');
        $apiUserId = env('WAAFI_API_USER_ID', '1007586');
        $apiKey = env('WAAFI_API_KEY', 'API-282358994AHX');
        $referenceId = $request->input('reference_id');
        $transactionId = $request->input('transactionId');
        $invoiceId = $request->input('invoice_id');

        // Build the payload for the API request
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
                "apiKey" => $apiKey,
                "transactionId" => $transactionId,
                "referenceId" => $referenceId,
                "description" => "Commit transaction",
            ]
        ];

        // Set maximum attempts and delay between retries
        $maxAttempts = 5;
        $attempts = 0;

        try {
            // Begin database transaction
            DB::beginTransaction();

            // Keep checking the transaction status until it's approved or max attempts are reached
            while ($attempts < $maxAttempts) {
                // Make the API request
                $response = Http::timeout(env('API_TIMEOUT'))
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('https://api.waafipay.net/asm', $payload);

                // If the API call was successful
                if ($response->successful()) {
                    $responseData = $response->json();
                    $invoiceResponse = $responseData['params'];

                    // Check for success and if transaction is approved
                    if ($responseData['errorCode'] == 0 && $invoiceResponse && $invoiceResponse['state'] == 'approved') {
                        // Find the invoice in the database
                        $invoice = Invoice::where('invoice_id', $invoiceId)->first();

                        // If invoice exists, check its current status
                        if ($invoice) {
                            $amount = $invoice->amount;
                            $currency = $invoice->currency;

                            // If the invoice is already marked as "Paid", return an error
                            if ($invoice->status == 'Paid') {
                                return $this->sendError([], 'Invoice already paid on amount of ' . $amount . ' ' . $currency);
                            }

                            // Update the invoice status to "Paid"
                            $invoice->update([
                                'status' => 'Paid',
                                'e_transaction_id' => $invoiceResponse['transactionId']
                            ]);

                            // Commit the database changes
                            DB::commit();

                            // Return a successful response
                            return $this->sendResponse([
                                'status' => $invoiceResponse['state'],
                                'e_transaction_id' => $invoiceResponse['transactionId'],
                                'amount' => $amount,
                                'currency' => $currency,
                            ], 'Invoice committed successfully');
                        } else {
                            return $this->sendError([], 'No invoice found');
                        }
                    }

                    // If the transaction is not yet approved, wait for 5 seconds and retry
                    sleep(5);
                    $attempts++;
                } else {
                    // If the API request fails, return an error with the response details
                    return $this->sendError([], 'Failed to commit invoice', 500, [
                        'status' => $response->status(),
                        'error' => $response->body()
                    ]);
                }
            }

            // If the maximum number of attempts is reached without success
            return $this->sendError([], 'Transaction was not approved within the allowed time frame');
        } catch (\Exception $e) {
            // In case of any exception, log the error and return a failure response
            \Log::error('API Commit exception', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'An error occurred while committing the transaction',
                'details' => $e->getMessage()
            ], 500);
        }
    }

}
