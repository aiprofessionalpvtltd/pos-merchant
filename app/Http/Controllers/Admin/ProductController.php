<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Services\InventoryDirectoryService;
use Illuminate\Http\Request;
use Yajra\DataTables\DataTables;

class ProductController extends Controller
{
    public function __construct(private readonly InventoryDirectoryService $inventory)
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-product'])->only(['index', 'show', 'categories']);
    }

    /**
     * Every product of every shop, with stock per location.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::of($this->inventory->listQuery())
                ->filter(fn ($query) => $this->inventory->filterStatus($query, $request->input('status')), true)
                ->addColumn('merchant', fn (Product $product) => $this->inventory->listRow($product)['merchant'])
                ->filterColumn('merchant', fn ($query, $keyword) => $query->whereHas('merchant', fn ($merchant) => $merchant
                    ->where('business_name', 'like', "%{$keyword}%")
                    ->orWhere('first_name', 'like', "%{$keyword}%")
                    ->orWhere('last_name', 'like', "%{$keyword}%")))
                ->orderColumn('merchant', 'products.merchant_id $1')
                ->addColumn('category', fn (Product $product) => $this->inventory->listRow($product)['category'])
                ->filterColumn('category', fn ($query, $keyword) => $query->whereHas('category', fn ($category) => $category->where('name', 'like', "%{$keyword}%")))
                ->orderColumn('category', 'products.category_id $1')
                ->addColumn('price_display', fn (Product $product) => $this->inventory->listRow($product)['price'])
                ->orderColumn('price_display', 'products.price $1')
                ->addColumn('shop', fn (Product $product) => $this->inventory->listRow($product)['shop'])
                ->orderColumn('shop', 'qty_shop $1')
                ->addColumn('stock', fn (Product $product) => $this->inventory->listRow($product)['stock'])
                ->orderColumn('stock', 'qty_stock $1')
                ->addColumn('transit', fn (Product $product) => $this->inventory->listRow($product)['transit'])
                ->orderColumn('transit', 'qty_transit $1')
                ->addColumn('status', fn (Product $product) => $this->inventory->listRow($product)['status']['label'])
                ->editColumn('created_at', fn (Product $product) => $product->created_at?->format('d M Y H:i'))
                ->addColumn('action', fn (Product $product) => '<a class="btn btn-sm btn-outline-primary" href="'.route('admin.products.view', $product->id).'">View</a>')
                // Only the button is HTML; names, barcodes and merchants are user input and stay escaped.
                ->rawColumns(['action'])
                ->make(true);
        }

        return view('admin.product.index', ['title' => 'Products', 'statuses' => InventoryDirectoryService::STATUSES]);
    }

    public function show(int $id)
    {
        $product = Product::withTrashed()->with(['merchant', 'category'])->findOrFail($id);

        return view('admin.product.view', ['title' => 'Product', 'product' => $product, 'detail' => $this->inventory->detail($product)]);
    }

    /**
     * Every shop's categories with how many products each holds.
     */
    public function categories(Request $request)
    {
        if ($request->ajax()) {
            $categories = Category::withTrashed()->select('categories.*')->with('merchant')->withCount('products');

            return DataTables::of($categories)
                ->addColumn('merchant', fn (Category $category) => $category->merchant?->business_name ?: trim($category->merchant?->first_name.' '.$category->merchant?->last_name))
                ->filterColumn('merchant', fn ($query, $keyword) => $query->whereHas('merchant', fn ($merchant) => $merchant
                    ->where('business_name', 'like', "%{$keyword}%")
                    ->orWhere('first_name', 'like', "%{$keyword}%")
                    ->orWhere('last_name', 'like', "%{$keyword}%")))
                ->orderColumn('merchant', 'categories.merchant_id $1')
                ->orderColumn('products_count', 'products_count $1')
                ->addColumn('status', fn (Category $category) => $category->trashed() ? 'Deleted' : 'Active')
                ->editColumn('created_at', fn (Category $category) => $category->created_at?->format('d M Y H:i'))
                ->make(true);
        }

        return view('admin.category.index', ['title' => 'Categories']);
    }
}
