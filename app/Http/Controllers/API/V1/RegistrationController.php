<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\CompleteAccountVerificationRequest;
use App\Http\Requests\API\V1\CompleteVerificationRequest;
use App\Http\Requests\API\V1\IssueInvoiceRequest;
use App\Http\Requests\API\V1\PhoneNumberRequest;
use App\Http\Requests\API\V1\QuoteRequest;
use App\Http\Requests\API\V1\RegisterMerchantRequest;
use App\Services\RegistrationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegistrationController extends Controller
{
    public function __construct(private readonly RegistrationService $registration) {}

    public function states(): JsonResponse
    {
        return ApiResponse::success($this->registration->states());
    }

    public function quote(QuoteRequest $request): JsonResponse
    {
        return ApiResponse::success($this->registration->quote($request->validated('purpose') ?? 'registration'));
    }

    public function checkPhone(PhoneNumberRequest $request): JsonResponse
    {
        $result = $this->registration->checkPhone($request->validated('phone_number'));

        return ApiResponse::success($result['data'], $result['message']);
    }

    public function issueInvoice(IssueInvoiceRequest $request): JsonResponse
    {
        $result = $this->registration->issueInvoice($request->validated());

        return ApiResponse::success($result['data'], $result['message'], 202);
    }

    public function invoiceStatus(string $invoiceId): JsonResponse
    {
        $result = $this->registration->invoiceStatus($invoiceId);

        return ApiResponse::success($result['data'], $result['message']);
    }

    public function simulatePayment(Request $request, string $invoiceId): JsonResponse
    {
        $outcome = $request->validate(['outcome' => ['nullable', 'in:paid,failed,cancelled,expired']])['outcome'] ?? 'paid';

        $result = $this->registration->simulatePayment($invoiceId, $outcome);

        return ApiResponse::success($result['data'], $result['message'] ?? 'Simulated');
    }

    public function register(RegisterMerchantRequest $request): JsonResponse
    {
        $data = $this->registration->registerMerchant($request->validated());

        return ApiResponse::success($data, 'Account created. Set your PIN to continue.', 201);
    }

    public function completeVerification(CompleteVerificationRequest $request, int $id): JsonResponse
    {
        $data = $this->registration->completeVerification($request->user(), $id, $request->validated('invoice_id'));

        return ApiResponse::success($data, 'Your payment numbers are verified');
    }

    public function completeAccountVerification(CompleteAccountVerificationRequest $request): JsonResponse
    {
        $data = $this->registration->completeAccountVerification($request->user(), $request->validated('invoice_id'));

        return ApiResponse::success($data, 'Your phone number is verified. You can now create your shop.');
    }
}
