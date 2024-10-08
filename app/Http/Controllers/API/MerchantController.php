<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController;
use App\Http\Resources\MerchantResource;
use App\Http\Resources\UserResource;
use App\Models\Merchant;
use App\Models\MerchantSubscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;
use function Lcobucci\JWT\Token\all;

class MerchantController extends BaseController
{
    public function index()
    {
        $merchants = Merchant::all();
        return $this->sendResponse(MerchantResource::collection($merchants), 'Merchants retrieved successfully.');
    }

    public function signup(Request $request)
    {
        $validator = $this->validateRequest($request, [
            'phone_number' => 'required|string|max:15|unique:merchants,phone_number',

        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }


        $merchant = Merchant::create($request->all());
        return $this->sendResponse(new MerchantResource($merchant), 'Merchant Phone Number Registered successfully');
    }


    public function store(Request $request)
    {
        $validator = $this->validateRequest($request, [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'dob' => 'required|date',
            'location' => 'required|string|max:255',
            'business_name' => 'required|string|max:255',
            'merchant_code' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone_number' => 'required|string|max:15',
            'user_id' => 'nullable|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        DB::beginTransaction();

        try {

//            $phoneNumber =  str_replace('+252', '', $request->input('phone_number'));
            $phoneNumber = $request->input('phone_number');

            // Check if a merchant with the provided phone number already exists
            $merchantCount = Merchant::where('phone_number', $phoneNumber)->count();

            if ($merchantCount > 0) {
                return $this->sendError('Merchant mobile number is already registered', '');
            }

            // Remove spaces from phone number
            $phoneNumber = str_replace(' ', '', $request->input('phone_number'));

            // Verify phone number and get the specific company column
            $verifiedNumber = $this->verifiedPhoneNumber($phoneNumber);

            // If the phone number is invalid or company not recognized, return error
            if (!$verifiedNumber) {
                return $this->sendError('Invalid phone number. Company not recognized.');
            }

            // If edahab_number is detected, update only edahab_number
            if ($verifiedNumber == 'edahab_number') {
                $request->merge([
                    'phone_number' => $phoneNumber,
                    'edahab_number' => $phoneNumber,
                ]);
            } else {
                $request->merge([
                    'phone_number' => $phoneNumber,
                    'zaad_number' => $phoneNumber,
                    'golis_number' => $phoneNumber,
                    'evc_number' => $phoneNumber,
                ]);
            }

             // Create the merchant with the modified request data
            $merchant = Merchant::create($request->all());

            // Get Merchant Subscription
            $silverPackageID = 2;
            MerchantSubscription::create([
                'merchant_id' => $merchant->id,
                'subscription_plan_id' => $silverPackageID,
                'start_date' => now(),
                'end_date' => now()->addMonth(),
                'transaction_status' => 'Paid',
            ]);

            $merchant->load(['currentSubscription.subscriptionPlan']); // Load both subscription and subscriptionPlan relationships

            if ($request->input('email')) {
                $email = $request->input('email');
            } else {
                $email = $request->input('phone_number') . '@email.com';
            }

            $user = User::find($merchant->user_id);

            if ($user && $user->id) {
                return $this->sendResponse(['merchant' => new MerchantResource($merchant), 'user' => new UserResource($user)], 'Merchant is already Registered.');
            }

            // Create a new user account linked to the merchant
            $user = User::create([
                'name' => $merchant->first_name . ' ' . $merchant->last_name,
                'email' => $email,
                'password' => Hash::make('1234'), // Set a default or random password
                'user_type' => 'merchant',
            ]);

            $user->assignRole(Role::where('name', 'Merchant')->first());

            // Update merchant approval status and link to the new user
            $merchant->is_approved = true;
            $merchant->user_id = $user->id;
            $merchant->save();

            DB::commit();

            return $this->sendResponse(
                ['merchant' => new MerchantResource($merchant), 'user' => new UserResource($user), 'short_name' => $this->getInitials($user->name)],
                'Merchant registered/updated successfully.');

        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError('An error occurred while processing your request.', $e->getMessage(), 400);
        }
    }


    public function show($id)
    {
        $merchant = Merchant::find($id);

        if (is_null($merchant)) {
            return $this->sendError('Merchant not found.');
        }

        return $this->sendResponse($merchant, 'Merchant retrieved successfully.');
    }

    public function update(Request $request, Merchant $merchant)
    {
        $validator = $this->validateRequest($request, [
            'name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string|max:255',
            'country' => 'sometimes|required|string|max:255',
            'city' => 'sometimes|required|string|max:255',
            'state' => 'sometimes|required|string|max:255',
            'phone_number' => 'sometimes|required|string|max:15|unique:merchants,phone_number,' . $merchant->id,
            'user_id' => 'sometimes|required|exists:users,id',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $merchant->update($request->all());
        return $this->sendResponse(new MerchantResource($merchant), 'Merchant updated successfully.');
    }

    public function destroy(Merchant $merchant)
    {
        $merchant->delete();
        return $this->sendResponse([], 'Merchant deleted successfully.');
    }

    public function requestOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:15',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $merchant = Merchant::where('phone_number', $request->phone_number)->first();

        if (!$merchant) {
            return $this->sendError('Merchant not found.', 404);
        }

        // Generate OTP
        $otp = rand(1000, 9999);

        // Save OTP to the database
        $merchant->otp = $otp;
        $merchant->otp_expires_at = now()->addMinutes(10); // OTP valid for 10 minutes
        $merchant->save();

        return $this->sendResponse(['merchant' => new MerchantResource($merchant)], 'OTP sent successfully.');
    }

    public function verifyOtp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:15',
            'otp' => 'required|integer|digits:4',
            'new_pin' => 'required|string|size:4|confirmed', // new_pin_confirmation is required
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors());
        }

        $merchant = Merchant::where('phone_number', $request->phone_number)->first();

        if (!$merchant) {
            return $this->sendError('Merchant not found.', 404);
        }

        if ($merchant->otp !== (int)$request->otp || now()->greaterThan($merchant->otp_expires_at)) {
            return $this->sendError('Invalid or expired OTP.', 401);
        }

        $user = User::find($merchant->user_id);

        if (!$user) {
            return $this->sendError('User not found.', 404);
        }

        // Update the merchant's PIN code
        $user->pin = $request->new_pin;
        $user->password = Hash::make($request->new_pin);


        $user->save();

        // Clear the OTP fields
        $merchant->otp = null;
        $merchant->otp_expires_at = null;
        $merchant->save();

        return $this->sendResponse(['merchant' => new MerchantResource($merchant), 'user' => new UserResource($user)], 'PIN code updated successfully.');
    }


    public function verifyPhoneNumberByCompany(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'phone_number' => 'required|string|max:15',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation Error.', $validator->errors());
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

            // Verify the phone number to get the company name
            $company = trim($this->verifiedPhoneNumber($request->phone_number)); // Trim any extra spaces

            // If the company is not recognized, return an error
            if ($company === null) {
                return $this->sendError('Invalid phone number. Company not recognized.');
            }
            $companyName = strtoupper(str_replace('_number', ' ', $company));
            // Static column checks based on the company
            if ($company === 'edahab_number') {
                if ($merchant->edahab_number === $request->phone_number) {
                    return $this->sendResponse(['status' => 'Verified'], 'Phone Number is already verified by ' . $companyName, 404);
                }
            } elseif ($company === 'zaad_number') {
                if ($merchant->zaad_number === $request->phone_number) {
                    return $this->sendResponse(['status' => 'Verified'], 'Phone Number is already verified by ' . $companyName, 404);
                }
            } elseif ($company === 'golis_number') {
                if ($merchant->golis_number === $request->phone_number) {
                    return $this->sendResponse(['status' => 'Verified'], 'Phone Number is already verified by ' . $companyName, 404);
                }
            } elseif ($company === 'evc_number') {
                if ($merchant->evc_number === $request->phone_number) {
                    return $this->sendResponse(['status' => 'Verified'], 'Phone Number is already verified by ' . $companyName, 404);
                }
            } else {
                return $this->sendError('Invalid company type.', 404);
            }

            // Success response for verification
            return $this->sendResponse([
                'phone_number' => $request->phone_number,
                'company' => $companyName,
            ], 'Ready for verification');

        } catch (\Exception $e) {
            // Catch any exception and return an error message
            return $this->sendError('Something went wrong: ' . $e->getMessage(), 500);
        }
    }


    public function verificationComplete(Request $request)
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'phone_number' => 'required|string|max:15',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Validation Error.', $validator->errors());
            }

            // Get authenticated user
            $authUser = auth()->user();

            // Check if the authenticated user has an associated merchant
            if (!$authUser || !$authUser->merchant) {
                return $this->sendError('Merchant not found for the authenticated user.');
            }

            // Get the merchant
            $merchant = $authUser->merchant;

            // Clean up the phone number (remove spaces)
            $phoneNumber = str_replace(' ', '', $request->input('phone_number'));

            // Verify phone number and get the specific company column (edahab_number, zaad_number, etc.)
            $verifiedNumber = $this->verifiedPhoneNumber($phoneNumber);

            // If the phone number is invalid or company not recognized, return error
            if (!$verifiedNumber) {
                return $this->sendError('Invalid phone number. Company not recognized.');
            }

            // Apply logic before saving the merchant
            if ($verifiedNumber == 'edahab_number') {
                // Update only edahab_number
                $merchant->phone_number = $phoneNumber;
                $merchant->edahab_number = $phoneNumber;
            } else {
                // Update other columns if not edahab_number
                $merchant->phone_number = $phoneNumber;
                $merchant->zaad_number = $phoneNumber;
                $merchant->golis_number = $phoneNumber;
                $merchant->evc_number = $phoneNumber;
            }

             // Save the updated phone number in the merchant record
            $merchant->save();

            // Return success message
            return $this->sendResponse([
                'merchant' => new MerchantResource($merchant),
                'company' => $verifiedNumber,
            ], 'Phone number verification complete and updated successfully.');

        } catch (\Exception $e) {
            // Catch any exception and return an error message
            return $this->sendError('Something went wrong: ' . $e->getMessage(), 500);
        }
    }

    public function getPhoneNumbersStatus()
    {
        try {
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

            // Prepare the response data
            $phoneNumbersStatus = [
                'edahab_number' => [
                    'number' => $merchant->edahab_number,
                    'status' => !empty($merchant->edahab_number) ? 'Verified' : 'Not verified',
                ],
                'zaad_number' => [
                    'number' => $merchant->zaad_number,
                    'status' => !empty($merchant->zaad_number) ? 'Verified' : 'Not verified',
                ],
                'golis_number' => [
                    'number' => $merchant->golis_number,
                    'status' => !empty($merchant->golis_number) ? 'Verified' : 'Not verified',
                ],
                'evc_number' => [
                    'number' => $merchant->evc_number,
                    'status' => !empty($merchant->evc_number) ? 'Verified' : 'Not verified',
                ],
            ];

            // Return the response with phone numbers status
            return $this->sendResponse($phoneNumbersStatus, 'Phone numbers status retrieved successfully.');

        } catch (\Exception $e) {
            // Catch any exception and return an error message
            return $this->sendError('Something went wrong: ' . $e->getMessage(), 500);
        }
    }


}
