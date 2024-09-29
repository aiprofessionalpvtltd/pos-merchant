<?php

namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use App\Models\Invoice;
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


class InvoiceController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-invoice'])->only(['index', 'show']);
        $this->middleware(['permission:edit-invoice'])->only(['edit', 'update', 'resetID', 'changePassword', 'change']);
        $this->middleware(['permission:create-invoice'])->only(['create', 'store']);
        $this->middleware(['permission:delete-invoice'])->only('destroy');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request)
    {
        if ($request->ajax()) {
            $invoices = Invoice::with('merchant')->select('invoices.*');

            return DataTables::of($invoices)
                ->addColumn('merchant', function ($invoice) {
                    return $invoice->merchant->first_name . ' ' . $invoice->merchant->last_name . ' (' . $invoice->merchant->business_name . ')';
                })
                ->addColumn('type', function ($invoice) {
                    return $invoice->type;
                })
                ->addColumn('mobile_number', function ($invoice) {
                    return $invoice->mobile_number;
                })
                ->addColumn('transaction_id', function ($invoice) {
                    return $invoice->transaction_id;
                })
                ->addColumn('amount', function ($invoice) {
                    return $invoice->amount;
                })
                ->addColumn('currency', function ($invoice) {
                    return $invoice->currency;
                })
                ->addColumn('status', function ($invoice) {
                    return $invoice->status;
                })
                ->rawColumns(['merchant', 'type', 'mobile_number', 'transaction_id', 'amount', 'currency', 'status'])
                ->make(true);
        }

        $title = 'All Invoices';
        return view('admin.invoice.index', compact('title'));
    }





}
