<?php

namespace App\Http\Controllers\API;

use App\Http\Resources\EmployeePermissionResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\MerchantPermissionResource;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\POSPermissionResource;
use App\Http\Resources\UserResource;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\POSPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\API\BaseController;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class PassportAuthController extends BaseController
{
    /**
     * Handle login and return a token.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */


    public function login(Request $request)
    {

        $this->validateRequest($request, [
            'phone_number' => 'required|string|max:20',
        ]);

        $phoneNumber = str_replace(' ', '', $request->phone_number);
        $merchant = Merchant::where('phone_number', $phoneNumber)->first();

        if (!$merchant) {
            return $this->sendError('Phone number not found.', 404);
        }


        $user = $merchant->user;

        // Check if the user has a PIN
        $isPin = !is_null($user->pin);

        // Return response
        return $this->sendResponse([
            'user' => new UserResource($user),
            'merchant' => new MerchantResource($merchant),
            'phone_number' => $merchant->phone_number,
            'user_type' => $user->user_type,
            'is_pin' => $isPin,
            'short_name' => $this->getInitials($user->name)
        ], 'Enter the PIN Code');

    }

    public function verifyUser(Request $request)
    {
        try {
            // Validate the request based on user type
            $this->validateRequest($request, [
                'phone_number' => 'required|string|max:15',
                'pin' => 'required|string|size:4',
            ]);

            $phoneNumber = str_replace(' ', '', $request->phone_number);
            $merchant = Merchant::where('phone_number', $phoneNumber)->first();

//            dd($phoneNumber);
            if (!$merchant) {
                return $this->sendError('Phone number not found.', '', 404);
            }

            if (!Hash::check($request->pin, $merchant->user->password)) {
                return $this->sendError('Invalid PIN code.', '', 401);
            }

            $user = $merchant->user;
            $token = $user->createToken('PassportAuth')->accessToken;
            $merchant->load(['currentSubscription.subscriptionPlan']); // Load both subscription and subscriptionPlan relationships

            $currentSubscription = $merchant->currentSubscription;
            $noSubscription = new \stdClass();
            // If currentSubscription is null, set default values
            if (!$currentSubscription) {
                // Create a new stdClass object
                $noSubscription->subscription_plan_id = 1; // Default to Silver
                $noSubscription->reSubscriptionEligible = true; // Eligible for re-subscription
                // Add the current subscription back to the merchant for the resource
                $merchant->currentSubscription = $noSubscription;
            }

            $permissions = POSPermission::all();

            return $this->sendResponse([
                'permissions' => MerchantPermissionResource::collection($permissions),
                'user' => new UserResource($user),
                'merchant' => new MerchantResource($merchant),
                'token' => $token,
                'phone_number' => $merchant->phone_number,
                'user_type' => $user->user_type,
                'short_name' => $this->getInitials($user->name),
                'profile' => [
                    'first_name' => $merchant->first_name,
                    'last_name' => $merchant->last_name,
                    'phone_number' => $merchant->phone_number,
                    'business_name' => $merchant->business_name,
                    'merchant_code' => $merchant->merchant_code,
                    'location' => $merchant->location,
                ]
            ], 'Merchant Login successful.');

        } catch (\Exception $e) {
            return $this->sendError('An error occurred during the verification process.', ['error' => $e->getMessage()]);
        }
    }

    public function verifyUserPin(Request $request)
    {
        try {
            // Validate the request based on user type
            $this->validateRequest($request, [
                'phone_number' => 'required|string|max:15',
                'pin' => 'required|string|size:4',
            ]);

            $phoneNumber = str_replace(' ', '', $request->phone_number);
            $merchant = Merchant::where('phone_number', $phoneNumber)->first();

            if (!$merchant) {
                return $this->sendError('Phone number not found.', '', 404);
            }

            if (!Hash::check($request->pin, $merchant->user->password)) {
                return $this->sendError('Invalid PIN code.', '', 401);
            }

            $user = $merchant->user;


            return $this->sendResponse([
                'user' => new UserResource($user),
                'merchant' => new MerchantResource($merchant),
                'phone_number' => $merchant->phone_number,
                'user_type' => $user->user_type,
                'short_name' => $this->getInitials($user->name)
            ], 'Merchant Pin Verified successful.');

        } catch (\Exception $e) {
            return $this->sendError('An error occurred during the verification process.', ['error' => $e->getMessage()]);
        }
    }


    /**
     * Get the authenticated user information.
     *
     * @return \Illuminate\Http\Response
     */
    public function userInfo()
    {
        try {
            // Get the authenticated user
            $authUser = auth()->user();

            // Ensure the authenticated user exists
            if (!$authUser) {
                return $this->sendError('User not authenticated.', '', 401);
            }

            // Load employee or merchant relationships depending on the user type
            if ($authUser->user_type === 'employee') {
                // Load merchant through employee relationship
                $employee = $authUser->employee;
                if (!$employee || !$employee->merchant) {
                    return $this->sendError('Merchant not found for the authenticated employee.');
                }

                // Load employee permissions
                $employee->load('permissions.permission');

                // Return response with employee-specific data
                return $this->sendResponse([
                    'permissions' => EmployeePermissionResource::collection($employee->permissions),
                    'user' => new UserResource($employee->user),
                    'employee' => new EmployeeResource($employee),
                    'merchant' => new MerchantResource($employee->merchant),
                    'phone_number' => $employee->phone_nmber,
                    'user_type' => $authUser->user_type,
                    'short_name' => $this->getInitials($authUser->name),
                    'profile' => [
                        'first_name' => $employee->first_name,
                        'last_name' => $employee->last_name,
                        'business_name' => $employee->merchant->business_name,
                        'merchant_code' => $employee->merchant->merchant_code,
                        'location' => $employee->location,
                        'role' => $employee->role,
                        'salary' => $employee->salary,
                        'salary_in_usd' => convertShillingToUSD($employee->salary),
                    ]

                ], 'Employee Info retrieved successfully.');
            }

            // If the user is not an employee, assume they are a merchant
            $merchant = $authUser->merchant;

            // Ensure the authenticated user has a merchant relation
            if (!$merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Load merchant's user and subscription relationships
            $merchant->load(['currentSubscription.subscriptionPlan']);

            // Handle missing subscription scenario by setting default values
            $currentSubscription = $merchant->currentSubscription ?? (object)[
                    'subscription_plan_id' => 1,  // Default to Silver
                    'reSubscriptionEligible' => true,  // Eligible for re-subscription
                ];

            // If no subscription exists, attach the default one to the merchant
            if (!$merchant->currentSubscription) {
                $merchant->currentSubscription = $currentSubscription;
            }

            // Fetch all available merchant permissions
            $permissions = POSPermission::all();

            // Return response with merchant-specific data
            return $this->sendResponse([
                'permissions' => MerchantPermissionResource::collection($permissions),
                'user' => new UserResource($merchant->user),
                'merchant' => new MerchantResource($merchant),
                'phone_number' => $merchant->phone_number,
                'user_type' => $authUser->user_type,
                'short_name' => $this->getInitials($authUser->name),
                'profile' => [
                    'first_name' => $merchant->first_name,
                    'last_name' => $merchant->last_name,
                    'phone_number' => $merchant->phone_number,
                    'business_name' => $merchant->business_name,
                    'merchant_code' => $merchant->merchant_code,
                    'location' => $merchant->location,
                ]
            ], 'Merchant Info retrieved successfully.');

        } catch (\Exception $e) {
            // Handle any errors during the process
            return $this->sendError('An error occurred during the verification process.', ['error' => $e->getMessage()]);
        }
    }


    public function logout(Request $request)
    {
//        return $this->sendResponse([], 'i m here');
        // Validate that the user is authenticated
        if (!Auth::check()) {
            return $this->sendError('User not authenticated.', [], 401);
        }

        // Retrieve the authenticated user
        $user = Auth::user();

        // Revoke all tokens for the authenticated user
        $user->tokens->each(function ($token) {
            $token->revoke();
        });

        // Optionally, if you are using a refresh token or personal access tokens
        // and want to ensure they are also revoked, you can clear them here.

        // Return a successful response
        return $this->sendResponse([], 'User logout successful.');
    }


    public function checkInvoiceAndRegisterMerchant(Request $request)
    {
        try {
            // Validate the request inputs
            $this->validateRequest($request, [
                'phone_number' => 'required|string|max:15',
            ]);

            $phoneNumber = str_replace(' ', '', $request->phone_number);

            // Step 1: Check if an invoice exists for the phone number with type == 'Registration'
            $registrationInvoice = Invoice::where('mobile_number', $phoneNumber)
                ->where('type', 'Registration')
                ->where('status', 'Paid')
                ->first();

//            dd($phoneNumber);
            // Step 2: Check if a merchant exists for the phone number
            $existingMerchant = Merchant::where('phone_number', $phoneNumber)->first();

//            dd($existingMerchant);
            // Case 1: If boths invoice and merchant are found, block registration
            if ($registrationInvoice && $existingMerchant) {
                return $this->sendError('Merchant already exists and registration invoice has already been generated for this phone number.', '', 403);
            }

            // Case 2: If invoice is found but merchant is not found, return invoice data
            if ($registrationInvoice && !$existingMerchant) {
                return $this->sendResponse([
                    'is_invoice' => true,
                    'is_registration' => false,
                    'invoice_id' => $registrationInvoice->id,
                    'type' => $registrationInvoice->type,
                    'invoice_amount' => $registrationInvoice->amount,
                    'invoice_date' => $registrationInvoice->created_at,
                    'message' => 'Invoice found, but no merchant registered with this phone number.'
                ], 'Invoice data found.');
            }

            // Case 3: If no invoice and no merchant, proceed to register the merchant
            if (!$registrationInvoice && !$existingMerchant) {


                return $this->sendResponse([
                    'is_invoice' => false,
                    'is_registration' => false], 'This is new user');
            }

        } catch (\Exception $e) {
            return $this->sendError('An error occurred during the registration process.', ['error' => $e->getMessage()]);
        }
    }


}
