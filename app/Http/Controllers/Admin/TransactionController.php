<?php

namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\Sale;
use Carbon\Carbon;
use DB;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use pdf;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Middlewares\PermissionMiddleware;
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
            $transactions = Transaction::with('merchant')->select('transactions.*');

            return DataTables::of($transactions)
                ->addColumn('merchant_name', function ($transaction) {
                    return $transaction->merchant->first_name . ' ' . $transaction->merchant->last_name . ' (' . $transaction->merchant->business_name . ')';
                })
                ->addColumn('amount', function ($transaction) {
                    return $transaction->transaction_amount;
                })
                ->addColumn('phone_number', function ($transaction) {
                    return $transaction->phone_number;
                })
                ->addColumn('message', function ($transaction) {
                    return $transaction->transaction_message;
                })
                ->addColumn('transaction_id', function ($transaction) {
                    return $transaction->transaction_id;
                })
                ->addColumn('status', function ($transaction) {
                    return $transaction->transaction_status;
                })
                ->rawColumns(['merchant_name', 'amount', 'phone_number', 'message', 'transaction_id', 'status'])
                ->make(true);
        }

        $title = 'All Transactions';
        return view('admin.transaction.index', compact('title'));
    }


}
