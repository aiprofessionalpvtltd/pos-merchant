<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleAndPermissionController extends Controller
{

    public function __construct()
    {
        $this->middleware(['permission:View Permissions'])->only(['index','show','view','getRolePermissions']);
        $this->middleware(['permission:Create Permissions'])->only(['assignRevokePermission']);
        $this->middleware(['permission:Edit Permissions'])->only(['edit','update','assignRevokePermission']);
        $this->middleware(['permission:Delete Permissions'])->only('destroy');
    }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (auth()->user()->hasRole('Admin')) {
            $roles = Role::all()->except(8);
        }
        else{
            $roles = Role::all()->whereNotIn(['1,8']);
        }
        $permissions = Permission::all();
        return view('roles-and-permissions.index',compact('roles','permissions'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('roles-and-permissions.index');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function store(RoleRequest $request)
    {
        $request->validated();

        Role::create(['name' => $request->input('name')]);
        $roles = Role::all()->except(1);
        $permissions = Permission::all();
        return view('roles-and-permissions.index',compact('roles','permissions'));

    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function show(Role $role)
    {
        $permissions = $role->permissions->toArray();
        return ["status" => true, "data" => $permissions];
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param Role $role
     * @return \Illuminate\Http\Response
     */
    public function edit(Role $role)
    {
        $permissions = $role->permissions->toArray();
        return ["status" => true, "data" => $permissions];
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @param Role $role
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Role $role)
    {
        if ($request->input('type') == "Assign") {
            $role->givePermissionTo($request->input('permissions'));
        } else {
             $role->revokePermissionTo($request->input('permissions'));
        }

        return ["status" => true, "message" => "Success"];
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
