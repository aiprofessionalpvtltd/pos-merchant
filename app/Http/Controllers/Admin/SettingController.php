<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SettingController extends Controller
{
    public function __construct()
    {
        $this->middleware(['permission:view-setting'])->only(['index', 'show', 'view']);
        $this->middleware(['permission:create-setting']);
        $this->middleware(['permission:edit-setting'])->only(['edit', 'update']);
        $this->middleware(['permission:delete-setting'])->only('destroy');
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
//        if (!isPermission(config('constants.module.setting'), 'view')) {
//            return redirect()->route('404');
//        }
        $settings = DB::table('settings')->first();
        $user = '';

        return view('backend.settings')
            ->with([
                'title' => 'Settings',
                'setting' => $settings,
                'user' => $user,

            ]);
    }


    /**
     * Update the specified resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Setting $setting
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
//        if (!isPermission(config('constants.module.setting'), 'update')) {
//            return redirect()->route('404');
//        }
//        dd($request->all());
         $validator = Validator::make($request->all(), [
            'company_name' => 'required',
            'company_email' => 'required',
            'address_one' => 'required',
            'sub_charges' => 'required',
            'invoice_prefix' => 'required',
        ]);


        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $validatedData = $validator->validated();

        $setting = Setting::find($id);
        $setting->update($validatedData);
//        $setting->company_name = $validatedData['company_name'];
//        $setting->company_email = $validatedData['company_email'];
//        $setting->company_website = $validatedData['company_website'];
//        $setting->address_one = $validatedData['address_one'];
//        $setting->address_two = $validatedData['address_two'];
//        $setting->city = $validatedData['city'];
//        $setting->state = $validatedData['state'];
//        $setting->zipcode = $validatedData['zipcode'];
//        $setting->phone_no = $validatedData['phone_no'];
//        $setting->country = $validatedData['country'];
//        $setting->theme = $validatedData['theme'];
//        $setting->vat_no = $validatedData['vat_no'];
//        $setting->vat = $validatedData['vat'];
//        $setting->vat_type = $validatedData['vat_type'];
//        $setting->invoice_prefix = $validatedData['invoice_prefix'];
//        $setting->invoice_footer = $validatedData['invoice_footer'];
//        $setting->updated_at = currentDateTimeInsert();
//        $setting->save();

        if ($setting->id) {
            if ($request->logo) {
                $filename = uploadImage($request->file('logo'), 'logo');
                $setting->logo = $filename;
                $setting->save();
            }

            if ($request->favicon) {
                $filename = uploadImage($request->file('favicon'), 'favicon');
                $setting->favicon = $filename;
                $setting->save();
            }
            return redirect()->back()->with('success', 'Setting updated Successfully');
        } else {
            return redirect()->back()->with('error', 'Data updated Failed');

        }

    }

    /** ***************************Custom Functions********************************** */


    function validateRules(Request $request)
    {
        $rules = [
            'company_name' => 'required|string|min:3|max:255',
            'company_email' => 'required|string|min:3|max:255',
            'phone_no' => 'required|string|min:3|max:255',
            'address' => 'required|string|min:3|max:255',
        ];
        return Validator::make($request->all(), $rules);
    }
}
