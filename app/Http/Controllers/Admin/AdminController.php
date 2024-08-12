<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCandidate;
use App\Http\Traits\CandidateTrait;
use App\Http\Traits\EmployerTrait;
use App\Http\Traits\MentorTrait;
use App\Models\Complaint;
use App\Models\Intern;
use App\Models\User;
use Ichtrojan\Otp\Otp;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Yajra\DataTables\DataTables;

//use Laravie\Codex\Contracts\Response;
//use Laravie\Codex\Transport\Curl;

class AdminController extends Controller
{

    private $otp;

    public function __construct(Otp $Otp)
    {
        $this->otp = $Otp;
    }


    public function updatePassword()
    {
        $userID = Auth::user()->id;
        return view('auth.updatePassword')->with(['title' => 'Change Password', 'userID' => $userID]);
    }

    public function ChangePassword(Request $request, $id)
    {

        $validator = Validator::make($request->all(), [
            'oldpassword' => 'required',
            'password' => 'required',
        ]);
        if ($validator->fails()) {
            return redirect()->route('update-password')
                ->withErrors($validator)
                ->withInput();
        }

        $hashedPassword = Auth::user()->password;

        if (Hash::check($request->oldpassword, $hashedPassword)) {

            if (!Hash::check($request->password, $hashedPassword)) {

                $users = User::find(Auth::user()->id);
                $users->password = $request->password;
                $user = User::where('id', Auth::user()->id)->update(array('password' => $users->password));
                return redirect()->back()->with('success', "Password Change successfully");
            } else {
                return redirect()->back()->with('error', "New password can not be the old password!");
            }
        } else {
            return redirect()->back()->with('error', "Old password doesnt matched");
        }
    }
}


