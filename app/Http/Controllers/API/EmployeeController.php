<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\UserResource;
use App\Models\EmployeePermission;
use App\Models\Merchant;
use App\Models\POSPermission;
use App\Models\User;
use Illuminate\Http\Request;


use App\Http\Controllers\API\BaseController;
use App\Models\Employee;
use App\Models\Permission;
use App\Http\Resources\EmployeeResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class EmployeeController extends BaseController
{
    // Store a new employee
    public function store(Request $request)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:15',
            'first_name' => 'required|string|max:50',
            'last_name' => 'required|string|max:50',
            'dob' => 'required|date',
            'location' => 'required|string|max:100',
            'role' => 'required|string|max:50',
            'salary' => 'required|numeric|min:0',
            'permissions' => 'required|array', // Expecting an array of permission IDs
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        // Get authenticated user
        $authUser = auth()->user();

        // Check if the authenticated user has an associated merchant
        if (!$authUser || !$authUser->merchant) {
            return $this->sendError('Merchant not found for the authenticated user.');
        }

        // Get the merchant
        $merchant = $authUser->merchant;

        if (!$merchant) {
            return $this->sendError('Merchant Not Found', 404);
        }

        // Start a database transaction
        DB::beginTransaction();

        try {
            // Create a new employee
            $employee = Employee::create([
                'merchant_id' => $merchant->id,
                'phone_number' => $request->phone_number,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'dob' => $request->dob,
                'location' => $request->location,
                'role' => $request->role,
                'salary' => $request->salary,
            ]);

            // Attach permissions using the Permission model
            foreach ($request->permissions as $permissionId) {
                // Find the permission by ID
                $permission = POSPermission::find($permissionId);

                if ($permission) {
                    EmployeePermission::create([
                        'employee_id' => $employee->id,
                        'pos_permission_id' => $permissionId,
                    ]);
                }
            }

            // Create a new user account linked to the employee
            $user = User::create([
                'name' => $employee->first_name . ' ' . $employee->last_name,
                'email' => $request->phone_number . '@email.com', // Make sure to include email in the request
                'password' => Hash::make('1234'), // Set a default or random password
                'user_type' => 'employee',
            ]);

            $employee->user_id = $user->id;
            $employee->save();


            $employee->load('permissions.permission', 'user');
            // Commit the transaction
            DB::commit();
            return response()->json([
                'employee' => new EmployeeResource($employee),
                'message' => 'Employee created successfully.',
            ], 201);
        } catch (\Exception $e) {
            // Rollback the transaction if any error occurs
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to create employee: ' . $e->getMessage(),
            ], 500);
        }
    }

    // Get employee records with permissions
    public function index()
    {
        // Get authenticated user
        $authUser = auth()->user();

        // Check if the authenticated user has an associated merchant
        if (!$authUser || !$authUser->merchant) {
            return response()->json(['error' => 'Merchant not found for the authenticated user.'], 404);
        }

        try {
            // Fetch employees associated with the authenticated merchant, including their permissions
            $employees = Employee::with('permissions.permission', 'user')
                ->where('merchant_id', $authUser->merchant->id)
                ->get();

            // Check if employees were found
            if ($employees->isEmpty()) {
                return response()->json(['message' => 'No employees found for this merchant.'], 404);
            }

            return EmployeeResource::collection($employees);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to retrieve employees: ' . $e->getMessage()], 500);
        }
    }

    public function getMerchantDetail(Request $request)
    {
        try {
            // Validate the request input
            $this->validateRequest($request, [
                'phone_number' => 'required|string|max:15',
            ]);

            // Clean the phone number by removing spaces
            $phoneNumber = str_replace(' ', '', $request->phone_number);

            // Find the employee by phone number
            $employee = Employee::where('phone_number', $phoneNumber)->first();

            // Return error if employee is not found
            if (!$employee) {
                return $this->sendError('Phone number not found.', '', 404);
            }

            // Get the associated merchant
            $merchant = $employee->merchant;
            $user = $employee->user;

            // Check if the user has a PIN
            $isPin = !is_null($user->pin);

            // Prepare the response data
            $responseData = [
                'employee' => new EmployeeResource($employee),
                'merchant' => new MerchantResource($merchant),
                'merchant_short_name' => $this->getInitials($merchant->first_name . ' ' . $merchant->last_name),
                'employee_short_name' => $this->getInitials($employee->first_name . ' ' . $employee->last_name),
                'is_pin' => $isPin,
            ];

            return $this->sendResponse($responseData, 'Merchant Detail retrieved successfully.');

        } catch (\Exception $e) {
            return $this->sendError('An error occurred during the verification process.', ['error' => $e->getMessage()]);
        }
    }

    public function verifyEmployee(Request $request)
    {
        try {
            // Validate the request based on user type
            $this->validateRequest($request, [
                'phone_number' => 'required|string|max:15',
                'pin' => 'required|string|size:4',
            ]);

            $phoneNumber = str_replace(' ', '', $request->phone_number);
            $employee = Employee::where('phone_number', $phoneNumber)->first();

//            dd($phoneNumber);
            if (!$employee) {
                return $this->sendError('Phone number not found.', '', 404);
            }

            if (!Hash::check($request->pin, $employee->user->password)) {
                return $this->sendError('Invalid PIN code.', '', 401);
            }

            $user = $employee->user;
            $merchant = $employee->merchant;
            $token = $user->createToken('PassportAuth')->accessToken;

            $employee->load('permissions.permission');
            return $this->sendResponse([
                'user' => new UserResource($user),
                'employee' => new EmployeeResource($employee),
                'merchant' => new MerchantResource($merchant),
                'token' => $token,
                'user_type' => $user->user_type,
                'short_name' => $this->getInitials($employee->first_name . ' ' . $employee->last_name),
            ], 'Employee Login successful.');

        } catch (\Exception $e) {
            return $this->sendError('An error occurred during the verification process.', ['error' => $e->getMessage()]);
        }
    }

    public function storePin(Request $request)
    {
        // Validate the request data
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:15',
            'pin' => 'required|string|size:4',
            'repeat_pin' => 'required|string|same:pin',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {
            // Normalize the phone number
            $phoneNumber = str_replace(' ', '', $request->phone_number);

            // Check if the merchant exists
            $employee = Employee::where('phone_number', $phoneNumber)->first();

            if (!$employee) {
                return $this->sendError('Employee mobile number is not registered', '');
            }

            // Fetch the user associated with the merchant
            $user = $employee->user;
            $merchant = $employee->merchant;

            // Update the user's PIN and password
            $user->pin = $request->pin;
            $user->password = Hash::make($request->pin);
            $user->save();

            // Generate the access token
            $token = $user->createToken('PassportAuth')->accessToken;

            DB::commit();

            // Prepare the response data
            return $this->sendResponse([
                'employee' => new EmployeeResource($employee),
                'user' => new UserResource($user),
                'merchant' => new MerchantResource($merchant),
                'token' => $token,
                'user_type' => $user->user_type,
                'short_name' => $this->getInitials($employee->first_name . ' ' . $employee->last_name),
            ], 'Employee Login successful.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('An error occurred while storing the PIN code.', ['error' => $e->getMessage()]);
        }
    }


}
