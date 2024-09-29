<?php

namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Sale;
use App\Models\Transaction;
use Carbon\Carbon;
use DB;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use pdf;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Middlewares\PermissionMiddleware;
use Yajra\DataTables\DataTables;


class MerchantController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-merchant'])->only(['index', 'show']);
        $this->middleware(['permission:edit-merchant'])->only(['edit', 'update', 'resetID', 'changePassword', 'change']);
        $this->middleware(['permission:create-merchant'])->only(['create', 'store']);
        $this->middleware(['permission:delete-merchant'])->only('destroy');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            $merchants = Merchant::with('user')->get();
            return DataTables::of($merchants)
                ->addColumn('action', function ($merchant) {
                    $viewBtn = '';
                    $deleteBtn = '';

                    if (auth()->user()->can('view-merchant')) {
                        $viewBtn = '<a title="View" href="' . route('view-merchant', $merchant->id) . '" class="badge bg-primary m-1"><i class="fas fa-fw fa-eye"></i></a>';
                    }

                    if (auth()->user()->can('delete-merchant')) {
                        $deleteBtn = '<a href="javascript:void(0)" data-url="' . route('delete-merchant') . '" data-status="0" data-label="delete" data-id="' . $merchant->id . '" class="badge bg-danger m-1 change-status-record" title="Delete Record"><i class="fas fa-trash"></i></a>';
                    }

                    return '<div class="d-flex">' . $viewBtn . $deleteBtn . '</div>';
                })
                ->rawColumns(['action'])
                ->make(true);
        }

        $title = 'Add Merchant';
        return view('admin.merchant.index', compact('title'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function view($id)
    {
        $title = 'View Merchant';
        $merchant = Merchant::with('user')->find($id);

        if (!$merchant) {
            return redirect()->route('show-merchant')->with('error', 'Merchant not found.');
        }

        // Load orders, invoices, and transactions
        $orders = Order::where('merchant_id', $merchant->id)->get();
        $invoices = Invoice::where('merchant_id', $merchant->id)->get();
        $transactions = Transaction::where('merchant_id', $merchant->id)->get();

        return view('admin.merchant.view', compact('merchant', 'title', 'orders', 'invoices', 'transactions'));
    }




    public function delete(Request $request)
    {
        $merchant = Merchant::find($request->id);

        if (!$merchant) {
            return response()->json(['error' => 'Merchant not found.'], 404);
        }

        $merchant->delete();

        return response()->json(['success' => 'Merchant has been deleted successfully.']);
    }




}
