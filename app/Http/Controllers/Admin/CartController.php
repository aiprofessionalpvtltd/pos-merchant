<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Services\CartDirectoryService;
use Illuminate\Http\Request;
use Yajra\DataTables\DataTables;

class CartController extends Controller
{
    public function __construct(private readonly CartDirectoryService $carts)
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-cart'])->only(['index', 'show']);
    }

    /**
     * The open tickets on every till, or every ticket when empty ones are included.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::of($this->carts->listQuery($request->boolean('include_empty')))
                ->addColumn('merchant', fn (Cart $cart) => $this->carts->listRow($cart)['merchant'])
                ->filterColumn('merchant', fn ($query, $keyword) => $query->whereHas('merchant', fn ($merchant) => $merchant
                    ->where('business_name', 'like', "%{$keyword}%")
                    ->orWhere('first_name', 'like', "%{$keyword}%")
                    ->orWhere('last_name', 'like', "%{$keyword}%")))
                ->orderColumn('merchant', 'carts.merchant_id $1')
                ->addColumn('cashier', fn (Cart $cart) => $this->carts->listRow($cart)['cashier'])
                ->filterColumn('cashier', fn ($query, $keyword) => $query->whereHas('user', fn ($user) => $user->where('name', 'like', "%{$keyword}%")))
                ->orderColumn('cashier', 'carts.user_id $1')
                ->addColumn('type', fn (Cart $cart) => $this->carts->listRow($cart)['type'])
                ->orderColumn('type', 'carts.cart_type $1')
                ->addColumn('lines', fn (Cart $cart) => $this->carts->listRow($cart)['lines'])
                ->orderColumn('lines', 'items_count $1')
                ->addColumn('units', fn (Cart $cart) => $this->carts->listRow($cart)['units'])
                ->orderColumn('units', 'items_sum_quantity $1')
                ->addColumn('total', fn (Cart $cart) => $this->carts->listRow($cart)['total'])
                ->editColumn('device_id', fn (Cart $cart) => $cart->device_id ?? 'Legacy app')
                ->editColumn('updated_at', fn (Cart $cart) => $cart->updated_at?->format('d M Y H:i'))
                ->addColumn('action', fn (Cart $cart) => '<a class="btn btn-sm btn-outline-primary" href="'.route('admin.carts.view', $cart->id).'">View</a>')
                // Only the button is HTML; names and device ids are user input and stay escaped.
                ->rawColumns(['action'])
                ->make(true);
        }

        return view('admin.cart.index', ['title' => 'Open Tickets']);
    }

    public function show(int $id)
    {
        $cart = Cart::with(['merchant', 'user.employee'])->findOrFail($id);

        return view('admin.cart.view', ['title' => 'Ticket', 'cart' => $cart, 'detail' => $this->carts->detail($cart)]);
    }
}
