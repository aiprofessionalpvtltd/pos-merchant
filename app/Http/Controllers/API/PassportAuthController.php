<?php

namespace App\Http\Controllers\API;

use Illuminate\Http\Request;
use App\Http\Controllers\API\BaseController;
use Illuminate\Support\Facades\Auth;

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
        // Validate the request
        $this->validateRequest($request, [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $credentials = [
            'email' => $request->email,
            'password' => $request->password
        ];

        if (Auth::attempt($credentials)) {
            $user = Auth::user();
            $token = $user->createToken('PassportAuth')->accessToken;

            return $this->sendResponse([
                'user' => $user,
                'token' => $token
            ], 'Login successful.');        } else {
            return $this->sendError('Unauthorized', 401);
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
