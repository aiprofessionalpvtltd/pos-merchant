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
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        $data = array();
        $title = 'Add Invoice';
        $invoices = Invoice::all();
//        dd($invoices);
        return view('admin.invoice.index', compact('title', 'invoices', 'data'));
    }




}