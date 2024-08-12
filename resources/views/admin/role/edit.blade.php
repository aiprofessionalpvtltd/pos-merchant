@extends('admin.layouts.app')

@section('content')
    <!--**********************************
            Content body start
        ***********************************-->

    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold"></span>{{$title}}
                </h4>
                <a href="#" class="header-elements-toggle text-default d-md-none"><i class="icon-more"></i></a>
            </div>


        </div>


    </div>
    <!-- /page header -->

    <!-- Content area -->
    <div class="content">

        <!-- Form validation -->
        <div class="card">

            <!-- Registration form -->
            <form action="{{route('update-role', $role->id)}}" method="post"
                  name="role_registration" class="flex-fill form-validate-jquery">
                @csrf
                @method('PUT')
                <div class="row">
                    <div class="col-lg-12">
                        <div class="card mb-0">
                            <div class="card-body">

                                <div class="row">
                                    <div class="col-md-4">
                                        <label class="col-form-label  ">Role <span
                                                class="text-danger">*</span> </label>
                                        <div
                                            class="form-group form-group-feedback form-group-feedback-right">
                                            <input type="text" required class="form-control"
                                                   name="name"
                                                   value="{{old('name') ?? $role->name}}"
                                                   placeholder=" Name">
                                            <div class="form-control-feedback">
                                                <i class="icon-role-check text-muted"></i>
                                            </div>
                                            @if ($errors->has('name'))
                                                <span
                                                    class="text-danger">{{ $errors->first('name') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    @foreach($permissions as $module)
                                        <div class="col-md-4">
                                            <div class="card mb-3 mt-3">
                                                <div class="card-header text-center bg-primary-dark">
                                                    <h4 class="mb-0 text-white">{{$module['name']}}</h4>
                                                </div>
                                                <div class="card-body">
                                                    @foreach ($module['permissions'] as $permissionID => $permission)
                                                        <div class="form-check form-check-right p-1">
                                                            <input type="checkbox" name="permission[]" class="form-check-input" value="{{$permissionID}}"
                                                                {{ $permission['checked'] ? 'checked' : '' }}>
                                                            <label class="form-check-label">
                                                                {{ $permission['name'] }}
                                                            </label>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach

{{--                                    @can('edit-roles')--}}
                                        <div class="col-md-12 mt-5">
                                            <button type="submit" class="btn btn-outline-primary float-end">
                                                <i class="bi bi-database-fill-check me-2"></i> Update
                                            </button>
                                        </div>
{{--                                    @endcan--}}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
            <!-- /registration form -->
        </div>
        <!-- /form validation -->

    </div>
    <!-- /content area -->
    <!--**********************************
        Content body end
    ***********************************-->

@endsection

@push('script')


@endpush
