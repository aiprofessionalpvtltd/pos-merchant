<?php

namespace App\Http\Controllers\API\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\V1\ChangePinRequest;
use App\Http\Requests\API\V1\LogoutRequest;
use App\Http\Requests\API\V1\PhoneNumberRequest;
use App\Http\Requests\API\V1\PinLoginRequest;
use App\Http\Requests\API\V1\ResetPinRequest;
use App\Http\Requests\API\V1\ResetVerifyRequest;
use App\Http\Requests\API\V1\StorePinRequest;
use App\Http\Requests\API\V1\VerifyPinRequest;
use App\Services\AuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function lookup(PhoneNumberRequest $request): JsonResponse
    {
        $result = $this->auth->lookup($request->validated('phone_number'));

        return ApiResponse::success($result['data'], $result['message']);
    }

    public function login(PinLoginRequest $request): JsonResponse
    {
        $data = $this->auth->loginWithPin(
            $request->validated('phone_number'),
            $request->validated('pin'),
            $request->header('X-EXELO-Device-Id'),
        );

        $name = $data['user']['first_name'] ?: $data['user']['short_name'];

        return ApiResponse::success($data, 'Welcome back, '.$name);
    }

    public function storePin(StorePinRequest $request): JsonResponse
    {
        $data = $this->auth->createPin(
            $request->validated('phone_number'),
            $request->validated('pin'),
            $request->header('X-EXELO-Device-Id'),
        );

        return ApiResponse::success($data, 'PIN created', 201);
    }

    public function changePin(ChangePinRequest $request): JsonResponse
    {
        $data = $this->auth->changePin(
            $request->user(),
            $request->validated('current_pin'),
            $request->validated('pin'),
            $request->user()->currentAccessToken()?->id,
        );

        return ApiResponse::success($data, 'PIN updated');
    }

    public function verifyPin(VerifyPinRequest $request): JsonResponse
    {
        $data = $this->auth->verifyPin($request->user(), $request->validated('pin'), $request->validated('scope'));

        return ApiResponse::success($data, 'Confirmed');
    }

    public function requestReset(PhoneNumberRequest $request): JsonResponse
    {
        $data = $this->auth->requestPinReset($request->validated('phone_number'));

        return ApiResponse::success($data, 'We sent a code to '.$data['masked_phone']);
    }

    public function verifyReset(ResetVerifyRequest $request): JsonResponse
    {
        $data = $this->auth->verifyPinResetOtp($request->validated('phone_number'), $request->validated('otp'));

        return ApiResponse::success($data, 'Code confirmed');
    }

    public function reset(ResetPinRequest $request): JsonResponse
    {
        $data = $this->auth->resetPin(
            $request->validated('reset_token'),
            $request->validated('pin'),
            $request->header('X-EXELO-Device-Id'),
        );

        return ApiResponse::success($data, 'PIN updated');
    }

    public function session(Request $request): JsonResponse
    {
        return ApiResponse::success($this->auth->session($request->user()));
    }

    public function logout(LogoutRequest $request): JsonResponse
    {
        $revoked = $this->auth->logout(
            $request->user(),
            $request->user()->currentAccessToken()?->id,
            $request->boolean('all_devices'),
        );

        return ApiResponse::success(['revoked_devices' => $revoked], 'Signed out');
    }
}
