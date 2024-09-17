<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\CartItemResource;
use App\Http\Resources\CartResource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductInventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

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

            // Add the product to the cart
            $cartItem = CartItem::updateOrCreate(
                ['cart_id' => $cart->id, 'product_id' => $validated['product_id']],
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
            $cartItem->save();

            // Prepare the updated cart items data
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

            // Calculate VAT (10%)
            $vat = $subtotal * 0.10;

            // Calculate Exelo amount (on sub total)
            $exeloAmount = ($subtotal) * 0.03;

            // Calculate total price including VAT and Exelo amount
            $totalPriceWithVATAndExelo = $subtotal + $vat + $exeloAmount;

            // Prepare the response data
            $data = [
                'subtotal' => round($subtotal),
                'vat' => round($vat),
                'exelo_amount' => round($exeloAmount),
                'total' => round($totalPriceWithVATAndExelo),
                'cart_items' => $cart->items->map(function ($item) {
                    return [
                        'product_id' => $item->product->id,
                        'product_name' => $item->product->product_name,
                        'quantity' => $item->quantity,
                        'price' => $item->product->price,
                        'total_price' => $item->quantity * $item->product->price,
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
            $vat = $totalPrice * 0.10;

            // Calculate Exelo amount (3% after VAT is applied)
            $exeloAmount = ($totalPrice) * 0.03;

            // Calculate total price including VAT and Exelo amount
            $totalPriceWithVATAndExelo = $totalPrice + $vat + $exeloAmount;

            // Create an order
            $order = Order::create([
                'merchant_id' => $user->merchant->id,
                'sub_total' => round($totalPrice),
                'vat' => round($vat),
                'exelo_amount' => round($exeloAmount),
                'total_price' => round($totalPriceWithVATAndExelo),
                'order_type' => $validated['cart_type'],
                'order_status' => 'pending',
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

            DB::commit();

            return $this->sendResponse($order, 'Order placed successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Error placing order.', $e->getMessage());
        }
    }

    public function getOrdersByType(Request $request)
    {
        $validated = $request->validate([
            'order_type' => 'required|in:shop,stock',
        ]);

        try {
            // Fetch orders by the given type
            $orders = Order::where('order_type', $validated['order_type'])
                ->with('items.product') // Load related order items and products
                ->get();

            if ($orders->isEmpty()) {
                return $this->sendError('No orders found for the specified type.');
            }

            // Prepare the response data
            $data = $orders->map(function ($order) {
                return [
                    'order_id' => $order->id,
                    'sub_total' => $order->sub_total,
                    'vat' => $order->vat,
                    'exelo_amount' => $order->exelo_amount,
                    'total_price' => $order->total_price,
                    'order_status' => $order->order_status,
                    'order_items' => $order->items->map(function ($item) {
                        return [
                            'product_id' => $item->product_id,
                            'product_name' => $item->product->product_name,
                            'quantity' => $item->quantity,
                            'price' => $item->price,
                            'total_price' => $item->quantity * $item->price,
                        ];
                    }),
                ];
            });

            return $this->sendResponse($data, 'Orders retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error retrieving orders.', $e->getMessage());
        }
    }

}
