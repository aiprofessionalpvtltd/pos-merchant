@extends('layouts.app')

@section('content')


    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><i class="icon-arrow-left52 mr-2"></i> <span class="font-weight-semibold">{{$title}}</h4>
                <a href="#" class="header-elements-toggle text-default d-md-none"><i class="icon-more"></i></a>
            </div>

        </div>

        <div class="breadcrumb-line breadcrumb-line-light header-elements-md-inline">
            <div class="d-flex">
                <div class="breadcrumb">
                    <a href="/dashboard" class="breadcrumb-item"><i class="icon-home2 mr-2"></i> Home</a>
                    <span class="breadcrumb-item active">{{$title}}</span>
                </div>

                <a href="#" class="header-elements-toggle text-default d-md-none"><i class="icon-more"></i></a>
            </div>


        </div>
    </div>
    <!-- /page header -->


    <!-- Content area -->
    <div class="content">

        <!-- Inner container -->
        <div class=" align-items-md-start">
            <!-- Profile info -->
            <div class="card">
                <div class="card-header header-elements-inline">
                    <h5 class="card-title">General information</h5>

                </div>

                <div class="card-body">
                    <form class="form-validate-jquery" method="post" enctype="multipart/form-data"
                          action="{{ route('update-settings' , $setting->id) }}">
                        @csrf
                        @method('PUT')
                        <div class="form-group">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label>Company Name <span class="text-danger">*</span></label>
                                    <input required type="text" name="company_name"
                                           value="{{ $setting->company_name}}" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Company Email <span class="text-danger">*</span></label>
                                    <input required type="text" name="company_email"
                                           value="{{ $setting->company_email}}" class="form-control">
                                </div>


                                <div class="col-md-6 mb-3">
                                    <label>Address <span class="text-danger">*</span></label>
                                    <input required type="text" name="address_one"
                                           value="{{ $setting->address_one}}" class="form-control">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label>Invoice Prefix # <span class="text-danger">*</span></label>
                                    <input required type="text" name="invoice_prefix"
                                           value="{{ $setting->invoice_prefix}}" class="form-control">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label>Sub Charges (%)<span class="text-danger">*</span></label>
                                    <input required type="text" name="sub_charges"
                                           value="{{ $setting->sub_charges}}" class="form-control">
                                </div>

                                <div class="col-md-6 mb-3">
                                    <label>Company Logo </label>
                                    <input type="file" class="form-input-styled" name="logo" data-fouc>
                                    <span
                                        class="form-text text-muted">Accepted formats:  png, jpg. Max file size 2Mb</span>

                                    <img class="img-fluid" src="{{showImage($setting->logo,'logo')}}"
                                         width="170" height="170" alt=""></div>

                            </div>
                        </div>
                        @can('update-setting')
                            <div class="text-right">
                                <button type="submit" class="btn btn-primary">Save changes</button>
                            </div>
                        @endcan
                    </form>

                </div>


            </div>

        </div>
        <!-- /inner container -->

    </div>
    <!-- /content area -->



@endsection

@push('script')
    <script src="{{asset('assets/global_assets/js/plugins/forms/validation/validate.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/plugins/forms/inputs/touchspin.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/plugins/forms/selects/select2.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/plugins/forms/styling/switch.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/plugins/forms/styling/switchery.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/plugins/forms/styling/uniform.min.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/demo_pages/form_validation.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/demo_pages/form_select2.js')}}"></script>
    <script src="{{asset('assets/global_assets/js/plugins/visualization/echarts/echarts.min.js')}}"></script>
@endpush
