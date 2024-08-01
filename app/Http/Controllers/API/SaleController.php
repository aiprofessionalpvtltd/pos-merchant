<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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
            'merchant_id' => 'required|exists:merchants,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,card'
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            $sale = Sale::create([
                'merchant_id' => $request->merchant_id,
                'amount' => $request->amount,
                'payment_method' => $request->payment_method,
                'is_completed' => $request->payment_method === 'cash' ? true : false,
                'is_successful' => false,
            ]);

            if ($request->payment_method === 'card') {
                // Simulate sending payment details to payment gateway
                $paymentDetails = $this->initiateCardPayment($sale);

                $payment = Payment::create([
                    'sale_id' => $sale->id,
                    'amount' => $sale->amount,
                    'payment_method' => $sale->payment_method,
                    'transaction_id' => $paymentDetails['transaction_id'],
                    'is_successful' => false,
                ]);
            }

            return $this->sendResponse(['sale' =>new SaleResource($sale),'payment' => new PaymentResource($payment)], 'Sale processed successfully.');
        } catch (Exception $e) {
            return $this->sendError('An error occurred during the sale process.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Simulate initiating card payment
     */
    private function initiateCardPayment(Sale $sale)
    {
        // Simulate interaction with payment gateway here
        // Generate a random transaction ID
        $transactionId = 'TXN' . strtoupper(uniqid());

        return [
            'transaction_id' => $transactionId
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

            return $this->sendResponse(['sale' =>new SaleResource($payment->sale),'payment' => new PaymentResource($payment)], 'Payment confirmation process completed.');
        } catch (Exception $e) {
            return $this->sendError('An error occurred during the payment confirmation process.', ['error' => $e->getMessage()]);
        }
    }
}
