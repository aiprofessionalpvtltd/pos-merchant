<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\EmployeePermission;
use App\Models\POSPermission;
use App\Models\User;
use Illuminate\Http\Request;


use App\Http\Controllers\API\BaseController;
use App\Models\Employee;
use App\Models\Permission;
use App\Http\Resources\EmployeeResource;
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


            $employee->load('permissions.permission' ,'user');
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
            $employees = Employee::with('permissions.permission' ,'user')
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

}
