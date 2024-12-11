<?php

namespace App\Http\Controllers\API;

use App\Http\Resources\EmployeePermissionResource;
use App\Http\Resources\EmployeeResource;
use App\Http\Resources\MerchantPermissionResource;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\POSPermissionResource;
use App\Http\Resources\ShiftResource;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\Otp;
use App\Models\POSPermission;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Http\Controllers\API\BaseController;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

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
        $merchant = Merchant::with('currentSubscription')->where('phone_number', $phoneNumber)->first();

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

            // Get the authenticated user
            $authUser = auth()->user();

            // Ensure the authenticated user exists
            if (!$authUser) {
                return $this->sendError('User not authenticated.', '', 401);
            }


            if ($authUser->user_type == 'employee') {

                $merchant = $authUser->employee->merchant;

                $employee = Employee::where('phone_number', $phoneNumber)->where('merchant_id', $merchant->id)->first();
                if (!$employee) {
                    return $this->sendError('Phone number not found.', '', 404);
                }

                if (!Hash::check($request->pin, $employee->user->password)) {
                    return $this->sendError('Invalid PIN code.', '', 401);
                }

                $user = $merchant->user;

                return $this->sendResponse([
                    'user' => new UserResource($user),
                    'employee' => new EmployeeResource($employee),
                    'phone_number' => $phoneNumber,
                    'user_type' => $user->user_type,
                    'short_name' => $this->getInitials($user->name)
                ], 'Employee Pin Verified successful.');
            } else {

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
            }

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
                    'phone_number' => $employee->phone_number,
                    'user_type' => $authUser->user_type,
                    'short_name' => $this->getInitials($authUser->name),
                    'profile' => [
                        'first_name' => $employee->first_name,
                        'last_name' => $employee->last_name,
                        'phone_number' => $employee->phone_number,
                        'business_name' => $employee->merchant->business_name,
                        'merchant_code' => $employee->merchant->merchant_code,
                        'location' => $employee->location,
                        'role' => $employee->role,
                        'salary' => $employee->salary,
                        'salary_in_usd' => convertShillingToUSD($employee->salary),
                    ]

                ], 'User Info retrieved successfully.');
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

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'phone_number' => 'required|string',
            'type' => 'required|string',
        ]);

        DB::beginTransaction();

        try {
            $phoneNumber = str_replace(' ', '', $request->phone_number);

            // Determine if type is merchant or employee and retrieve the profile
            $userProfile = $request->type === 'merchant'
                ? Merchant::where('phone_number', $phoneNumber)->first()
                : Employee::where('phone_number', $phoneNumber)->first();

            if (!$userProfile) {
                $errorMsg = $request->type === 'merchant' ? 'Merchant Phone number not found.' : 'Employee Phone number not found.';
                return $this->sendError($errorMsg, 404);
            }

            // Generate OTP using last 6 digits of phone number
            $otpCode = substr($phoneNumber, -4);

            // Store OTP
            Otp::create([
                'user_id' => $userProfile->user_id,
                'otp' => $otpCode,
                'expires_at' => Carbon::now()->addMinutes(10),
            ]);

            DB::commit();

            return $this->sendResponse(['phone_number' => $request->phone_number, 'otp' => $otpCode], 'OTP sent to your mobile number.', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to send OTP.', ['error' => $e->getMessage()], 500);
        }
    }

    public function verifyOtpAndResetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'phone_number' => 'required|string|max:15',
            'otp' => 'required|digits:4',
            'type' => 'required|string',
        ]);

        DB::beginTransaction();

        try {
            $phoneNumber = str_replace(' ', '', $request->phone_number);

            $userProfile = $request->type === 'merchant'
                ? Merchant::where('phone_number', $phoneNumber)->first()
                : Employee::where('phone_number', $phoneNumber)->first();

            if (!$userProfile) {
                $errorMsg = $request->type === 'merchant' ? 'Merchant Phone number not found.' : 'Employee Phone number not found.';
                return $this->sendError($errorMsg, 404);
            }

            $user = $userProfile->user;

            // Verify OTP
            $otpRecord = Otp::where('user_id', $user->id)
                ->where('otp', $request->otp)
                ->where('expires_at', '>', Carbon::now())
                ->first();

            if (!$otpRecord) {
                return $this->sendError('Invalid or expired OTP.', ['error' => 'Invalid or expired OTP'], 401);
            }

            // Delete OTP after verification
            $otpRecord->delete();

            DB::commit();

            return $this->sendResponse(new UserResource($user), 'OTP Verified successfully.', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('Failed to verify OTP.', ['error' => $e->getMessage()], 500);
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string',
            'new_pin' => 'required|string|min:4',
            'repeat_pin' => 'required|same:new_pin',
            'type' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        DB::beginTransaction();

        try {
            $phoneNumber = str_replace(' ', '', $request->phone_number);

            $userProfile = $request->type === 'merchant'
                ? Merchant::where('phone_number', $phoneNumber)->first()
                : Employee::where('phone_number', $phoneNumber)->first();

            if (!$userProfile) {
                $errorMsg = $request->type === 'merchant' ? 'Merchant Phone number not found.' : 'Employee Phone number not found.';
                return $this->sendError($errorMsg, 404);
            }

            $user = $userProfile->user;

            // Update the user's password
            $user->password = Hash::make($request->new_pin);
            $user->pin = $request->new_pin;
            $user->save();

            DB::commit();

            return $this->sendResponse(new UserResource($user), 'PIN Reset successfully.', 200);
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('PIN change failed.', ['error' => $e->getMessage()], 500);
        }
    }


    public function saveShift(Request $request)
    {
        $request->validate([
            'start_time' => 'nullable|date_format:H:i:s', // Validate time format
            'end_time' => 'nullable|date_format:H:i:s',   // Validate time format
        ]);

        // Get the authenticated user
        $authUser = auth()->user();

        // Ensure the authenticated user exists
        if (!$authUser) {
            return $this->sendError('User not authenticated.', '', 401);
        }

        // Get the current date
        $currentDate = now()->toDateString();

        // Check if a shift already exists for today
        $shift = Shift::where('user_id', $authUser->id)
            ->whereDate('created_at', $currentDate)
            ->latest()
            ->first();

        if ($request->start_time && $request->end_time) {
            // Both start_time and end_time shouldn't be sent together
            return $this->sendError('Cannot set both start time and end time simultaneously.', '', 422);
        }

        if ($request->start_time) {
            // Ensure no shift with start_time exists for today
            if ($shift && !$shift->end_time) {
                return $this->sendError('The previous shift is still open. Please close it before starting a new one.', '', 422);
            }

            // Ensure a shift with a start time for today does not already exist
            if ($shift && $shift->start_time) {
                return $this->sendError('A shift has already been started for today.', '', 422);
            }

            // Create a new shift for today
            $shift = Shift::create([
                'user_id' => $authUser->id,
                'start_time' => $currentDate . ' ' . $request->start_time,
            ]);

            return $this->sendResponse(new ShiftResource($shift), 'Shift started successfully.');
        }

        if ($request->end_time) {
            // Ensure a shift exists for today with a start time
            if (!$shift || !$shift->start_time) {
                return $this->sendError('Start time is required before setting an end time.', '', 422);
            }

            // Ensure the shift is not already closed
            if ($shift->end_time) {
                return $this->sendError('The shift is already closed in same date', '', 422);
            }

            // Update the end_time for the shift
            $shift->update([
                'end_time' => $currentDate . ' ' . $request->end_time,
            ]);

            return $this->sendResponse(new ShiftResource($shift), 'Shift ended successfully.');
        }

        // If neither start_time nor end_time is present
        return $this->sendError('Either start time or end time is required.', '', 422);
    }

    public function getShiftData(Request $request)
    {
        // Get the authenticated user
        $authUser = auth()->user();

        // Ensure the authenticated user exists
        if (!$authUser) {
            return $this->sendError('User not authenticated.', '', 401);
        }

        // Get all shifts for the authenticated user
        $shifts = Shift::where('user_id', $authUser->id)->orderBy('created_at', 'desc')->get();

        if ($shifts->isEmpty()) {
            return $this->sendError('No shifts found for the user.', '', 404);
        }

        return $this->sendResponse(ShiftResource::collection($shifts), 'All shifts retrieved successfully.');
    }

    public function updateShift(Request $request, $shiftId)
    {
        $request->validate([
            'start_time' => 'nullable|date_format:H:i:s', // Validate time format
            'end_time' => 'nullable|date_format:H:i:s',   // Validate time format
        ]);

        // Get the authenticated user
        $authUser = auth()->user();

        // Ensure the authenticated user exists
        if (!$authUser) {
            return $this->sendError('User not authenticated.', '', 401);
        }

        // Find the shift by ID and ensure it belongs to the authenticated user
        $shift = Shift::where('id', $shiftId)->where('user_id', $authUser->id)->first();

        if (!$shift) {
            return $this->sendError('Shift not found or does not belong to the authenticated user.', '', 404);
        }

        // Ensure there is something to update
        if (!$request->start_time && !$request->end_time) {
            return $this->sendError('At least one of start time or end time must be provided to update the shift.', '', 422);
        }

        // Update fields if provided
        $updates = [];

        if ($request->start_time) {

            $updates['start_time'] = currentDateInsert() . ' ' . $request->start_time;
        }

        if ($request->end_time) {

            $updates['end_time'] = currentDateInsert() . ' ' . $request->end_time;
        }

        // Update the shift
        $shift->update($updates);

        return $this->sendResponse(new ShiftResource($shift), 'Shift updated successfully.');
    }


}
