<?php

namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Sale;
use Carbon\Carbon;
use DB;
use File;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use pdf;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Middlewares\PermissionMiddleware;


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
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        $data = array();
        $title = 'Add Merchant';
        $merchants = Merchant::with('user')->get();
//        dd($merchants);
        return view('admin.merchant.index', compact('title', 'merchants', 'data'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function view($id)
    {
        $title = 'View Merchant';
        $merchant = Merchant::with('user','sales.payments')->find($id);

        if (!$merchant) {
            return redirect()->route('show-merchant')->with('error', 'Merchant not found.');
        }

        return view('admin.merchant.view', compact('merchant', 'title' ));
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
