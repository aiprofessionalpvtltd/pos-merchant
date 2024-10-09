<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\EmployeePermissionResource;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\POSPermissionResource;
use App\Http\Resources\UserResource;
use App\Models\Category;
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
    public function getPOSPermission()
    {
        try {
            $permissions = POSPermission::all();
            if ($permissions->isEmpty()) {
                return $this->sendResponse([],'No permissions found.');
            }
            return $this->sendResponse(POSPermissionResource::collection($permissions), 'Categories retrieved successfully.');
        } catch (\Exception $e) {
            return $this->sendError('Error fetching permissions.', $e->getMessage());
        }
    }

    public function getSingleEmployee($id)
    {
        try {
            // Find the employee by ID
            $employee = Employee::with('permissions.permission')->find($id);

            // Check if employee exists
            if (!$employee) {
                return $this->sendError('Employee not found.', '', 404);
            }

            // Return the employee data using the EmployeeResource
            return $this->sendResponse(new EmployeeResource($employee), 'Employee retrieved successfully.');

        } catch (\Exception $e) {
            return $this->sendError('An error occurred while fetching the employee.', ['error' => $e->getMessage()]);
        }
    }

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

        if ($authUser->user_type == 'employee') {
            $authUser->merchant = $authUser->employee->merchant;
        }

        // Get the merchant
        $merchant = $authUser->merchant;

        if (!$merchant) {
            return $this->sendError('Merchant Not Found', 404);
        }

        // Check for duplicate employee with the same phone number
        $existingEmployee = Employee::where('phone_number', $request->phone_number)
            ->where('merchant_id', $merchant->id)
            ->first();

        if ($existingEmployee) {
            return response()->json(['error' => 'Employee with this phone number already exists.'], 409); // Conflict status code
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
                'email' => $request->phone_number . '@email.com', // Set email based on phone number
                'password' => Hash::make('1234'), // Default or random password
                'user_type' => 'employee',
            ]);

            // Associate user with employee
            $employee->user_id = $user->id;
            $employee->save();

            // Load relations
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

    public function updateEmployee(Request $request, $id)
    {
        // Validate request data
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:15|unique:employees,phone_number,' . $id,
            'first_name' => 'required|string|max:50',
            'last_name' => 'required|string|max:50',
            'dob' => 'required|date',
//            'location' => 'required|string|max:100',
//            'role' => 'required|string|max:50',
            'salary' => 'required|numeric|min:0',
//            'permissions' => 'sometimes|array', // Expecting an array of permission IDs
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

        if ($authUser->user_type == 'employee') {
            $authUser->merchant = $authUser->employee->merchant;
        }
        // Get the merchant
        $merchant = $authUser->merchant;

        if (!$merchant) {
            return $this->sendError('Merchant Not Found', 404);
        }

        // Find the employee by ID
        $employee = Employee::find($id);

        if (!$employee) {
            return $this->sendError('Employee not found.', '', 404);
        }

        // Start a database transaction
        DB::beginTransaction();

        try {
            // Update employee details
            $employee->update([
                'phone_number' => $request->phone_number,
                'first_name' => $request->first_name,
                'last_name' => $request->last_name,
                'dob' => $request->dob,
                 'salary' => $request->salary,
            ]);



            if($request->permissions){
                // Sync permissions: remove old and add new permissions
                EmployeePermission::where('employee_id', $employee->id)->delete();
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
            }


            // Update the associated user account
            $user = $employee->user;

            if ($user) {
                $user->update([
                    'name' => $employee->first_name . ' ' . $employee->last_name,
                    'email' => $request->phone_number . '@email.com', // Adjust as needed
                ]);
            }

            $employee->load('permissions.permission', 'user');

            // Commit the transaction
            DB::commit();
            return response()->json([
                'employee' => new EmployeeResource($employee),
                'message' => 'Employee updated successfully.',
            ], 200);

        } catch (\Exception $e) {
            // Rollback the transaction if any error occurs
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to update employee: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteEmployee($id)
    {
        // Start a database transaction
        DB::beginTransaction();

        try {
            // Find the employee by ID
            $employee = Employee::find($id);

            if (!$employee) {
                return response()->json(['error' => 'Employee not found.'], 404);
            }

            // Update the employee's status to 'inactive'
            $employee->update([
                'status' => 'inactive',
            ]);

            // Check if the employee has an associated user
            if ($employee->user) {
                // Soft delete the associated user
                $employee->user->delete();
            }

            // Commit the transaction
            DB::commit();

            return response()->json([
                'message' => 'Employee status updated to inactive and associated user soft deleted successfully.',
            ], 200);

        } catch (\Exception $e) {
            // Rollback the transaction if any error occurs
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to delete employee: ' . $e->getMessage(),
            ], 500);
        }
    }

    // Get employee records with permissions
    public function getAllEmployees()
    {
        // Get authenticated user
        $authUser = auth()->user();

        // Check if the authenticated user has an associated merchant
        if (!$authUser || !$authUser->merchant) {
            return response()->json(['error' => 'Merchant not found for the authenticated user.'], 404);
        }

        if ($authUser->user_type == 'employee') {
            $authUser->merchant = $authUser->employee->merchant;
        }

        try {
            // Fetch active employees associated with the authenticated merchant,
            // exclude soft deleted users and inactive employees
            $employees = Employee::with(['permissions.permission', 'user' => function ($query) {
                $query->whereNull('deleted_at'); // Exclude soft-deleted users
            }])
                ->where('merchant_id', $authUser->merchant->id)
                ->where('status', 'active') // Only fetch active employees
                ->orderBy('id','DESC')
                ->get();

            // Check if employees were found
            if ($employees->isEmpty()) {
                return response()->json(['message' => 'No active employees found for this merchant.'], 404);
            }

            return $this->sendResponse(EmployeeResource::collection($employees), 'Employees  retrieved successfully.');


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

            // Check if the employee exists
            if (!$employee) {
                return $this->sendError('Phone number not found.', '', 404);
            }

            // Check if the employee's status is inactive
            if ($employee->status === 'inactive') {
                return $this->sendError('Employee is inactive.', '', 403);
            }

            // Check if the user is soft deleted
            if ($employee->user->trashed()) {
                return $this->sendError('User account has been deleted.', '', 403);
            }

            // Check if the provided PIN matches the user's password
            if (!Hash::check($request->pin, $employee->user->password)) {
                return $this->sendError('Invalid PIN code.', '', 401);
            }

            // Retrieve the associated user and merchant
            $user = $employee->user;
            $merchant = $employee->merchant;

            // Generate an access token for the user
            $token = $user->createToken('PassportAuth')->accessToken;

            // Load the employee's permissions
            $employee->load('permissions.permission');

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

            // Return a successful response with the user, employee, merchant, and token
            return $this->sendResponse([
                'permissions' => EmployeePermissionResource::collection($employee->permissions),
                'user' => new UserResource($user),
                'employee' => new EmployeeResource($employee),
                'merchant' => new MerchantResource($merchant),
                'token' => $token,
                'phone_number' => $employee->phone_number,
                'user_type' => $user->user_type,
                'short_name' => $this->getInitials($employee->first_name . ' ' . $employee->last_name),
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
            ], 'Employee login successful.');

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
