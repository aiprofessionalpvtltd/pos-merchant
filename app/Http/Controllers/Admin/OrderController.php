<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Yajra\DataTables\DataTables;

class OrderController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-order'])->only(['index', 'show', 'view']);
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
            $orders = Order::with(['merchant', 'merchantAccount'])->select('orders.*');

            return DataTables::of($orders)
                ->addColumn('shop', function ($order) {
                    return $order->merchant->business_name ?: trim($order->merchant->first_name.' '.$order->merchant->last_name);
                })
                ->addColumn('merchant_account', function ($order) {
                    return $order->merchantAccount?->fullName();
                })
                ->addColumn('customer', function ($order) {
                    return $order->name ?? $order->mobile_number;
                })
                ->addColumn('sub_total', fn (Order $order) => $this->money((float) $order->sub_total, $order))
                ->addColumn('vat', fn (Order $order) => $this->money((float) $order->vat, $order))
                ->addColumn('exelo_amount', fn (Order $order) => $this->money((float) $order->exelo_amount, $order))
                ->addColumn('total_price', fn (Order $order) => $this->money((float) $order->total_price, $order, $order->total_price_sls))
                ->addColumn('order_status', function ($order) {
                    return $order->order_status;
                })
                ->addColumn('action', function ($order) {
                    return '<a href="'.route('admin.orders.view', $order->id).'"  class="badge bg-primary m-1"><i
                                            class="fas fa-fw fa-eye"></i></a>';
                })
                ->rawColumns(['shop', 'sub_total', 'vat', 'exelo_amount', 'total_price', 'order_status', 'action'])
                ->make(true);
        }

        $title = 'All Orders';

        return view('admin.order.index', compact('title'));
    }

    /**
     * `$usd` is a USD amount as stored on the order (sub_total, vat, exelo_amount,
     * total_price are all USD, not shillings — see docs/orders.md). Shown next to
     * its SLSH equivalent at the order's own frozen rate, or `$slsh` when given
     * (the order's actual `total_price_sls`, which is authoritative once paid).
     */
    private function money(float $usd, Order $order, float|string|null $slsh = null): string
    {
        $rate = (int) ($order->exchange_rate ?: config('exelo.conversion_rate'));
        $slshAmount = $slsh !== null ? (float) $slsh : round($usd * $rate, 2);

        return '$'.number_format($usd, 2).' ('.number_format($slshAmount).' SLSH)';
    }

    public function view($id)
    {
        $order = Order::with(['items.product', 'merchant', 'merchantAccount'])->findOrFail($id);
        $rate = (int) ($order->exchange_rate ?: config('exelo.conversion_rate'));

        // The order's own stored totals (all USD; see docs/orders.md), not
        // recomputed from today's item prices or fee rates, which drift from what
        // was actually charged.
        $subtotal = (float) $order->sub_total;
        $vat = (float) $order->vat;
        $exeloAmount = (float) $order->exelo_amount;
        $totalPriceWithVAT = (float) $order->total_price;
        $totalSls = (float) ($order->total_price_sls ?? round($totalPriceWithVAT * $rate, 2));

        return view('admin.order.view', compact('order', 'rate', 'subtotal', 'vat', 'exeloAmount', 'totalPriceWithVAT', 'totalSls'));
    }
}
