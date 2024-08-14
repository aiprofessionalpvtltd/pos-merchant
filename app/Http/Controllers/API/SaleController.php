<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Exception;

class SaleController extends BaseController
{
    /**
     * Process a sale
     */

    public function processSale(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card',
            'currency' => 'required|in:USD,SLS',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            DB::beginTransaction();
            $user = auth()->user();
            $authUser = $user->merchant;

            // Calculate payment details
            $paymentDetails = $this->calculatePaymentDetails($request->amount, $request->currency);

            // Extract relevant details for Merchant and Exelo transactions
            $amount_to_merchant = $paymentDetails['amount_to_merchant'];
            $amount_to_exelo = $paymentDetails['amount_to_exelo'];

            // Create the sale record
            $sale = Sale::create([
                'merchant_id' => $authUser->id,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'is_completed' => $request->payment_method === 'cash' ? true : false,
                'is_successful' => false,

                'total_amount_after_conversion' => $paymentDetails['total_amount_after_conversion'],
                'amount_to_merchant' => $amount_to_merchant,
                'conversion_fee_amount' => $paymentDetails['conversion_fee_amount'],
                'transaction_fee_amount' => $paymentDetails['transaction_fee_amount'],
                'total_fee_charge_to_customer' => $paymentDetails['total_fee_charge_to_customer'],
                'amount_sent_to_exelo' => $amount_to_exelo,
                'total_amount_charge_to_customer' => $paymentDetails['total_amount_charge_to_customer'],
                'conversion_rate' => $paymentDetails['conversion_rate'],
                'currency' => $paymentDetails['currency'],
            ]);

            // Simulate sending payment details to the payment gateway for Merchant
            $merchantPayment = $this->initiatePaymentGatewayForMerchant($sale);

            // Simulate sending payment details to the payment gateway for Exelo
            $exeloPayment = $this->initiatePaymentGatewayForExelo($sale);

//            dd($merchantPayment,$exeloPayment);
            // Create payment record for Merchant
            $merchantPaymentRecord = Payment::create([
                'sale_id' => $sale->id,
                'amount' => $sale->amount,
                'payment_method' => $sale->payment_method,
                'transaction_id' => $merchantPayment['transaction_id'],
                'amount_to_merchant' => $merchantPayment['amount_to_merchant'],
                'is_successful' => true,
            ]);

            // Create payment record for Exelo
            $exeloPaymentRecord = Payment::create([
                'sale_id' => $sale->id,
                'amount' => $sale->amount,
                'payment_method' => $sale->payment_method,
                'transaction_id' => $exeloPayment['transaction_id'],
                'amount_to_exelo' => $exeloPayment['amount_to_exelo'],
                'is_successful' => true,
            ]);
            DB::commit();
            return $this->sendResponse([
                'sale' => new SaleResource($sale),
                'merchant_payment' => new PaymentResource($merchantPaymentRecord),
                'exelo_payment' => new PaymentResource($exeloPaymentRecord)
            ], 'Sale processed successfully.');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError('An error occurred during the sale process.', ['error' => $e->getMessage()]);
        }
    }

    // Simulate sending payment to Merchant
    private function sendPaymentToMerchant($amount, $merchant_id)
    {

    }

    // Simulate sending payment to Exelo
    private function sendPaymentToExelo($amount)
    {

    }


    /**
     * Simulate initiating card payment
     */
    private function initiatePaymentGatewayForMerchant($sale)
    {
        // Generate a random transaction ID
        $transactionId = 'TXN' . strtoupper(uniqid());

        return [
            'transaction_id' => $transactionId,
            'amount_to_merchant' => $sale->amount_to_merchant,
            'merchant_id' => $sale->merchant_id,
        ];
    }

    private function initiatePaymentGatewayForExelo($sale)
    {
        // Generate a random transaction ID
        $transactionId = 'TXN' . strtoupper(uniqid());

        return [
            'transaction_id' => $transactionId,
            'amount_to_exelo' => $sale->amount_sent_to_exelo,
        ];
    }

    // Payment calculation logic
    private function calculatePaymentDetails($transaction_amount, $selected_currency)
    {
        // Define the conversion rate
        $conversion_rate = 8000; // 1 USD = 8,000 SLS

        // Define fees
        $conversion_fee_rate = 0.005; // 0.5%
        $transaction_fee_rate = 0.02; // 2%

        // Default values for SLS
        $currency = 'SLS';
        $conversion_fee_amount = 0;
        $total_amount_after_conversion = $transaction_amount;
        $transaction_fee_amount = $transaction_amount * $transaction_fee_rate;

        if ($transaction_amount >= 800000 && $selected_currency === 'USD') {
            // Convert to USD if the selected currency is USD and amount is >= 800,000 SLS
            $currency = 'USD';
            $total_amount_after_conversion = $transaction_amount / $conversion_rate;
            $conversion_fee_amount = $total_amount_after_conversion * $conversion_fee_rate;
            $transaction_fee_amount = $total_amount_after_conversion * $transaction_fee_rate;
        }

        // Calculate the total fee charged to the customer
        $total_fee_charge_to_customer = $conversion_fee_amount + $transaction_fee_amount;

        // Calculate the total amount charged to the customer
        $total_amount_charge_to_customer = $total_amount_after_conversion + $total_fee_charge_to_customer;

        // Return the calculated values
        return [
            'total_amount_after_conversion' => round($total_amount_after_conversion, 2),
            'amount_to_merchant' => round($total_amount_after_conversion, 2),
            'conversion_fee_amount' => round($conversion_fee_amount, 2),
            'transaction_fee_amount' => round($transaction_fee_amount, 2),
            'total_fee_charge_to_customer' => round($total_fee_charge_to_customer, 2),
            'amount_to_exelo' => round($total_fee_charge_to_customer, 2),
            'total_amount_charge_to_customer' => round($total_amount_charge_to_customer, 2),
            'conversion_rate' => $conversion_rate,
            'currency' => $currency,
        ];
    }


    /**
     * Confirm card payment
     */


    public function confirmPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'transaction_id' => 'required|string|exists:payments,transaction_id',
            'is_successful' => 'required|boolean'
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            $payment = Payment::where('transaction_id', $request->transaction_id)->first();

            if (!$payment) {
                return $this->sendError('Payment not found.');
            }

            $payment->is_successful = $request->is_successful;
            $payment->save();

            if ($request->is_successful) {
                $payment->sale->is_successful = true;
                $payment->sale->is_completed = true;
                $payment->sale->save();
            }

            return $this->sendResponse(['sale' => new SaleResource($payment->sale), 'payment' => new PaymentResource($payment)], 'Payment confirmation process completed.');
        } catch (Exception $e) {
            return $this->sendError('An error occurred during the payment confirmation process.', ['error' => $e->getMessage()]);
        }
    }


}
