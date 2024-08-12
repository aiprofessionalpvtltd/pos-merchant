<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\District;
use App\Models\Province;
use Illuminate\Http\Request;

class AjaxController extends Controller
{

    public function getProvinceByCountryAjax(Request $request): \Illuminate\Http\JsonResponse
    {
        $responseData = Province::select('id','name')->where('country_id', '=', $request->countryID)->orderBy('name', 'ASC')->get();
         return response()->json($responseData);
    }

    public function getDistrictByProvinceAjax(Request $request): \Illuminate\Http\JsonResponse
    {
        $responseData = District::select('id','name')->where('province_id', '=', $request->provinceID)->orderBy('name', 'ASC')->get();
        return response()->json( $responseData);
    }

    public function getCityByProvinceAjax(Request $request): \Illuminate\Http\JsonResponse
    {
        $responseData = City::select('id','name')->where('province_id', '=', $request->provinceID)->orderBy('name', 'ASC')->get();
        return response()->json( $responseData);
    }


}
