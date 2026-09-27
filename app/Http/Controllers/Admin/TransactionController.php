<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Yajra\DataTables\DataTables;

class TransactionController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-transaction'])->only(['index', 'show']);
        $this->middleware(['permission:edit-transaction'])->only(['edit', 'update', 'resetID', 'changePassword', 'change']);
        $this->middleware(['permission:create-transaction'])->only(['create', 'store']);
        $this->middleware(['permission:delete-transaction'])->only('destroy');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request)
    {
        if ($request->ajax()) {
            $transactions = Transaction::with(['merchant', 'invoice', 'order'])->select('transactions.*');

            return DataTables::of($transactions)
                ->filter(fn ($query) => $query->when($request->input('status'), fn ($inner, $status) => $inner->where('transaction_status', $status)), true)
                ->addColumn('type', fn (Transaction $transaction) => $transaction->invoice?->type ?: ($transaction->order_id ? 'Sale' : '—'))
                ->filterColumn('type', fn ($query, $keyword) => $query->whereHas('invoice', fn ($invoice) => $invoice->where('type', 'like', "%{$keyword}%")))
                ->addColumn('shop', function (Transaction $transaction) {
                    if (! $transaction->merchant) {
                        return '—'; // registration/verification: no shop exists yet
                    }

                    return $transaction->merchant->business_name ?: trim($transaction->merchant->first_name.' '.$transaction->merchant->last_name);
                })
                ->filterColumn('shop', fn ($query, $keyword) => $query->whereHas('merchant', fn ($merchant) => $merchant
                    ->where('business_name', 'like', "%{$keyword}%")
                    ->orWhere('first_name', 'like', "%{$keyword}%")
                    ->orWhere('last_name', 'like', "%{$keyword}%")))
                ->addColumn('merchant_account', fn (Transaction $transaction) => $transaction->merchant?->merchantAccount?->fullName())
                ->addColumn('amount', fn (Transaction $transaction) => $this->amount($transaction))
                ->addColumn('message', fn (Transaction $transaction) => $transaction->transaction_message)
                ->filterColumn('message', fn ($query, $keyword) => $query->where('transaction_message', 'like', "%{$keyword}%"))
                ->addColumn('status', fn (Transaction $transaction) => $transaction->transaction_status)
                ->filterColumn('status', fn ($query, $keyword) => $query->where('transaction_status', 'like', "%{$keyword}%"))
                ->editColumn('created_at', fn (Transaction $transaction) => $transaction->created_at?->format('d M Y H:i'))
                ->addColumn('action', function (Transaction $transaction) {
                    $buttons = '';

                    if ($transaction->invoice && $transaction->invoice->status === 'Paid') {
                        $buttons .= '<a class="btn btn-sm btn-outline-primary me-1" target="_blank" title="View invoice" href="'.route('admin.invoices.document', $transaction->invoice_id).'">Invoice</a>';
                    }

                    if ($transaction->order_id) {
                        $buttons .= '<a class="btn btn-sm btn-outline-secondary" title="View order" href="'.route('admin.orders.view', $transaction->order_id).'">Order</a>';
                    }

                    return $buttons;
                })
                // Only the action buttons are HTML; everything else is escaped, since names and messages are user input.
                ->rawColumns(['action'])
                ->make(true);
        }

        $title = 'All Transactions';

        return view('admin.transaction.index', compact('title'));
    }

    /**
     * `transaction_amount` is always shillings (see docs/data-model.md), shown
     * next to its USD equivalent at the rate the sale actually settled at: the
     * order's own frozen rate when there is one, else the shop's current rate.
     */
    private function amount(Transaction $transaction): string
    {
        $shillings = (float) $transaction->transaction_amount;
        $rate = (int) ($transaction->order?->exchange_rate ?: $transaction->merchant?->effectiveExchangeRate() ?: config('exelo.conversion_rate'));
        $usd = $rate > 0 ? $shillings / $rate : 0.0;

        return number_format($shillings).' SLSH ($'.number_format($usd, 2).')';
    }
}
