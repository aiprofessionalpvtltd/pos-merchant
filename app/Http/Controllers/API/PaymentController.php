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
//    public function processTransaction(Request $request)
//    {
//
//        // Validate incoming request
//        $validator = $this->validateRequest($request, [
//            'transaction_amount' => 'required|numeric',
//        ]);
//
//        if ($validator->fails()) {
//            return $this->sendError('Validation Error.', $validator->errors());
//        }
//
//        $transactionAmount = $request->transaction_amount;
//
//        // Add 2% to the amount entered by the customer (customer fee)
//        $customerFee = $transactionAmount * 0.02;
//        $totalCustomerCharge = $transactionAmount + $customerFee;
//
//        // Deduct 1% from the amount to be sent to the merchant (Exelo fee)
//        $exeloFee = $transactionAmount * 0.01;
//        $amountSentToMerchant = $transactionAmount - $exeloFee;
//
//        // Exelo keeps both the customer fee and the Exelo fee (1% deducted from merchant)
//        $amountKeptByExelo = $customerFee + $exeloFee;
//
//        return response()->json([
//            'customer_fee' => round($customerFee),
//            'exelo_fee' => round($exeloFee),
//            'amount_kept_by_exelo' => round($amountKeptByExelo),
//            'amount_sent_to_merchant' => round($amountSentToMerchant),
//            'total_customer_charge' => round($totalCustomerCharge),
//        ]);
//    }


    public function processTransaction(Request $request)
    {
        // Validate incoming request
        $validator = $this->validateRequest($request, [
            'transaction_amount' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $transactionAmount = $request->transaction_amount;

        // Determine the authenticated user
        $authUser = auth()->user();

        // Check subscription plan and set fees accordingly
        if ($authUser && $authUser->merchant && $authUser->merchant->currentSubscription) {
            $subscriptionPlanId = $authUser->merchant->currentSubscription->subscription_plan_id;

            if ($subscriptionPlanId == 1) { // Gold Plan
                // Exelo fee for customers: 2.85%, merchants: 0%
                $customerFee = $transactionAmount * 0.0285;
                $exeloFee = 0; // Merchant fee
            } elseif ($subscriptionPlanId == 2) { // Silver Plan
                // Exelo fee for merchants: 2.85%, customers: 0%
                $customerFee = 0; // Customer fee
                $exeloFee = $transactionAmount * 0.0285;
            }
        } else {
            // Default to Silver Plan
            $customerFee = 0; // Customer fee
            $exeloFee = $transactionAmount * 0.0285; // Exelo fee for merchants: 2.85%
        }

        // Calculate the total amounts
        $totalCustomerCharge = $transactionAmount + $customerFee;
        $amountSentToMerchant = $transactionAmount - $exeloFee;
        $amountKeptByExelo = $customerFee + $exeloFee;

        // Convert SLHS to dollars (1 DOLLAR = 9200 SLHS)
        $amountInDollars = convertShillingToUSD($transactionAmount);

        return response()->json([
            'customer_fee' => round($customerFee),
            'exelo_fee' => round($exeloFee),
            'amount_kept_by_exelo' => round($amountKeptByExelo),
            'amount_sent_to_merchant' => round($amountSentToMerchant),
            'total_customer_charge' => round($totalCustomerCharge),
            'amount_in_dollars' => round($amountInDollars, 2) // Add dollar conversion
        ]);
    }

    // Generate transaction hash
    private function generateHash($body, $secret)
    {
        return hash('sha256', $body . $secret);
    }

    // check invoice already issue

    public function checkInvoice(Request $request)
    {
        // Validate incoming request
        $validator = $this->validateRequest($request, [
            'phone_number' => 'required|numeric',
            'type' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $phoneNumber = $request->input('phone_number');

        // Check if a merchant with the provided phone number already exists
        $merchantCount = Merchant::where('phone_number', $phoneNumber)->count();

        if ($merchantCount > 0) {
            return $this->sendError('Merchant mobile number is already registered', '');
        }

        // Remove spaces from phone number
        $phoneNumber = str_replace(' ', '', $phoneNumber);

        // Check if an invoice with the status 'Paid' exists for this phone number
        $paidInvoice = Invoice::where('mobile_number', $phoneNumber)
            ->where('status', 'Paid')
            ->where('type', $request->type)
            ->first();

        if ($paidInvoice) {
            // If a 'Paid' invoice exists, return the status
            return $this->sendResponse('Invoice status is Paid.', 'Paid');
        } else {
            // If no 'Paid' invoice exists, delete all other invoices for the phone number
            Invoice::where('mobile_number', $phoneNumber)->where('status', '!=', 'Paid')->delete();

            return $this->sendResponse('All non-paid invoices deleted for the phone number.', 'Invoices deleted');
        }
    }

    // Issue an invoice
    public function issueInvoice(Request $request)
    {

        // Fetch api_key and agent_code from .env
        $apiKey = env('EXELO_API_KEY');
        $agentCode = env('EXELO_AGENT_CODE');
        $secret = env('SECRET_KEY');

        $firstName = '';
        $lastName = '';
        $merchantID = NULL;

        // Inputs from the request
        $firstName = $request->input('first_name');
        $lastName = $request->input('last_name');
        $phoneNumber = $request->input('edahab_number');
        $totalCustomerCharge = $request->input('total_customer_charge');
        $currency = $request->input('currency');
        $type = $request->input('type');
        $paymentMethod = $request->input('payment_method');

        if ($type === 'POS' || $type == 'Subscription') {
            $merchantID = $request->input('merchant_id');
        }

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
                $invoice = Invoice::create([
                    'merchant_id' => $merchantID,
                    'invoice_id' => $invoiceData['InvoiceId'],
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'mobile_number' => $phoneNumber,
                    'transaction_id' => $transactionId,
                    'hash' => $hashValue,
                    'amount' => $totalCustomerCharge,
                    'currency' => $currency,
                    'status' => 'Pending',
                    'e_transaction_id' => $transactionId,
                    'type' => $type,
                    'payment_method' => $paymentMethod ?? 'number',
                ]);


                // Commit the transaction if everything is successful
                DB::commit();

                return $this->sendResponse(
                    [
                        'id' => $invoice->id,
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
        $paymentMethod = $request->input('payment_method');


        if ($request->phone_number) {
            // for Zaad payment
            $phoneNumber = $request->input('phone_number');
            $phoneNo = '+252' . $request->input('phone_number');
            // Check if a merchant with the provided phone number already exists
            $merchant = Merchant::where('phone_number', $phoneNo)->first();
        } else {

            $apiKey = env('EXELO_API_KEY'); // From .env
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }


            $phoneNumber = $authUser->merchant->edahab_number;

            if (!$phoneNumber) {
                return $this->sendError('Merchant e-dahab mobile number is not Verified', '');
            }

            $merchant = Merchant::where('edahab_number', $phoneNumber)->first();


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


        $bodyStr = json_encode($payload);
        $secret = env('SECRET_KEY');

        $hashValue = $this->generateHash($bodyStr, $secret);
        $url = "https://edahab.net/api/api/agentPayment?hash=$hashValue";

        $response = Http::timeout(env('API_TIMEOUT'))->withHeaders(['Content-Type' => 'application/json'])->post($url, $payload);

        if ($response->status() === 200) {
            $responseData = $response->json();

            if ($responseData['TransactionId'] == null) {
                return $this->sendError($responseData['TransactionMesage'], '', 500);
            }

            // Save the response data to the database
            $transaction = Transaction::create([
                'transaction_amount' => $amountSentToMerchant,
                'transaction_status' => $responseData['TransactionStatus'],
                'transaction_message' => $responseData['TransactionMesage'],
                'phone_number' => $responseData['PhoneNumber'],
                'transaction_id' => $responseData['TransactionId'],
                'merchant_id' => $merchant->id,
                'payment_method' => $paymentMethod ?? 'number',

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

        $accountNo = $request->input('edahab_number'); // Phone number
        $accountNo = str_replace('+', '', $accountNo);

        // Verify phone number and get the specific company column
        $verifiedNumber = $this->verifiedPhoneNumber($accountNo);

        // If the phone number is invalid or company not recognized, return error
        if ($verifiedNumber != 'zaad_number') {
            return $this->sendError('Zaad phone number. Zaad not recognized.');
        }

        $amount = $request->input('total_customer_charge');  // Amount to be paid
        $currency = $request->input('currency', 'SLSH');  // Currency
        $type = $request->input('type');  // Type of invoice
        $merchantID = NULL;
        $paymentMethod = $request->input('payment_method');

        if ($type === 'POS' || $type == 'Subscription') {
            $merchantID = $request->input('merchant_id');
        }

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

//        dd($payload);
        try {
            // Send the API request using Guzzle (Http facade)
            $response = Http::timeout(env('API_TIMEOUT'))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.waafipay.net/asm', $payload);

            // Return the response body as JSON
            if ($response->successful()) {
//            if (true) {
                $invoiceData = $response->json();

                if ($invoiceData['errorCode'] == 0) {

                    // Simulating database insertion of the transaction details
                    $invoice = Invoice::create([
                        'merchant_id' => $merchantID,
                        'invoice_id' => $invoiceData['params']['referenceId'],
                        'mobile_number' => str_replace('252', '', $accountNo),
                        'transaction_id' => $invoiceData['params']['transactionId'],
                        'hash' => 0,
                        'amount' => $invoiceData['params']['txAmount'],
                        'currency' => $currency,
                        'status' => 'Pending',
                        'type' => $type,
                        'payment_method' => $paymentMethod ?? 'number',

                    ]);

                    return [
                        'success' => true,
                        'message' => 'Zaad Invoice Issue Successful.',
                        'status' => $invoiceData['errorCode'],
                        'data' => [
                            'id' => $invoice->id,
                            'referenceId' => $invoiceData['params']['referenceId'],
                            'transactionId' => $invoiceData['params']['transactionId'],
                            'amount' => $invoiceData['params']['txAmount'],
                            'currency' => $currency,
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
        $amount = $request->input('amount');
        $currency = $request->input('currency');

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

//            // If the maximum number of attempts is reached without success
//            return $this->sendError([], 'Transaction was not approved within the allowed time frame');

            // If maximum attempts are reached without success, cancel the transaction
            if ($attempts >= $maxAttempts) {
                // Call the cancelTransaction function when the attempts reach the max limit
                return $this->cancelWaafiTransaction($request);
            }


        } catch (\Exception $e) {
            // In case of any exception, log the error and return a failure response
            \Log::error('API Commit exception', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'An error occurred while committing the transaction',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    protected function cancelWaafiTransaction(Request $request)
    {
        // Generate random requestId, sessionId and timestamp
        $requestId = rand(100000, 999999);
        $sessionId = rand(100000, 999999);
        $timestamp = now()->toIso8601String(); // Current timestamp in ISO format

        // Fetch required values from environment or request input
        $merchantUid = env('WAAFI_MERCHANT_UID', 'M0913698');
        $apiUserId = env('WAAFI_API_USER_ID', '1007586');
        $apiKey = env('WAAFI_API_KEY', 'API-282358994AHX'); // API Key if needed for security
        $referenceId = $request->input('reference_id');
        $invoiceId = $request->input('invoice_id');
        $amount = $request->input('amount');
        $currency = $request->input('currency');

        // Build the payload for the API request
        $payload = [
            "schemaVersion" => "1.0",
            "requestId" => $requestId,
            "timestamp" => $timestamp,
            "channelName" => "WEB",
            "serviceName" => "API_CANCEL",
            "sessionId" => $sessionId,
            "serviceParams" => [
                "merchantUid" => $merchantUid,
                "apiUserId" => $apiUserId,
                "apiKey" => $apiKey,
                "transactionInfo" => [
                    "referenceId" => $referenceId,
                    "invoiceId" => $invoiceId,
                    "amount" => $amount,
                    "currency" => $currency,
                    "description" => "Cancel transaction"
                ]
            ]
        ];

        try {
            // Start a database transaction
            DB::beginTransaction();

            // Make the API request to Waafi API
            $response = Http::timeout(env('API_TIMEOUT'))
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.waafipay.net/asm', $payload);

            // Check if the response is successful
            if ($response->successful()) {
                $responseData = $response->json();

                // Assuming a responseCode of 0 means success, otherwise handle errors
                if ($responseData['errorCode'] == 0) {
                    // Find the invoice in the database
                    $invoice = Invoice::where('invoice_id', $invoiceId)->first();

                    if ($invoice) {
                        // Update the invoice status to "Cancelled"
                        $invoice->update([
                            'status' => 'Cancelled'
                        ]);

                        // Commit the database transaction
                        DB::commit();

                        // Return success response
                        return $this->sendResponse([
                            'status' => 'Cancelled',
                            'invoice_id' => $invoiceId,
                            'amount' => $amount,
                            'currency' => $currency
                        ], 'Transaction cancelled successfully');
                    } else {
                        return $this->sendError([], 'No invoice found');
                    }
                } else {
                    // Handle error response from API
                    return $this->sendError([], 'Failed to cancel transaction', 500, [
                        'errorCode' => $responseData['errorCode'],
                        'errorMessage' => $responseData['responseMsg']
                    ]);
                }
            } else {
                // Handle non-200 HTTP response
                return $this->sendError([], 'Failed to communicate with the API', 500, [
                    'status' => $response->status(),
                    'error' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            // Rollback any database changes on failure
            DB::rollBack();

            // Log the exception and return a failure response
            \Log::error('API Cancel exception', ['message' => $e->getMessage()]);
            return response()->json([
                'error' => 'An error occurred while cancelling the transaction',
                'details' => $e->getMessage()
            ], 500);
        }
    }

}
