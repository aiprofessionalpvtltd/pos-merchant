<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Services\InvoicePaymentService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EdahabReturnController extends Controller
{
    public function __invoke(Request $request, InvoicePaymentService $payments): View
    {
        try {
            $invoice = $payments->returnFromEdahab($request->query());
        } catch (ApiException $e) {
            abort($e->status, $e->getMessage());
        }

        return view('payments.edahab-return', [
            'status' => strtolower($invoice->status),
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
        ]);
    }
}
