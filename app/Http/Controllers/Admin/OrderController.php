<?php

namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use App\Models\Order;
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


class OrderController extends Controller
{

    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-order'])->only(['index', 'show']);
        $this->middleware(['permission:edit-order'])->only(['edit', 'update', 'resetID', 'changePassword', 'change']);
        $this->middleware(['permission:create-order'])->only(['create', 'store']);
        $this->middleware(['permission:delete-order'])->only('destroy');
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(Request $request)
    {
        if ($request->ajax()) {
            $orders = Order::with('merchant')->select('orders.*');

            return DataTables::of($orders)
                ->addColumn('merchant', function ($order) {
                    return $order->merchant->first_name . ' ' . $order->merchant->last_name . ' (' . $order->merchant->business_name . ')';
                })
                ->addColumn('customer', function ($order) {
                    return $order->name ?? $order->mobile_number;
                })
                ->addColumn('sub_total', function ($order) {
                    return $order->sub_total . ' ($' . convertShillingToUSD($order->sub_total) . ')';
                })
                ->addColumn('vat', function ($order) {
                    return $order->vat . ' ($' . convertShillingToUSD($order->vat) . ')';
                })
                ->addColumn('exelo_amount', function ($order) {
                    return $order->exelo_amount . ' ($' . convertShillingToUSD($order->exelo_amount) . ')';
                })
                ->addColumn('total_price', function ($order) {
                    return $order->total_price . ' ($' . convertShillingToUSD($order->total_price) . ')';
                })
                ->addColumn('order_status', function ($order) {
                    return $order->order_status;
                })
                ->addColumn('action', function ($order) {
                    return '<a href="' . route('admin.orders.view', $order->id) . '"  class="badge bg-primary m-1"><i
                                            class="fas fa-fw fa-eye"></i></a>';
                })
                ->rawColumns(['merchant', 'sub_total', 'vat', 'exelo_amount', 'total_price', 'order_status', 'action'])
                ->make(true);
        }

        $title = 'All Orders';
        return view('admin.order.index', compact('title'));
    }

    public function view($id)
    {
        $order = Order::with('items.product')->findOrFail($id);

        // Initialize subtotal
        $subtotal = 0;
        foreach ($order->items as $item) {
            $subtotal += $item->price * $item->quantity;
        }

        // Calculate VAT and Exelo Amount
        $vatCharge = env('VAT_CHARGE');
        $exeloCharge = env('EXELO_CHARGE');
        $vat = $subtotal * $vatCharge;
        $exeloAmount = $subtotal * $exeloCharge;
        $totalPriceWithVAT = $subtotal + $vat;

        return view('admin.order.view', compact('order', 'subtotal', 'vat', 'exeloAmount', 'totalPriceWithVAT'));
    }


}
