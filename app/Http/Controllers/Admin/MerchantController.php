<?php

namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Sale;
use App\Models\Transaction;
use App\Services\MerchantDirectoryService;
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
        $this->middleware(['permission:view-merchant'])->only(['index', 'show', 'view']);
        $this->middleware(['permission:edit-merchant'])->only(['edit', 'update', 'resetID', 'changePassword', 'change']);
        $this->middleware(['permission:create-merchant'])->only(['create', 'store']);
        $this->middleware(['permission:delete-merchant'])->only(['destroy', 'delete']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request, MerchantDirectoryService $directory)
    {
        if ($request->ajax()) {
            // A query, not ->get(): DataTables pages, sorts and searches in the database.
            $merchants = Merchant::query()->with(['user', 'currentSubscription.subscriptionPlan']);

            $rows = [];
            $row = function (Merchant $merchant) use (&$rows, $directory) {
                return $rows[$merchant->id] ??= $directory->listRow($merchant);
            };

            return DataTables::of($merchants)
                ->addColumn('name', fn (Merchant $merchant) => trim($merchant->first_name . ' ' . $merchant->last_name))
                ->filterColumn('name', fn ($query, $keyword) => $query->whereRaw("concat_ws(' ', first_name, last_name) like ?", ["%{$keyword}%"]))
                ->orderColumn('name', 'first_name $1')
                ->addColumn('address', fn (Merchant $merchant) => $row($merchant)['address'])
                ->filterColumn('address', fn ($query, $keyword) => $query->where(function ($inner) use ($keyword) {
                    $inner->where('location', 'like', "%{$keyword}%")
                        ->orWhere('city', 'like', "%{$keyword}%")
                        ->orWhere('state', 'like', "%{$keyword}%");
                }))
                ->addColumn('plan', fn (Merchant $merchant) => $row($merchant)['plan'])
                ->addColumn('pin', fn (Merchant $merchant) => $row($merchant)['pin'])
                ->addColumn('wallets', fn (Merchant $merchant) => $row($merchant)['wallets'])
                ->editColumn('created_at', fn (Merchant $merchant) => $merchant->created_at?->format('d M Y'))
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
    public function view($id, MerchantDirectoryService $directory)
    {
        $title = 'View Merchant';
        $merchant = Merchant::with('user')->find($id);

        if (!$merchant) {
            return redirect()->route('admin.merchant.index')->with('error', 'Merchant not found.');
        }

        $detail = $directory->detail($merchant);

        return view('admin.merchant.view', compact('merchant', 'title', 'detail'));
    }




    public function delete(Request $request)
    {
        $merchant = Merchant::find($request->id);

        if (!$merchant) {
            return response()->json(['error' => 'Merchant not found.'], 404);
        }
        // All or nothing: a half-deleted merchant would keep a usable login.
        DB::transaction(function () use ($merchant) {
            $merchant->update([
                'phone_number' => 0,
                'edahab_number' => 0,
                'zaad_number' => 0,
                'golis_number' => 0,
                'evc_number' => 0,
            ]);

            $merchant->delete();

            if ($merchant->user->exists) {
                $merchant->user->update([
                    'email' => 'deleted' . $merchant->id . '@email.com',
                ]);
                $merchant->user->delete();
            }
        });

        return response()->json(['success' => 'Merchant has been deleted successfully.']);
    }




}
