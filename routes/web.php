<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\Dashboard\SuperAdminDashboardController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\MerchantController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;



Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');
Route::get('/verifyPayment', [App\Http\Controllers\HomeController::class, 'verifyPayment'])->name('verifyPayment');

Route::middleware(['auth'])->group(function () {

    Route::get('our-dashboard', [SuperAdminDashboardController::class, 'index'])->name('our-dashboard');
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('update-password', [AdminController::class, 'updatePassword'])->name('update-password');
    Route::put('change-password{id}', [AdminController::class, 'ChangePassword'])->name('change-password');

    //User Controllers
    Route::get('show-user', [UserController::class, 'show'])->name('show-user');
    Route::get('add-user', [UserController::class, 'index'])->name('add-user');
    Route::post('store-user', [UserController::class, 'store'])->name('store-user');
    Route::get('user/{id}/edit', [UserController::class, 'edit'])->name('edit-user');
    Route::put('update-user{id}', [UserController::class, 'update'])->name('update-user');
    Route::post('changeStatus-user', [UserController::class, 'changeStatus'])->name('changeStatus-user');
    Route::post('delete-user', [UserController::class, 'delete'])->name('delete-user');
    Route::post('changePassword', [UserController::class, 'changePassword'])->name('changePassword');


    Route::get('show-role', [RoleController::class, 'show'])->name('show-role');
    Route::get('add-role', [RoleController::class, 'index'])->name('add-role');
    Route::post('store-role', [RoleController::class, 'store'])->name('store-role');
    Route::get('role/{id}/edit', [RoleController::class, 'edit'])->name('edit-role');
    Route::put('update-role{id}', [RoleController::class, 'update'])->name('update-role');
    Route::post('destroy-role', [RoleController::class, 'destroy'])->name('destroy-role');


    //Merchant Controllers
    Route::get('show-merchant', [MerchantController::class, 'show'])->name('show-merchant');
    Route::get('view-merchant/{id}', [MerchantController::class, 'view'])->name('view-merchant');
     Route::post('delete-merchant', [MerchantController::class, 'delete'])->name('delete-merchant');


    //Invoice Controllers
    Route::get('show-invoice', [InvoiceController::class, 'show'])->name('show-invoice');
    Route::get('view-invoice/{id}', [InvoiceController::class, 'view'])->name('view-invoice');
    Route::post('delete-invoice', [InvoiceController::class, 'delete'])->name('delete-invoice');


    //Transaction Controllers
    Route::get('show-transaction', [TransactionController::class, 'show'])->name('show-transaction');
    Route::get('view-transaction/{id}', [TransactionController::class, 'view'])->name('view-transaction');
    Route::post('delete-transaction', [TransactionController::class, 'delete'])->name('delete-transaction');


});




Auth::routes();

Route::get('/home', [App\Http\Controllers\HomeController::class, 'index'])->name('home');

