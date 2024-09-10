<?php

use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\MerchantConfirmationController;
use App\Http\Controllers\API\MerchantController;
use App\Http\Controllers\API\MerchantSubscriptionController;
use App\Http\Controllers\API\MerchantTransactionController;
use App\Http\Controllers\API\MerchantVerificationController;
use App\Http\Controllers\API\PassportAuthController;
use App\Http\Controllers\API\PaymentController;
use App\Http\Controllers\API\ProductController;
use App\Http\Controllers\API\ProductInventoryController;
use App\Http\Controllers\API\SaleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('login', [PassportAuthController::class, 'login']);
Route::post('logout', [PassportAuthController::class, 'logout'])->middleware('auth:api');
Route::post('login/verifyUser', [PassportAuthController::class, 'verifyUser']);

Route::post('merchants', [MerchantController::class, 'store']);
Route::post('merchants/signup', [MerchantController::class, 'signup']);

Route::post('merchants/request-otp', [MerchantController::class, 'requestOtp']);
Route::post('merchants/verify-otp', [MerchantController::class, 'verifyOtp']);
Route::post('merchants/store-pin', [MerchantVerificationController::class, 'storePin']);

Route::post('merchant/transaction/process', [PaymentController::class, 'processTransaction']);
Route::post('merchant/invoice/issue', [PaymentController::class, 'issueInvoice']);
Route::post('merchant/invoice/status', [PaymentController::class, 'checkInvoiceStatus']);

Route::middleware('auth:api')->group(function () {


    // Payment Routes
    Route::prefix('merchant/invoice')->group(function () {
        Route::post('/route', [PaymentController::class, 'routePaymentAPI']);           // Route payment API
        Route::post('/payment', [PaymentController::class, 'makeMerchantPayment']);     // Make merchant payment

        // Zaad Pre Authorize
        Route::post('/zaad/issue', [PaymentController::class, 'callWaafiAPIForPreAuthorize']);
        Route::post('/zaad/commit', [PaymentController::class, 'connectToWaafiCommitAPI']);
    });

    // Merchant Routes
    Route::prefix('merchants')->group(function () {
        Route::get('/', [MerchantController::class, 'index']);                          // List all merchants
        Route::get('/{id}', [MerchantController::class, 'show']);                       // Show a specific merchant

        // Merchant Confirmation
        Route::post('/confirm', [MerchantConfirmationController::class, 'sendConfirmation']); // Send merchant confirmation

        // Merchant Verification
        Route::get('/verify/{merchant_id}', [MerchantVerificationController::class, 'verifyMerchant']);  // Verify a merchant
        Route::post('/approve', [MerchantVerificationController::class, 'approveMerchant']);             // Approve a merchant

        // Merchant Subscriptions
        Route::prefix('subscriptions')->group(function () {
            Route::get('/', [MerchantSubscriptionController::class, 'index'])->name('subscriptions.index');  // List all subscriptions
            Route::post('/', [MerchantSubscriptionController::class, 'store'])->name('subscriptions.store');  // Create a subscription
            Route::get('/{id}', [MerchantSubscriptionController::class, 'show'])->name('subscriptions.show');  // Show a specific subscription
            Route::put('/{id}', [MerchantSubscriptionController::class, 'update'])->name('subscriptions.update');  // Update a subscription
            Route::delete('/{id}', [MerchantSubscriptionController::class, 'destroy'])->name('subscriptions.destroy');  // Delete a subscription
            Route::post('/{id}/cancel', [MerchantSubscriptionController::class, 'cancel'])->name('subscriptions.cancel');  // Cancel a subscription
        });
    });

    // Sales Routes
    Route::prefix('merchants/sales')->group(function () {
        Route::post('/process', [SaleController::class, 'processSale'])->name('sales.process')->middleware('merchant');  // Process sale with middleware
        Route::post('/confirm-payment', [SaleController::class, 'confirmPayment'])->name('sales.confirm-payment')->middleware('merchant');  // Confirm payment
    });

    // Merchant Transaction Routes
    Route::post('/merchant/verify-transaction', [MerchantTransactionController::class, 'verifyTransaction']);  // Verify a transaction

    // Product Routes
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);           // Get all products
        Route::get('/barcode/{id}', [ProductController::class, 'searchBarcode']);
        Route::post('/', [ProductController::class, 'store']);
        Route::get('/merchant', [ProductController::class, 'getByMerchant']); // Get categories by merchant
// Create a new product
        Route::get('/{id}', [ProductController::class, 'show']);        // Get a single product by ID
        Route::post('/{product}', [ProductController::class, 'update']); // Update a product
        Route::delete('/{product}', [ProductController::class, 'destroy']); // Delete a product
    });

    // Product Inventory Routes
    Route::prefix('product-inventories')->group(function () {
        Route::get('/', [ProductInventoryController::class, 'index']);           // Get all product inventories
        Route::post('/', [ProductInventoryController::class, 'store']);          // Create a new product inventory
        Route::get('/{id}', [ProductInventoryController::class, 'show']);        // Get a single product inventory by ID
        Route::put('/{inventory}', [ProductInventoryController::class, 'update']); // Update a product inventory
        Route::delete('/{inventory}', [ProductInventoryController::class, 'destroy']); // Delete a product inventory
    });


    // Category Routes
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);           // List all categories
        Route::get('/merchant', [CategoryController::class, 'getByMerchant']); // Get categories by merchant
        Route::post('/store', [CategoryController::class, 'store']);          // Store (create) a category
        Route::get('/{id}', [CategoryController::class, 'show']);         // Show a specific category
        Route::put('/{id}', [CategoryController::class, 'update']);     // Update a category
        Route::delete('/{id}', [CategoryController::class, 'destroy']); // Delete a category
        Route::post('/search', [CategoryController::class, 'search']); // Delete a category
    });

});
