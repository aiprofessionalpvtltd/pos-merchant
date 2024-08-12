<?php

namespace App\Http\Controllers\API;

use App\Http\Resources\MerchantResource;
use App\Http\Resources\UserResource;
use App\Models\Merchant;
use Illuminate\Http\Request;
use App\Http\Controllers\API\BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class PassportAuthController extends BaseController
{
    /**
     * Handle login and return a token.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function login(Request $request)
    {
        // Validate the request based on user type
        if ($request->has('email')) {
            $this->validateRequest($request, [
                'email' => 'required|email',
                'password' => 'required',
            ]);

            $credentials = [
                'email' => $request->email,
                'password' => $request->password,
            ];

            if (Auth::attempt($credentials)) {
                $user = Auth::user();
                $token = $user->createToken('POS')->accessToken;

                return $this->sendResponse([
                    'user' => $user,
                    'token' => $token,
                    'user_type' => $user->user_type
                ], 'Login successful.');
            } else {
                return $this->sendError('Unauthorized', 401);
            }
        } elseif ($request->has('phone_number')) {
            $this->validateRequest($request, [
                'phone_number' => 'required|string|max:15',
                'pin_code' => 'required|string|size:4',
            ]);

            $merchant = Merchant::where('phone_number', $request->phone_number)->first();

            if (!$merchant) {
                return $this->sendError('Phone number not found.', 404);
            }

            if (!Hash::check($request->pin_code, $merchant->user->password)) {
                return $this->sendError('Invalid PIN code.', 401);
            }

            $user = $merchant->user;
            $token = $user->createToken('PassportAuth')->accessToken;

            return $this->sendResponse([
                'user' => new UserResource($user),
                'merchant' => new MerchantResource($merchant),
                'token' => $token,
                'user_type' => $user->user_type
            ], 'Merchant Login successful.');
        } else {
            return $this->sendError('Invalid login credentials.', 400);
        }
    }

    /**
     * Get the authenticated user information.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function userInfo()
    {
        $user = Auth::user();

        return $this->sendResponse($user, 'User information retrieved successfully.');
    }
}
