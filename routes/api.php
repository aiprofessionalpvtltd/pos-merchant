<?php

 use App\Http\Controllers\API\CategoryController;
use App\Http\Controllers\API\DashboardController;
use App\Http\Controllers\API\EmployeeController;
use App\Http\Controllers\API\MerchantConfirmationController;
use App\Http\Controllers\API\MerchantController;
use App\Http\Controllers\API\MerchantSubscriptionController;
use App\Http\Controllers\API\MerchantTransactionController;
use App\Http\Controllers\API\MerchantVerificationController;
use App\Http\Controllers\API\OrderController;
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
Route::post('login/verifyUserPin', [PassportAuthController::class, 'verifyUserPin']);
Route::post('login/checkInvoice', [PassportAuthController::class, 'checkInvoiceAndRegisterMerchant']);


Route::post('merchants', [MerchantController::class, 'store']);
Route::post('merchants/signup', [MerchantController::class, 'signup']);

Route::post('merchants/request-otp', [MerchantController::class, 'requestOtp']);
Route::post('merchants/verify-otp', [MerchantController::class, 'verifyOtp']);
Route::post('merchants/store-pin', [MerchantVerificationController::class, 'storePin']);

Route::post('merchant/transaction/process', [PaymentController::class, 'processTransaction']);
Route::post('merchant/invoice/check', [PaymentController::class, 'checkInvoice']);

Route::post('merchant/invoice/issue', [PaymentController::class, 'issueInvoice']);
Route::post('merchant/invoice/status', [PaymentController::class, 'checkInvoiceStatus']);

// Zaad Pre Authorize
Route::post('/zaad/issue', [PaymentController::class, 'callWaafiAPIForPreAuthorize']);
Route::post('/zaad/commit', [PaymentController::class, 'connectToWaafiCommitAPI']);




//Employee

Route::post('/employee/getMerchantDetail', [EmployeeController::class, 'getMerchantDetail']); // Get employee records
Route::post('/employee/verifyEmployee', [EmployeeController::class, 'verifyEmployee']); // Get employee records
Route::post('employee/store-pin', [EmployeeController::class, 'storePin']);
Route::get('employee/getPOSPermission', [EmployeeController::class, 'getPOSPermission']);

Route::middleware('auth:api')->group(function () {

    Route::get('login/userinfo', [PassportAuthController::class, 'userInfo']);


    Route::post('merchants/change-pin', [MerchantVerificationController::class, 'changePin']);
    Route::post('merchants/verifyPhoneNumberByCompany', [MerchantController::class, 'verifyPhoneNumberByCompany']);
    Route::post('merchants/verificationComplete', [MerchantController::class, 'verificationComplete']);
    Route::get('merchants/getPhoneNumbersStatus', [MerchantController::class, 'getPhoneNumbersStatus']);


    // Payment Routes
    Route::prefix('merchant/invoice')->group(function () {
        Route::post('/route', [PaymentController::class, 'routePaymentAPI']);           // Route payment API
        Route::post('/payment', [PaymentController::class, 'makeMerchantPayment']);     // Make merchant payment
    });



    // Merchant Routes
    Route::get('/merchants', [MerchantController::class, 'index']);                          // List all merchants
    Route::get('/merchants/{id}', [MerchantController::class, 'show']);                       // Show a specific merchant

    // Merchant Confirmation
    Route::post('/merchants/confirm', [MerchantConfirmationController::class, 'sendConfirmation']); // Send merchant confirmation

    // Merchant Verification
    Route::get('/merchants/verify/{merchant_id}', [MerchantVerificationController::class, 'verifyMerchant']);  // Verify a merchant
    Route::post('/merchants/approve', [MerchantVerificationController::class, 'approveMerchant']);             // Approve a merchant


    // Merchant Subscriptions
    Route::get('/merchants/subscriptions/all', [MerchantSubscriptionController::class, 'index']);  // List all subscriptions
    Route::get('/merchants/subscriptions/current', [MerchantSubscriptionController::class, 'current']);  // List all subscriptions
    Route::get('/merchants/subscriptions/canceled', [MerchantSubscriptionController::class, 'canceled']);  // List all subscriptions
    Route::post('/merchants/subscriptions', [MerchantSubscriptionController::class, 'store']); // Create a subscription
    Route::get('/merchants/subscriptions/{id}/cancel', [MerchantSubscriptionController::class, 'cancel']);  // Cancel a subscription


    // Sales Routes
    Route::prefix('merchants/sales')->group(function () {
        Route::post('/process', [SaleController::class, 'processSale'])->name('sales.process')->middleware('merchant');  // Process sale with middleware
        Route::post('/confirm-payment', [SaleController::class, 'confirmPayment'])->name('sales.confirm-payment')->middleware('merchant');  // Confirm payment
    });

    // Merchant Transaction Routes
    Route::post('/merchant/verify-transaction', [MerchantTransactionController::class, 'verifyTransaction']);  // Verify a transaction

// Product Routes
    Route::get('/products', [ProductController::class, 'index']);           // Get all products
    Route::get('/products/merchant', [ProductController::class, 'getByMerchant']); // Get products by merchant
    Route::get('products/category/{category_id}', [ProductController::class, 'getProductsByCategory']);
    Route::get('/products/{id}', [ProductController::class, 'show']);       // Get a single product by ID
    Route::get('/products/{id}/{type}', [ProductController::class, 'showByType']);       // Get a single product by ID
    Route::get('/products/barcode/search/{id}', [ProductController::class, 'searchBarcode']); // Search by barcode
    Route::get('/products/barcode/{id}/{type}', [ProductController::class, 'searchBarcodeWithType']); // Search by barcode

    Route::post('/products', [ProductController::class, 'store']);          // Create a new product
    Route::post('/products/{id}', [ProductController::class, 'update']);    // Update a product
    Route::delete('/products/{product}', [ProductController::class, 'destroy']); // Delete a product
    Route::get('/getSoldProducts', [ProductController::class, 'getSoldProducts']);
    Route::get('/getAllProductsWithCategories', [ProductController::class, 'getAllProductsWithCategories']); // Get product statistics
    Route::get('/getTotalProductsInShop', [ProductController::class, 'getTotalProductsInShop']); // Get product statistics
    Route::get('/getTotalProductsInStock', [ProductController::class, 'getTotalProductsInStock']); // Get product statistics
    Route::get('/getNewShopProductsListing', [ProductController::class, 'getNewShopProductsListing']); // Get product statistics
    Route::get('/getNewStockProductsListing', [ProductController::class, 'getNewStockProductsListing']); // Get product statistics

    // Dashboard Routes
    Route::get('/main-dashboard', [DashboardController::class, 'mainDashboard']);
    Route::get('/top-selling-products', [DashboardController::class, 'getTopSellingProducts']);
    Route::get('/getProductStatistics', [DashboardController::class, 'getOverallProductStatistics']); // Get product statistics
    Route::get('/getProductsByAlarmLimit', [DashboardController::class, 'getProductsByAlarmLimit']); // Get product statistics
    Route::get('/getProductsByStockLimit', [DashboardController::class, 'getProductsByStockLimit']); // Get product statistics
    Route::get('/getPendingOrders', [DashboardController::class, 'getPendingOrders']); // Get product statistics

    // Product Inventory Routes
    Route::get('/product-inventories', [ProductInventoryController::class, 'index']);           // Get all product inventories
    Route::post('/product-inventories', [ProductInventoryController::class, 'store']);          // Create a new product inventory
    Route::get('/product-inventories/{id}', [ProductInventoryController::class, 'show']);       // Get a single product inventory by ID
    Route::put('/product-inventories/{inventory}', [ProductInventoryController::class, 'update']); // Update a product inventory
    Route::delete('/product-inventories/{inventory}', [ProductInventoryController::class, 'destroy']); // Delete a product inventory

// Product Inventory Transfer Routes
    Route::post('inventory/transfer/shop-to-stock', [ProductInventoryController::class, 'transferShopToStock']);
    Route::post('inventory/transfer/stock-to-shop', [ProductInventoryController::class, 'transferStockToShop']);
    Route::post('inventory/transfer/transportation-to-shop', [ProductInventoryController::class, 'transferTransportationToShop']);
    Route::post('inventory/transfer/transportation-to-stock', [ProductInventoryController::class, 'transferTransportationToStock']);
    Route::post('inventory/transfer/shop-to-transportation', [ProductInventoryController::class, 'transferShopToTransportation']);
    Route::post('inventory/transfer/stock-to-transportation', [ProductInventoryController::class, 'transferStockToTransportation']);
    Route::get('inventory/products/{type}', [ProductInventoryController::class, 'getProductsByType']);
    Route::get('inventory/products/{id}/{type}', [ProductInventoryController::class, 'getProductsByTypeWithCategory']);

    Route::post('inventory/updateInventory', [ProductInventoryController::class, 'updateInventory']);
    Route::get('inventory/getSoldItems', [ProductInventoryController::class, 'getSoldItems']);


    // Cart and Order routes
    Route::post('/cart/add', [OrderController::class, 'addToCart']);
    Route::get('/cart/cart-items', [OrderController::class, 'getCartItems']);
    Route::post('/cart/update-cart-items', [OrderController::class, 'updateCartItem']);
    Route::delete('/cart/delete-cart-items', [OrderController::class, 'deleteCartItem']);

    Route::get('/cart/checkout', [OrderController::class, 'checkout']); // Show checkout details
    Route::get('/order/all', [OrderController::class, 'getOrdersByType']); // Show checkout details
    Route::get('/order/allByStatus', [OrderController::class, 'getOrdersByStatus']); // Show checkout details
    Route::post('/cart/placeOrder', [OrderController::class, 'placeOrder']); // Place an order
    Route::post('/cart/paidOrder', [OrderController::class, 'paidOrder']); // Paid an order
    Route::post('/cart/placePendingOrder', [OrderController::class, 'placePendingOrder']); // Place an order
    Route::post('/cart/updateOrderStatusToComplete', [OrderController::class, 'updateOrderStatusToComplete']); // Place an order
    Route::post('/cart/updateOrderStatusToPending', [OrderController::class, 'updateOrderStatusToPending']); // Place an order
    Route::post('/cart/getOrderDetails', [OrderController::class, 'getOrderDetails']); // Place an order
    Route::get('/order/getOrderDetailsForInvoice/{orderID}', [OrderController::class, 'getOrderDetailsForInvoice']); // Show checkout details


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



    // Employee Module Routes
    Route::post('/employee', [EmployeeController::class, 'store']); // Store employee
    Route::get('/employee', [EmployeeController::class, 'getAllEmployees']); // Get employee records
    Route::get('/employee/getSingleEmployee/{id}', [EmployeeController::class, 'getSingleEmployee']); // Get employee records
    Route::post('/employee/{id}', [EmployeeController::class, 'updateEmployee']); // Store employee
    Route::delete('/employee/{id}', [EmployeeController::class, 'deleteEmployee']); // Store employee

});
