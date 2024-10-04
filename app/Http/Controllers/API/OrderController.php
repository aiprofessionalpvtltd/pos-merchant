<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\CartItemResource;
use App\Http\Resources\OrderResource;
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
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
            'cart_type' => 'required|in:shop,stock',
        ]);

        $user = auth()->user();

        // Begin a database transaction
        DB::beginTransaction();

        try {
            // Find or create a cart for the user
            $cart = Cart::firstOrCreate(
                ['merchant_id' => $user->merchant->id, 'cart_type' => $validated['cart_type']]
            );

            $product = Product::find($validated['product_id']);

            // Add the product to the cart
            $cartItem = CartItem::updateOrCreate(
                ['cart_id' => $cart->id, 'product_id' => $validated['product_id']],
                ['price' => $product->price],
                ['quantity' => DB::raw("quantity + {$validated['quantity']}")]
            );

            $cartItems = CartItem::with('cart.merchant', 'product')->where('cart_id', $cart->id)->get();
//            dd($cartItems);
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
        $validated = $request->validate([
            'cart_type' => 'required|in:shop,stock',
        ]);

        $user = auth()->user();

        try {
            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $user->merchant->id)
                ->where('cart_type', $validated['cart_type'])
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
                    'price' => $item->product->price,
                    'total' => $item->quantity * $item->product->price,
                ];
            });

            return $this->sendResponse([
                'cart_type' => $validated['cart_type'],
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
        $validated = $request->validate([
            'cart_type' => 'required|in:shop,stock',
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1', // Ensure quantity is provided and is a positive integer
            'price' => 'required', // Ensure quantity is provided and is a positive integer
        ]);

        $user = auth()->user();

        try {
            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $user->merchant->id)
                ->where('cart_type', $validated['cart_type'])
                ->with('items.product') // Load products in the cart items
                ->first();

            if (!$cart) {
                return $this->sendError('Cart not found.');
            }

            // Find the specific cart item by product_id
            $cartItem = $cart->items->where('product_id', $validated['product_id'])->first();

            if (!$cartItem) {
                return $this->sendError('Product not found in the cart.');
            }

            // Update the quantity for the cart item
            $cartItem->quantity = $validated['quantity'];
            $cartItem->price = $validated['price'];
            $cartItem->save();

            // Prepare the updated cart items data
            $cartItems = $cart->items->map(function ($item) {
                return [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product->product_name,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'total' => $item->quantity * $item->price,
                ];
            });

            return $this->sendResponse([
                'cart_type' => $validated['cart_type'],
                'items' => $cartItems,
                'total' => $cartItems->sum('total'),
            ], 'Cart item updated successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error updating cart item.', $e->getMessage());
        }
    }

    public function deleteCartItem(Request $request)
    {
        // Validate cart type and product_id
        $validated = $request->validate([
            'cart_type' => 'required|in:shop,stock',
            'product_id' => 'required|exists:products,id',
        ]);

        $user = auth()->user();

        try {
            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $user->merchant->id)
                ->where('cart_type', $validated['cart_type'])
                ->with('items') // Load cart items
                ->first();

            if (!$cart) {
                return $this->sendError('Cart not found.');
            }

            // Find the specific cart item by product_id
            $cartItem = $cart->items->where('product_id', $validated['product_id'])->first();

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
        $user = auth()->user();

        // Validate cart type (shop/stock)
        $validated = $request->validate([
            'cart_type' => 'required|in:shop,stock',
        ]);

        DB::beginTransaction();

        try {
            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $user->merchant->id)
                ->where('cart_type', $validated['cart_type'])
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
                $subtotal += $product->price * $item->quantity;
            }


            $vatCharge = env('VAT_CHARGE');
            $vat = $subtotal * $vatCharge;

            // Calculate Exelo amount (on sub total)
            $exeloCharge = env('EXELO_CHARGE');
            $exeloAmount = ($subtotal) * $exeloCharge;

            // Calculate total price including VAT
            $totalPriceWithVAT = $subtotal + $vat;

            // Prepare the response data
            $data = [
                'subtotal' => convertShillingToUSD($subtotal),
                'vat' => convertShillingToUSD($vat),
                'exelo_amount' => convertShillingToUSD($exeloAmount),
                'total' => round($totalPriceWithVAT,2),
                'total_in_usd' => convertShillingToUSD($totalPriceWithVAT),
                'cart_items' => $cart->items->map(function ($item) {
                    return [
                        'product_id' => $item->product->id,
                        'product_name' => $item->product->product_name,
                        'quantity' => $item->quantity,
                        'price' =>convertShillingToUSD( $item->product->price),
                        'total_price' => convertShillingToUSD($item->quantity * $item->product->price),
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
        $user = auth()->user();

        // Validate cart type (shop/stock)
        $validated = $request->validate([
            'cart_type' => 'required|in:shop,stock',
            'invoice_id' => 'required|exists:invoices,id',
        ]);

        DB::beginTransaction();

        try {
            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $user->merchant->id)
                ->where('cart_type', $validated['cart_type'])
                ->with('items.product') // Load products in the cart items
                ->first();

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
                    ->where('type', $validated['cart_type'])
                    ->first();

                if (!$inventory || $inventory->quantity < $item->quantity) {
                    DB::rollBack();
                    return $this->sendError("Insufficient stock for product: {$product->product_name}.");
                }

                // Update the total price
                $totalPrice += $product->price * $item->quantity;
            }

            // Calculate VAT (10%)
            $vatCharge = env('VAT_CHARGE');
            $vat = $totalPrice * $vatCharge;

            // Calculate Exelo amount (on sub total)
            $exeloCharge = env('EXELO_CHARGE');
            $exeloAmount = ($totalPrice) * $exeloCharge;

            // Calculate total price including VAT and Exelo amount
            $totalPriceWithVAT = $totalPrice + $vat;

            // Create an order
            $order = Order::create([
                'merchant_id' => $user->merchant->id,
                'sub_total' => round($totalPrice),
                'vat' => round($vat),
                'exelo_amount' => round($exeloAmount),
                'total_price' => round($totalPriceWithVAT),
                'order_type' => $validated['cart_type'],
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
                    'price' => $product->price,
                ]);

                // Decrement the inventory quantity for the specific cart type
                $inventory = ProductInventory::where('product_id', $product->id)
                    ->where('type', $validated['cart_type'])
                    ->first();

                if ($inventory) {
                    $inventory->decrement('quantity', $item->quantity);
                }
            }

            // Clear the cart after placing the order
            $cart->items()->delete();
            $cart->delete();

            $invoice = Invoice::find($request->invoice_id);

            $invoice->order_id = $order->id;
            $invoice->merchant_id = $order->merchant_id;
            $invoice->save();

            $transaction = Transaction::where('merchant_id', $order->merchant_id)->first();
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
        $validated = $request->validate([
            'order_id' => 'required|exists:orders,id',
            'invoice_id' => 'required|exists:invoices,id',
        ]);

        DB::beginTransaction();

        try {
            // Fetch the order by order_id
            $order = Order::findOrFail($validated['order_id']);
            $order->order_status = "Paid";
            $order->save();

            // Fetch the invoice by invoice_id
            $invoice = Invoice::findOrFail($validated['invoice_id']);

            // Update the invoice with the order_id and merchant_id from the order
            $invoice->order_id = $order->id;
            $invoice->merchant_id = $order->merchant_id;
            $invoice->save();

            $transaction = Transaction::where('merchant_id', $order->merchant_id)->first();
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
        $user = auth()->user();

        // Validate the input fields
        $validated = $request->validate([
            'cart_type' => 'required|in:shop,stock',
            'name' => 'nullable|string|max:255',
            'mobile_number' => 'nullable|string|max:20',
            'signature' => 'required|string',  // Expecting base64 string for signature

        ]);

        DB::beginTransaction();

        try {

            // Fetch the user's cart for the given type
            $cart = Cart::where('merchant_id', $user->merchant->id)
                ->where('cart_type', $validated['cart_type'])
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
                    ->where('type', $validated['cart_type'])
                    ->first();

                if (!$inventory || $inventory->quantity < $item->quantity) {
                    DB::rollBack();
                    return $this->sendError("Insufficient stock for product: {$product->product_name}.");
                }

                // Update the total price
                $totalPrice += $product->price * $item->quantity;
            }


            // Calculate VAT (10%)
            $vatCharge = env('VAT_CHARGE');
            $vat = $totalPrice * $vatCharge;

            // Calculate Exelo amount (on sub total)
            $exeloCharge = env('EXELO_CHARGE');
            $exeloAmount = ($totalPrice) * $exeloCharge;

            // Calculate total price including VAT and Exelo amount
            $totalPriceWithVAT = $totalPrice + $vat ;

            // Handle signature as base64 image upload
            $signaturePath = $this->saveBase64Image($request->signature, 'signatures');


            // Create the order
            $order = Order::create([
                'merchant_id' => $user->merchant->id,
                'name' => $request->input('name') ?? null,
                'mobile_number' => $request->input('mobile_number') ?? null,
                'signature' => $signaturePath,
                'sub_total' => round($totalPrice),
                'vat' => round($vat),
                'exelo_amount' => round($exeloAmount),
                'total_price' => round($totalPriceWithVAT),
                'order_type' => $validated['cart_type'],
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
                    'price' => $product->price,
                ]);

                // Decrement inventory for the specific cart type
                $inventory = ProductInventory::where('product_id', $product->id)
                    ->where('type', $validated['cart_type'])
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
        $validated = $request->validate([
            'cart_type' => 'required|in:shop,stock',
            'order_id' => 'required|exists:orders,id',
        ]);

        DB::beginTransaction();

        try {


            // Retrieve the order by the provided order_id
            $order = Order::find($validated['order_id']);


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
        $validated = $request->validate([
            'cart_type' => 'required|in:shop,stock',
            'order_id' => 'required|exists:orders,id',
        ]);

        DB::beginTransaction();

        try {


            // Retrieve the order by the provided order_id
            $order = Order::find($validated['order_id']);


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
        $validated = $request->validate([
            'order_type' => 'required|in:shop,stock',
        ]);

        try {
            // Get the authenticated merchant ID
            $authUser = auth()->user();

            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            $merchantID = $authUser->merchant->id;

            // Fetch orders by the given type
            $orders = Order::where('order_type', $validated['order_type'])
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
                    'sub_total' =>convertShillingToUSD( $order->sub_total),
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

        $validator = Validator::make($request->all(), [
            'order_status' => 'required|in:Pending,Paid,Complete',
        ]);


        // If validation fails, return an error response
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
            $orders = Order::where('order_status', $request->order_status)
                ->where('merchant_id', $merchantID)
                ->orderBy('id', 'DESC')
                ->with('items.product') // Load related order items and products
                ->get();

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
                    'total_price' => round($order->total_price,2),
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
            // Retrieve the order by order_id
            $order = Order::with('items.product')->find($request->order_id);

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
            $totalPriceWithVAT = $subtotal + $vat ;

            // Prepare the response data
            $data = [
                'order_id' => $order->id,
                'name' => $order->name,
                'initial_name' => $this->getInitials($order->name),
                'mobile_number' => $order->mobile_number,
                'signature' => Storage::url($order->signature),
                'merchant_id' => $order->merchant_id,
                'sub_total' => convertShillingToUSD($subtotal),
                'vat' => convertShillingToUSD($vat),
                'exelo_amount' => convertShillingToUSD($exeloAmount),
                'total' => round($totalPriceWithVAT,2),
                'total_in_usd' => convertShillingToUSD($totalPriceWithVAT),
                'order_status' => $order->order_status,
                'created_at' => showDatePicker($order->created_at),
                'order_items' => $order->items->map(function ($item) {
                    return [
                        'product_id' => $item->product->id,
                        'product_name' => $item->product->product_name,
                        'quantity' => $item->quantity,
                        'price' => convertShillingToUSD($item->price),
                        'total_price' =>convertShillingToUSD($item->quantity * $item->price),
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

            // Get merchant ID from authenticated user's merchant relation
            $merchantID = $authUser->merchant->id;

            // Retrieve the order with items and product relationship
            $order = Order::with('items.product', 'merchant', 'invoice')
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
            $mobileNO = ($order->mobile_number ?? $order->invoice->mobile_number);
            $phoneNo = str_replace('+252','',$mobileNO);
            $mobileNumberPrefix = substr($phoneNo , 0, 2);

            // Determine if it's edahab_number or zaad_number
            $mobileNumberType = in_array($mobileNumberPrefix, $dahabPrefixes) ? 'E-Dahab' : 'Zaad';

//            dd($mobileNumberType);
             // Prepare the response data
            $data = [
                'order_id' => $order->id,
                'merchant' => [
                    'business_name' => $order->merchant->business_name,
                    'merchant_code' => $order->merchant->merchant_code,
                    'cashier_name' => $order->merchant->first_name  . ' ' .  $order->merchant->last_name ,
                    'phone_number' => $order->merchant->phone_number,
                    'zaad_number' => $order->merchant->phone_number,
                ],
                'invoice' => [
                    'invoice_no' => $order->invoice->id,
                    'invoice_date' => showDate($order->invoice->created_at),
                    'payment_status' => $order->order_status,
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

}
