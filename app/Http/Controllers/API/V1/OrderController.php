<?php

namespace App\Http\Controllers\API\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\ListOrdersRequest;
use App\Http\Requests\API\V1\PayOrderRequest;
use App\Http\Requests\API\V1\StoreOrderRequest;
use App\Http\Requests\API\V1\UpdateOrderStatusRequest;
use App\Models\Merchant;
use App\Models\Order;
use App\Services\ChargeService;
use App\Services\OrderService;
use App\Support\ApiResponse;
use App\Support\Idempotency;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders, private readonly ChargeService $charges) {}

    public function index(ListOrdersRequest $request): JsonResponse
    {
        $result = $this->orders->paginate($this->merchant($request), $request->validated());

        return ApiResponse::success($result['items'], null, 200, ['pagination' => $result['pagination'], 'summary' => $result['summary']]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->orders->show($this->merchant($request), $id));
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $result = $this->orders->create($request->user(), $this->merchant($request), $request->validated());

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    public function status(UpdateOrderStatusRequest $request, int $id): JsonResponse
    {
        $merchant = $this->merchant($request);
        $body = $request->validated();

        $result = Idempotency::run("m{$merchant->id}:order:{$id}:status", $body['idempotency_key'], $body, fn () => [
            'data' => $this->orders->transition($merchant, $id, $body['status'], $body['reason'] ?? null),
            'message' => 'Order marked '.$body['status'],
            'status' => 200,
        ]);

        return ApiResponse::success($result['data'], $result['message'], $result['status']);
    }

    public function pay(PayOrderRequest $request, int $id): JsonResponse
    {
        $merchant = $this->merchant($request);
        $order = Order::where('merchant_id', $merchant->id)->find($id)
            ?? throw new ApiException('order.not_found', 'We could not find that order', 404);

        $result = $this->charges->create($request->user(), $merchant, [
            'rail' => $request->validated('rail'),
            'purpose' => 'order_settlement',
            'amount' => ['amount' => Money::toMinor((float) $order->total_price, 'USD'), 'currency' => 'USD'],
            'customer' => $request->validated('customer') ?? [],
            'order_id' => $order->id,
            'idempotency_key' => $request->validated('idempotency_key'),
            'tendered' => $request->validated('amount_tendered.amount'),
        ]);

        $data = $result['data'];

        if ($result['status'] === 202) {
            $data = ['status' => 'pending', 'charge_id' => $data['charge_id'], 'order_id' => $order->id, 'poll' => $data['poll'], 'poll_after' => $data['poll_after']];

            return ApiResponse::success($data, $result['message'], 202);
        }

        $tendered = $request->validated('amount_tendered.amount');
        $data = ['status' => 'paid', 'charge_id' => $data['charge_id'], 'order' => $this->orders->show($merchant, $order->id)]
            + ($tendered !== null ? ['change_due' => Money::usd($tendered - $data['customer_charge']['amount'])] : []);

        return ApiResponse::success($data, 'Order paid');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->orders->delete($this->merchant($request), $id), 'Pending order deleted');
    }

    public function receipt(Request $request, int $id): JsonResponse
    {
        return ApiResponse::success($this->orders->receipt($this->merchant($request), $id));
    }

    private function merchant(Request $request): Merchant
    {
        return $request->user()->actingMerchant()
            ?? throw new ApiException('merchant.not_found', 'We could not find that shop', 404);
    }
}
