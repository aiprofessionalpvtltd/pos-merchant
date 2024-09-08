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
     * @return \Illuminate\Http\Response
     */
    public function show()
    {
        $data = array();
        $title = 'Add Transaction';
        $transactions = Transaction::with('merchant')->get();
//        dd($transactions);
        return view('admin.transaction.index', compact('title', 'transactions', 'data'));
    }






}
