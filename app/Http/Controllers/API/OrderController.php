<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\CartItemResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\TransactionResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductInventory;
use App\Models\Transaction;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class OrderController extends BaseController
{
    public function addToCart(Request $request)
    {
        // Validate the incoming request data
        $validator = $this->validateRequest($request, [
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'cart_type' => 'required|in:shop,stock',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        // Begin a database transaction
        DB::beginTransaction();

        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Find the product by ID
            $product = Product::where('merchant_id', $merchantID)->find($request->product_id);

            if (!$product) {
                return $this->sendError('Product not found.', ['Product not found with ID ' . $request->product_id]);
            }

            // Find or create a cart for the user
            $cart = Cart::firstOrCreate(
                ['merchant_id' => $merchantID, 'user_id' => $authUser->id, 'cart_type' => $request->cart_type]
            );


            // Add the product to the cart
            $cartItem = CartItem::updateOrCreate(
                ['cart_id' => $cart->id, 'price' => $product->total_price, 'product_id' => $request->product_id],
                ['quantity' => DB::raw("quantity + {$request->quantity}")]
            );

            $cartItems = CartItem::with('cart.merchant', 'cart.user', 'product')->where('cart_id', $cart->id)->get();

            // Commit the transaction
            DB::commit();

            return $this->sendResponse(CartItemResource::collection($cartItems), 'Item added to cart successfully.');
        } catch (\Exception $e) {
            // Rollback the transaction on error
            DB::rollBack();
            return $this->sendError('Failed to add item to cart.', $e->getMessage());
        }
    }

    public function getCartItems(Request $request)
    {
        // Validate cart type
        $validator = $this->validateRequest($request, [
            'cart_type' => 'required|in:shop,stock',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $merchantID)
                ->where('user_id', $authUser->id)
                ->where('cart_type', $request->cart_type)
                ->with('items.product') // Load products in the cart items
                ->first();

            if (!$cart) {
                return $this->sendError('Cart not found.');
            }

            // Prepare the cart items data
            $cartItems = $cart->items->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->product_name,
                    'quantity' => $item->quantity,
                    'price' => $item->product->total_price,
                    'total' => $item->quantity * $item->product->total_price,
                ];
            });

            return $this->sendResponse([
                'cart_type' => $request->cart_type,
                'items' => $cartItems,
                'total' => $cartItems->sum('total'),
            ], 'Cart items retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving cart items.', $e->getMessage());
        }
    }

    public function updateCartItem(Request $request)
    {
        // Validate cart type and product_id

        $validator = $this->validateRequest($request, [
            'cart_type' => 'required|in:shop,stock',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1', // Ensure quantity is provided and is a positive integer
            'price' => 'required', // Ensure quantity is provided and is a positive integer
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }


        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $merchantID)
                ->where('user_id', $authUser->id)
                ->where('cart_type', $request->cart_type)
                ->with('items.product') // Load products in the cart items
                ->first();

            if (!$cart) {
                return $this->sendError('Cart not found.');
            }

            // Find the product by ID
            $product = Product::where('merchant_id', $merchantID)->find($request->product_id);

            if (!$product) {
                return $this->sendError('Product not found.', ['Product not found with ID ' . $request->product_id]);
            }

            // Find the specific cart item by product_id
            $cartItem = $cart->items->where('product_id', $request->product_id)->first();

            if (!$cartItem) {
                return $this->sendError('Product not found in the cart.');
            }

            // Store old values for comparison
            $oldQuantity = $cartItem->quantity;
            $oldPrice = $cartItem->price;


            // Update the quantity for the cart item
            $cartItem->quantity = $request->quantity;

            // Calculate the new price
            $calculatedPrice = $request->price;

            // Update the price only if the new price is different from the current price
            if ($oldPrice !== $calculatedPrice) {
                $cartItem->price = $calculatedPrice;
            }

            $cartItem->save();

            // Prepare the updated cart items data
            $cartItems = $cart->items->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->product_name,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'price_in_usd' => convertShillingToUSD($item->price),
                    'total' => $item->quantity * $item->price,
                    'total_in_usd' => convertShillingToUSD($item->quantity * $item->price),
                ];
            });

            return $this->sendResponse([
                'cart_type' => $request->cart_type,
                'items' => $cartItems,
                'total' => $cartItems->sum('total'),
            ], 'Cart item updated successfully.');

        } catch (\Exception $e) {
            return $this->sendError('Error updating cart item.', $e->getMessage());
        }
    }

    public function deleteCartItem(Request $request)
    {
        $validator = $this->validateRequest($request, [
            'cart_type' => 'required|in:shop,stock',
            'product_id' => 'required|exists:products,id',]);
        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }


        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $merchantID)
                ->where('user_id', $authUser->id)
                ->where('cart_type', $request->cart_type)
                ->with('items.product') // Load products in the cart items
                ->first();

            if (!$cart) {
                return $this->sendError('Cart not found.');
            }

            // Find the product by ID
            $product = Product::where('merchant_id', $merchantID)->find($request->product_id);

            if (!$product) {
                return $this->sendError('Product not found.', ['Product not found with ID ' . $request->product_id]);
            }

            // Find the specific cart item by product_id
            $cartItem = $cart->items->where('product_id', $request->product_id)->first();

            if (!$cartItem) {
                return $this->sendError('Product not found in the cart.');
            }

            // Delete the cart item
            $cartItem->delete();

            return $this->sendResponse([], 'Cart item deleted successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error deleting cart item.', $e->getMessage());
        }
    }


    public function checkout(Request $request)
    {
        // Validate cart type (shop/stock)
        $validator = $this->validateRequest($request, [
            'cart_type' => 'required|in:shop,stock',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {

            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $merchantID)
                ->where('user_id', $authUser->id)
                ->where('cart_type', $request->cart_type)
                ->with('items.product') // Load products in the cart items
                ->first();

            if (!$cart || $cart->items->isEmpty()) {
                DB::rollBack();
                return $this->sendError('Cart is empty.');
            }

            // Initialize subtotal
            $subtotal = 0;
            foreach ($cart->items as $item) {
                $product = $item->product;
                $subtotal += $item->price * $item->quantity;
            }


            $vatCharge = env('VAT_CHARGE');
//            $vat = $subtotal * $vatCharge;
            $vat = 0;

            // Calculate Exelo amount (on sub total)
            $exeloCharge = env('EXELO_CHARGE');
            $exeloAmount = ($subtotal) * $exeloCharge;

            // Calculate total price including VAT
            $totalPriceWithVAT = $subtotal + $vat;

            // Prepare the response data
            $data = [
                'subtotal' => convertShillingToUSD($subtotal),
//                'vat' => convertShillingToUSD($vat),
                'exelo_amount' => convertShillingToUSD($exeloAmount),
                'total' => round($totalPriceWithVAT, 2),
                'total_in_usd' => convertShillingToUSD($totalPriceWithVAT),
                'cart_items' => $cart->items->map(function ($item) {
                    return [
                        'product_id' => $item->product->id,
                        'product_name' => $item->product->product_name,
                        'quantity' => $item->quantity,
                        'price' => ($item->price),
                        'price_in_usd' => convertShillingToUSD($item->price),
                        'total_price' => ($item->quantity * $item->price),
                        'total_price_in_usd' => convertShillingToUSD($item->quantity * $item->price),
                    ];
                })
            ];

            DB::commit();

            return $this->sendResponse($data, 'Checkout details retrieved successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error retrieving checkout details.', $e->getMessage());
        }
    }

    public function placeOrder(Request $request)
    {

        $validator = $this->validateRequest($request, [
            'cart_type' => 'required|in:shop,stock',
            'invoice_id' => 'required|exists:invoices,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }


        DB::beginTransaction();

        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $merchantID)
                ->where('user_id', $authUser->id)
                ->where('cart_type', $request->cart_type)
                ->with('items.product') // Load products in the cart items
                ->first();

            if (!$cart || $cart->items->isEmpty()) {
                DB::rollBack();
                return $this->sendError('Cart is empty.');
            }

            $invoice = Invoice::where('type', 'POS')->find($request->invoice_id);
            if (!$invoice) {
                DB::rollBack();
                return $this->sendError('Invoice not found.');
            }

            $transaction = Transaction::where('merchant_id', $merchantID)->whereNull('order_id')->latest()->first();
            if (!$transaction) {
                DB::rollBack();
                return $this->sendError('transaction not found.');
            }

            // Calculate the total price before VAT
            $totalPrice = 0;
            foreach ($cart->items as $item) {
                $product = $item->product;

                // Fetch the product's inventory based on the cart type (shop/stock)
                $inventory = ProductInventory::where('product_id', $product->id)
                    ->where('type', $request->cart_type)
                    ->first();

                if (!$inventory || $inventory->quantity < $item->quantity) {
                    DB::rollBack();
                    return $this->sendError("Insufficient stock for product: {$product->product_name}.");
                }

                // Update the total price
                $totalPrice += $item->price * $item->quantity;
            }

            // Calculate VAT (10%)
            $vatCharge = env('VAT_CHARGE');
//            $vat = $totalPrice * $vatCharge;
            $vat = 0;
            // Calculate Exelo amount (on sub total)
            $exeloCharge = env('EXELO_CHARGE');
            $exeloAmount = ($totalPrice) * $exeloCharge;

            // Calculate total price including VAT and Exelo amount
            $totalPriceWithVAT = $totalPrice + $vat;

//            dd([
//                'merchant_id' => $merchantID,
//                'user_id' => $authUser->id,
//                'sub_total' => round($totalPrice),
//                'vat' => round($vat),
//                'exelo_amount' => round($exeloAmount),
//                'total_price' => round($totalPriceWithVAT),
//                'order_type' => $request->cart_type,
//                'order_status' => 'Paid',
//            ]);
            // Create an order
            $order = Order::create([
                'merchant_id' => $merchantID,
                'user_id' => $authUser->id,
                'sub_total' => round($totalPrice),
                'vat' => round($vat),
                'exelo_amount' => round($exeloAmount),
                'total_price' => round($totalPriceWithVAT),
                'order_type' => $request->cart_type,
                'order_status' => 'Paid',
            ]);

            // Add order items and decrement the inventory
            foreach ($cart->items as $item) {
                $product = $item->product;

                // Create the order item
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $item->quantity,
                    'price' => $product->total_price,
                ]);

                // Decrement the inventory quantity for the specific cart type
                $inventory = ProductInventory::where('product_id', $product->id)
                    ->where('type', $request->cart_type)
                    ->first();

                if ($inventory) {
                    $inventory->decrement('quantity', $item->quantity);
                }
            }

            // Clear the cart after placing the order
            $cart->items()->delete();
            $cart->delete();

            // update invoice
            $invoice->order_id = $order->id;
            $invoice->merchant_id = $order->merchant_id;
            $invoice->user_id = $order->user_id;
            $invoice->save();


            $transaction->order_id = $order->id;
            $transaction->save();
            DB::commit();

            return $this->sendResponse($order, 'Order placed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error placing order.', $e->getMessage());
        }
    }

    public function paidOrder(Request $request)
    {
        // Validate the incoming request for order_id and invoice_id
        $validator = $this->validateRequest($request, [
            'order_id' => 'required|exists:orders,id',
            'invoice_id' => 'required|exists:invoices,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;


            // Fetch the order by order_id
            $order = Order::where('merchant_id', $merchantID)->where('user_id', $authUser->id)
                ->find($request->order_id);

            if (!$order) {
                return $this->sendError('Order not found.');
            }

            $order->order_status = "Paid";
            $order->save();


            $invoice = Invoice::where('type', 'POS')->find($request->invoice_id);
            if (!$invoice) {
                DB::rollBack();
                return $this->sendError('Invoice not found.');
            }
            // Update the invoice with the order_id and merchant_id from the order
            $invoice->order_id = $order->id;
            $invoice->merchant_id = $order->merchant_id;
            $invoice->user_id = $order->user_id;
            $invoice->save();


            $transaction = Transaction::where('merchant_id', $merchantID)->whereNull('order_id')->latest()->first();
            if (!$transaction) {
                DB::rollBack();
                return $this->sendError('transaction not found.');
            }


            $transaction->order_id = $order->id;
            $transaction->save();

            DB::commit();

            return $this->sendResponse(new OrderResource($order), 'Invoice updated successfully with the order.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error updating the invoice.', $e->getMessage());
        }
    }

    public function placePendingOrder(Request $request)
    {

        // Validate the input fields
        $validator = $this->validateRequest($request, [
            'cart_type' => 'required|in:shop,stock',
            'name' => 'nullable|string|max:255',
            'mobile_number' => 'nullable|string|max:20',
            'signature' => 'required|string',  // Expecting base64 string for signature

        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }
        DB::beginTransaction();

        try {

            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $merchantID)
                ->where('user_id', $authUser->id)
                ->where('cart_type', $request->cart_type)
                ->with('items.product') // Load products in the cart items
                ->first();

            // Check if cart exists and is not empty
            if (!$cart || $cart->items->isEmpty()) {
                DB::rollBack();
                return $this->sendError('Cart is empty.');
            }

            // Calculate the total price before VAT
            $totalPrice = 0;
            foreach ($cart->items as $item) {
                $product = $item->product;

                // Fetch the product's inventory based on the cart type (shop/stock)
                $inventory = ProductInventory::where('product_id', $product->id)
                    ->where('type', $request->cart_type)
                    ->first();

                if (!$inventory || $inventory->quantity < $item->quantity) {
                    DB::rollBack();
                    return $this->sendError("Insufficient stock for product: {$product->product_name}.");
                }

                // Update the total price
                $totalPrice += $product->total_price * $item->quantity;
            }


            // Calculate VAT (10%)
            $vatCharge = env('VAT_CHARGE');
            $vat = $totalPrice * $vatCharge;

            // Calculate Exelo amount (on sub total)
            $exeloCharge = env('EXELO_CHARGE');
            $exeloAmount = ($totalPrice) * $exeloCharge;

            // Calculate total price including VAT and Exelo amount
            $totalPriceWithVAT = $totalPrice + $vat;

            // Handle signature as base64 image upload
            $signaturePath = $this->saveBase64Image($request->signature, 'signatures');


            // Create the order
            $order = Order::create([
                'merchant_id' => $merchantID,
                'user_id' => $authUser->id,
                'name' => $request->input('name') ?? null,
                'mobile_number' => $request->input('mobile_number') ?? null,
                'signature' => $signaturePath,
                'sub_total' => round($totalPrice),
                'vat' => round($vat),
                'exelo_amount' => round($exeloAmount),
                'total_price' => round($totalPriceWithVAT),
                'order_type' => $request->cart_type,
                'order_status' => 'Pending',
            ]);

            // Add order items and decrement inventory
            foreach ($cart->items as $item) {
                $product = $item->product;

                // Create the order item
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $item->quantity,
                    'price' => $product->total_price,
                ]);

                // Decrement inventory for the specific cart type
                $inventory = ProductInventory::where('product_id', $product->id)
                    ->where('type', $request->cart_type)
                    ->first();

                if ($inventory) {
                    $inventory->decrement('quantity', $item->quantity);
                }
            }

            // Clear the cart after placing the order
            $cart->items()->delete();
            $cart->delete();

            DB::commit();

            return $this->sendResponse(new OrderResource($order), 'Pending Order placed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error placing order.', $e->getMessage());
        }
    }

    public function updateOrderStatusToComplete(Request $request)
    {
        // Validate the request data

        $validator = $this->validateRequest($request, [
            'cart_type' => 'required|in:shop,stock',
            'order_id' => 'required|exists:orders,id',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {

            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Retrieve the order by the provided order_id
            $order = Order::where('merchant_id', $merchantID)->where('user_id', $authUser->id)->where('order_status', 'Pending')->find($request->order_id);


            if (!$order) {
                return $this->sendError('Order not found.');
            }

            $order->order_status = 'Complete'; // Update invoice status to 'Complete'
            $order->save();

            DB::commit();

            return $this->sendResponse(new OrderResource($order), 'Order status updated to Complete.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error updating invoice status.', $e->getMessage());
        }
    }

    public function updateOrderStatusToPending(Request $request)
    {
        // Validate the request data

        $validator = $this->validateRequest($request, [
            'cart_type' => 'required|in:shop,stock',
            'order_id' => 'required|exists:orders,id',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {


            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Retrieve the order by the provided order_id
            $order = Order::where('merchant_id', $merchantID)->where('user_id', $authUser->id)->where('order_status', 'Complete')->find($request->order_id);


            if (!$order) {
                return $this->sendError('Order not found.');
            }

            $order->order_status = 'Pending'; // Update invoice status to 'Complete'
            $order->save();

            DB::commit();

            return $this->sendResponse(new OrderResource($order), 'Order status updated to Pending.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error updating invoice status.', $e->getMessage());
        }
    }


    public function getOrdersByType(Request $request)
    {
        $validator = $this->validateRequest($request, [
            'order_type' => 'required|in:shop,stock',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {
            // Get the authenticated merchant ID
            $authUser = auth()->user();

            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchantID = $authUser->merchant->id;

            // Fetch orders by the given type
            $orders = Order::where('order_type', $validator['order_type'])
                ->where('merchant_id', $merchantID)
                ->with('items.product') // Load related order items and products
                ->get();

            if ($orders->isEmpty()) {
                return $this->sendError('No orders found for the specified type.');
            }

            // Prepare the response data
            $data = $orders->map(function ($order) {
                return [
                    'order_id' => $order->id,
                    'sub_total' => convertShillingToUSD($order->sub_total),
                    'vat' => convertShillingToUSD($order->vat),
                    'exelo_amount' => convertShillingToUSD($order->exelo_amount),
                    'total_price' => convertShillingToUSD($order->total_price),
                    'order_status' => $order->order_status,
                    'order_items' => $order->items->map(function ($item) {
                        return [
                            'product_id' => $item->product_id,
                            'product_name' => $item->product->product_name,
                            'quantity' => $item->quantity,
                            'price' => convertShillingToUSD($item->price),
                            'total_price' => convertShillingToUSD($item->quantity * $item->price),
                        ];
                    }),
                ];
            });

            return $this->sendResponse($data, 'Orders retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving orders.', $e->getMessage());
        }
    }

    public function getOrdersByStatus(Request $request)
    {
        $validator = $this->validateRequest($request, [
            'order_status' => 'required|in:Pending,Paid,Complete',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        try {

            // Get the authenticated merchant ID
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchantID = $authUser->merchant->id;

            if ($authUser->user_type == 'employee') {
                // Fetch orders by the given type
                $orders = Order::where('order_status', $request->order_status)
                    ->where('merchant_id', $merchantID)
                    ->where('user_id', $authUser->id)
                    ->orderBy('id', 'DESC')
                    ->with('items.product') // Load related order items and products
                    ->get();
            } else {
                // Fetch orders by the given type
                $orders = Order::where('order_status', $request->order_status)
                    ->where('merchant_id', $merchantID)
                    ->orderBy('id', 'DESC')
                    ->with('items.product') // Load related order items and products
                    ->get();
            }


            if ($orders->isEmpty()) {
                return $this->sendError('No orders found for the specified type.');
            }

            // Prepare the response data
            $data = $orders->map(function ($order) {
                return [
                    'order_id' => $order->id,
                    'name' => $order->name,
                    'initial_name' => $this->getInitials($order->name),
                    'mobile_number' => $order->mobile_number,
                    'signature' => Storage::url($order->signature),
                    'sub_total' => convertShillingToUSD($order->sub_total),
                    'vat' => convertShillingToUSD($order->vat),
                    'exelo_amount' => convertShillingToUSD($order->exelo_amount),
                    'total_price' => round($order->total_price, 2),
                    'total_price_in_usd' => convertShillingToUSD($order->total_price),
                    'order_status' => $order->order_status,
                    'created_at' => showDatePicker($order->created_at),
                    'order_items' => $order->items->map(function ($item) {
                        return [
                            'product_id' => $item->product_id,
                            'product_name' => $item->product->product_name,
                            'quantity' => $item->quantity,
                            'price' => convertShillingToUSD($item->price),
                            'total_price' => convertShillingToUSD($item->quantity * $item->price),
                        ];
                    }),
                ];
            });

            return $this->sendResponse($data, 'Orders retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving orders.', $e->getMessage());
        }
    }

    public function getOrderDetails(Request $request)
    {

        try {
            // Get authenticated user
            $authUser = auth()->user();

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure the authenticated user has a merchant relation
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant's ID
            $merchantID = $authUser->merchant->id;

            // Retrieve the order by order_id
            $order = Order::with('items.product')->where('merchant_id', $merchantID)
                ->where('user_id', $authUser->id)->find($request->order_id);

            if (!$order || $order->items->isEmpty()) {
                return $this->sendError('Order not found or has no items.');
            }

            // Initialize subtotal
            $subtotal = 0;
            foreach ($order->items as $item) {
                $product = $item->product;
                $subtotal += $item->price * $item->quantity;
            }

            // Calculate VAT (10%)
            $vatCharge = env('VAT_CHARGE');
            $vat = $subtotal * $vatCharge;

            // Calculate Exelo amount (on sub total)
            $exeloCharge = env('EXELO_CHARGE');
            $exeloAmount = ($subtotal) * $exeloCharge;

            // Calculate total price including VAT and Exelo amount
            $totalPriceWithVAT = $subtotal + $vat;

            // Prepare the response data
            $data = [
                'order_id' => $order->id,
                'name' => $order->name,
                'initial_name' => $this->getInitials($order->name),
                'mobile_number' => $order->mobile_number,
                'signature' => Storage::url($order->signature),
                'merchant_id' => $order->merchant_id,
                'user_id' => $order->user_id,
                'sub_total' => convertShillingToUSD($subtotal),
                'vat' => convertShillingToUSD($vat),
                'exelo_amount' => convertShillingToUSD($exeloAmount),
                'total' => round($totalPriceWithVAT, 2),
                'total_in_usd' => convertShillingToUSD($totalPriceWithVAT),
                'order_status' => $order->order_status,
                'created_at' => showDatePicker($order->created_at),
                'order_items' => $order->items->map(function ($item) {
                    return [
                        'product_id' => $item->product->id,
                        'product_name' => $item->product->product_name,
                        'quantity' => $item->quantity,
                        'price' => convertShillingToUSD($item->price),
                        'total_price' => convertShillingToUSD($item->quantity * $item->price),
                    ];
                }),
            ];

            return $this->sendResponse($data, 'Order details retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving order details.', $e->getMessage());
        }
    }

    public function getOrderDetailsForInvoice($orderID)
    {
        try {
            // Get authenticated user
            $authUser = auth()->user();

            // Ensure the authenticated user exists and has a merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Get merchant ID from authenticated user's merchant relation
            $merchantID = $authUser->merchant->id;

            // Retrieve the order with items and product relationship
            $order = Order::with('items.product', 'user.merchant', 'merchant', 'invoice')
                ->where('merchant_id', $merchantID)
                ->find($orderID);

            if (!$order || $order->items->isEmpty()) {
                return $this->sendError('Order not found or has no items.');
            }

            // Initialize subtotal
            $subtotal = 0;
            foreach ($order->items as $item) {
                $subtotal += $item->price * $item->quantity;
            }

            // Calculate VAT (10%)
            $vatCharge = env('VAT_CHARGE');
            $vat = $subtotal * $vatCharge;

            // Calculate Exelo amount (on sub total)
            $exeloCharge = env('EXELO_CHARGE');
            $exeloAmount = ($subtotal) * $exeloCharge;

            // Calculate total price including VAT
            $totalPriceWithVAT = $subtotal + $vat;

            // Dahab and Zaad prefixes
            $dahabPrefixes = ['65', '66', '62'];
            $mobileNO = $order->mobile_number ?? $order->invoice->mobile_number ?? 'N/A';
            $phoneNo = str_replace('+252', '', $mobileNO);
            $mobileNumberPrefix = substr($phoneNo, 0, 2);

            // Determine if it's edahab_number or zaad_number
            $mobileNumberType = in_array($mobileNumberPrefix, $dahabPrefixes) ? 'E-Dahab' : 'Zaad';


            // Prepare the response data
            $data = [
                'order_id' => $order->id,
                'merchant' => [
                    'business_name' => $order->merchant->business_name,
                    'merchant_code' => $order->merchant->merchant_code,
                    'cashier_name' => $order->user->name,
                    'edahab_number' => $order->merchant->edahab_number ?? 'N/A',
                    'zaad_number' => $order->merchant->zaad_number ?? 'N/A',
                ],
                'invoice' => $order->invoice ? [
                    'invoice_no' => $order->invoice->id,
                    'invoice_date' => showDate($order->invoice->created_at),
                    'payment_status' => $order->order_status,
                ] : [
                    'invoice_no' => 'N/A',
                    'invoice_date' => showDate($order->created_at),
                    'payment_status' => 'Paid By Cash',
                ],
                'customer' => [
                    'name' => $order->name ?? 'N/A',
                    'mobile_number' => $mobileNO,
                    'account' => $mobileNumberType,
                    'initial_name' => $this->getInitials($order->name ?? 'Not Avaibale'),
                ],
                'order_items' => $order->items->map(function ($item) {
                    return [
                        'product_name' => $item->product->product_name,
                        'quantity' => $item->quantity,
                        'price' => convertShillingToUSD($item->price),
                        'total_price' => convertShillingToUSD($item->quantity * $item->price),
                    ];
                }),
                'sub_total' => convertShillingToUSD($subtotal),
                'vat' => convertShillingToUSD($vat),
                'vat_charge' => env('VAT_CHARGE') * 100 . '%',
                'exelo_amount' => convertShillingToUSD($exeloAmount),
                'total' => convertShillingToUSD($totalPriceWithVAT)


            ];

            return $this->sendResponse($data, 'Order details retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving order details.', $e->getMessage());
        }
    }

    public function transactionByCash(Request $request)
    {
        // Validate the input fields
        $validator = $this->validateRequest($request, [
            'cart_type' => 'sometimes',
            'amount' => 'required',
            'order_id' => 'sometimes',
            'payment_method' => 'sometimes',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {
            // Get authenticated user
            $authUser = auth()->user();

            // If user is an employee, load the associated merchant
            if ($authUser->user_type == 'employee') {
                $authUser->merchant = $authUser->employee->merchant;
            }

            // Ensure merchant is available for the authenticated user
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchantID = $authUser->merchant->id;
            $amount = $request->amount;
            $paymentMethod = $request->payment_method;
            $cartType = $request->cart_type;
            $orderID = $request->order_id;
            $phoneNumber = $authUser->merchant->phone_number;


            // Check if cart exists and is not empty
            if ($cartType != null && $orderID == null) {
                // Fetch user's cart based on cart type
                $cart = Cart::where('merchant_id', $merchantID)
                    ->where('user_id', $authUser->id)
                    ->where('cart_type', $cartType)
                    ->with('items.product') // Load products in the cart items
                    ->first();
//                dd($cart && $cart->items->isNotEmpty());
                if ($cart && $cart->items->isNotEmpty()) {
                    $totalPrice = 0;

                    // Calculate total price and validate inventory
                    foreach ($cart->items as $item) {
                        $product = $item->product;
                        $inventory = ProductInventory::where('product_id', $product->id)
                            ->where('type', $cartType)
                            ->first();

                        if (!$inventory || $inventory->quantity < $item->quantity) {
                            DB::rollBack();
                            return $this->sendError("Insufficient stock for product: {$product->product_name}.");
                        }

                        $totalPrice += $product->total_price * $item->quantity;
                    }

                    // Calculate VAT and Exelo charges
                    $vatCharge = env('VAT_CHARGE', 0.10); // Set a default value in case VAT_CHARGE is not defined
                    $vat = $totalPrice * $vatCharge;

                    $exeloCharge = env('EXELO_CHARGE', 0.02); // Set a default value for Exelo charge
                    $exeloAmount = $totalPrice * $exeloCharge;

                    $exeloFee = $totalPrice * 0.0285; // Exelo fee for merchants: 2.85%
                    $amountSentToMerchant = $totalPrice - $exeloFee;

//                dd($totalPrice,$exeloAmount , $amountSentToMerchant);
                    // Total price including VAT
                    $totalPriceWithVAT = $totalPrice + $vat;


                    // Create the order
                    $order = Order::create([
                        'merchant_id' => $merchantID,
                        'user_id' => $authUser->id,
                        'name' => $request->input('name'),
                        'mobile_number' => $request->input('mobile_number'),
                        'sub_total' => round($totalPrice),
                        'vat' => round($vat),
                        'exelo_amount' => round($exeloAmount),
                        'total_price' => round($totalPriceWithVAT),
                        'order_type' => $cartType,
                        'order_status' => 'Paid',
                    ]);

                    // Add order items and update inventory
                    foreach ($cart->items as $item) {
                        $product = $item->product;

                        // Create order item
                        OrderItem::create([
                            'order_id' => $order->id,
                            'product_id' => $product->id,
                            'quantity' => $item->quantity,
                            'price' => $product->total_price,
                        ]);

                        // Decrease product inventory
                        $inventory->decrement('quantity', $item->quantity);
                    }

                    // Clear the cart after processing the order
                    $cart->items()->delete();
                    $cart->delete();

                    // Save the transaction details
                    $transaction = Transaction::create([
                        'order_id' => $order->id,
                        'transaction_amount' => $amountSentToMerchant,
                        'transaction_status' => 'Approved',
                        'transaction_message' => $amountSentToMerchant . ' amount received by cash with the deduction of exelo fee ' . $exeloFee,
                        'phone_number' => $phoneNumber,
                        'transaction_id' => 'N/A',
                        'merchant_id' => $merchantID,
                        'payment_method' => $paymentMethod ?? 'number',
                    ]);

                } else {
                    return $this->sendError('Cart is Empty. Add product to register');

                }
            } elseif ($orderID != null) {
                // Fetch the order by order_id
                $order = Order::where('merchant_id', $merchantID)
                    ->find($orderID);

                if (!$order) {
                    return $this->sendError('Order not found.');
                }

                $order->order_status = "Paid";
                $order->save();

                $exeloFee =  $order->sub_total * 0.0285; // Exelo fee for merchants: 2.85%
                $amountSentToMerchant =  $order->sub_total - $exeloFee;

                 // Save the transaction details
                $transaction = Transaction::create([
                    'order_id' => $order->id,
                    'transaction_amount' => $amountSentToMerchant,
                    'transaction_status' => 'Approved',
                    'transaction_message' => $amountSentToMerchant . ' amount received from  by cash with the deduction of exelo fee ' . $exeloFee,
                    'phone_number' => $phoneNumber,
                    'transaction_id' => 'N/A',
                    'merchant_id' => $merchantID,
                    'payment_method' => $paymentMethod ?? 'number',
                ]);

            } else {

                // Save the transaction details
                $transaction = Transaction::create([
                    'transaction_amount' => $amount,
                    'transaction_status' => 'Approved',
                    'transaction_message' => 'Amount received by cash',
                    'phone_number' => $phoneNumber,
                    'transaction_id' => 'N/A',
                    'merchant_id' => $merchantID,
                    'payment_method' => $paymentMethod ?? 'number',
                ]);
            }


            DB::commit();

            return $this->sendResponse(new TransactionResource($transaction), 'Transaction by Cash successfully completed.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error processing Transaction by Cash.', $e->getMessage());
        }
    }


}
