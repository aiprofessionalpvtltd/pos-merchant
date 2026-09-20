<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\EmployeeDirectoryService;
use Illuminate\Http\Request;
use Yajra\DataTables\DataTables;

class EmployeeController extends Controller
{
    public function __construct(private readonly EmployeeDirectoryService $employees)
    {
        $this->middleware('auth');
        $this->middleware(['permission:view-employee'])->only(['index', 'show']);
    }

    /**
     * Every employee of every shop, with shifts and sales.
     */
    public function index(Request $request)
    {
        if ($request->ajax()) {
            return DataTables::of($this->employees->listQuery())
                ->filter(fn ($query) => $this->employees->filterStatus($query, $request->input('status')), true)
                ->addColumn('merchant', fn (Employee $employee) => $this->employees->listRow($employee)['merchant'])
                ->filterColumn('merchant', fn ($query, $keyword) => $query->whereHas('merchant', fn ($merchant) => $merchant
                    ->where('business_name', 'like', "%{$keyword}%")
                    ->orWhere('first_name', 'like', "%{$keyword}%")
                    ->orWhere('last_name', 'like', "%{$keyword}%")))
                ->orderColumn('merchant', 'employees.merchant_id $1')
                ->addColumn('name', fn (Employee $employee) => $this->employees->listRow($employee)['name'])
                ->filterColumn('name', fn ($query, $keyword) => $query->whereRaw("CONCAT(employees.first_name, ' ', employees.last_name) LIKE ?", ["%{$keyword}%"]))
                ->orderColumn('name', 'employees.first_name $1')
                ->addColumn('salary_display', fn (Employee $employee) => $this->employees->listRow($employee)['salary'])
                ->orderColumn('salary_display', 'employees.salary $1')
                ->addColumn('status_label', fn (Employee $employee) => $this->employees->listRow($employee)['status']['label'])
                ->addColumn('pin', fn (Employee $employee) => $this->employees->listRow($employee)['pin'])
                ->addColumn('permission_keys', fn (Employee $employee) => $this->employees->listRow($employee)['permissions'])
                ->orderColumn('shifts', 'shifts_count $1')
                ->addColumn('shifts', fn (Employee $employee) => $this->employees->listRow($employee)['shifts'])
                ->addColumn('last_shift', fn (Employee $employee) => $this->employees->listRow($employee)['last_shift'])
                ->orderColumn('last_shift', 'last_shift_at $1')
                ->addColumn('sales', fn (Employee $employee) => $this->employees->listRow($employee)['sales'])
                ->orderColumn('sales', 'orders_total $1')
                ->editColumn('created_at', fn (Employee $employee) => $employee->created_at?->format('d M Y H:i'))
                ->addColumn('action', fn (Employee $employee) => '<a class="btn btn-sm btn-outline-primary" href="'.route('admin.employees.view', $employee->id).'">View</a>')
                // Only the button is HTML; names and phone numbers are user input and stay escaped.
                ->rawColumns(['action'])
                ->make(true);
        }

        return view('admin.employee.index', ['title' => 'Employees', 'statuses' => EmployeeDirectoryService::STATUSES]);
    }

    public function show(int $id)
    {
        $employee = Employee::with(['merchant', 'user', 'permissions.permission'])->findOrFail($id);

        return view('admin.employee.view', ['title' => 'Employee', 'employee' => $employee, 'detail' => $this->employees->detail($employee)]);
    }
}
