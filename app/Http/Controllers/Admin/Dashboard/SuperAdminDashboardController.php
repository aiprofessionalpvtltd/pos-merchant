<?php

namespace App\Http\Controllers\Admin\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Allotee;
use App\Models\Bill;
use App\Models\Charge;
use App\Models\Sector;
use App\Models\Size;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class SuperAdminDashboardController extends Controller
{

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index()
    {

        $conversionRate = env('CONVERSION_RATE', 10500); // Default value if not set in .env

        return view('home', compact('conversionRate'));
    }

    public function updateConversionRate(Request  $request)
    {
        $request->validate([
            'conversion_rate' => 'required|numeric',
        ]);

        $this->updateEnv(['CONVERSION_RATE' => $request->conversion_rate]);

        // Optionally clear the config cache to apply changes immediately
        Artisan::call('config:clear');

        return redirect()->back()->with('success', 'Conversion rate updated successfully.');
    }

    protected function updateEnv(array $data)
    {
        $envPath = base_path('.env');
        if (file_exists($envPath)) {
            foreach ($data as $key => $value) {
                $escapedValue = preg_quote('='.$value, '/');
                file_put_contents(
                    $envPath,
                    preg_replace(
                        "/^{$key}=.*/m",
                        "{$key}={$value}",
                        file_get_contents($envPath)
                    )
                );
            }
        }
    }


}
