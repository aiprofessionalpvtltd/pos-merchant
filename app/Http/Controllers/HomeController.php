<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HomeController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth')->except('verifyPayment' ,'deleteAccount' ,'delete');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {
        return view('home');
    }

    public function verifyPayment(Request  $request)
    {
        dd($request->all());
     }

    public function delete()
    {
        return view('auth.delete_account');
    }

    public function deleteAccount(Request $request)
    {
         $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|max:20',

        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $phoneNumber = str_replace(' ', '', $request->phone_number);
        $merchant = Merchant::where('phone_number', $phoneNumber)->first();

        if (!$merchant) {
            return $this->sendError('Phone number not found.', 404);
        }

        // Update the employee's status to 'inactive'
        $merchant->update([
            'phone_number' => 0,
            'edahab_number' => 0,
            'zaad_number' => 0,
            'golis_number' => 0,
            'evc_number' => 0,
        ]);

        $merchant->delete();
        $merchant->user->update([
            'email' => 'deleted'.$merchant->id.'@email.com',
        ]);
        $merchant->user->delete();

        return redirect()->back()->with('success', 'Account Deleted');


    }


}
