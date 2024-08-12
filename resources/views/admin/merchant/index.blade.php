@extends('admin.layouts.app')

@section('content')


    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold">{{$title}}</span>
                </h4>
                <a href="#" class="header-elements-toggle text-default d-md-none"><i class="icon-more"></i></a>

            </div>


        </div>

    </div>
    <!-- /page header -->


    <!-- Content area -->
    <div class="content">

        <!-- Basic datatable -->
        <div class="card">
            <div class="card-header header-elements-inline">
                <h5 class="card-title"></h5>
                <div class="header-elements">
                    @can('create-merchants')
                        <div class="col-md-12 mt-5">

                            <a href="{{route('add-merchant')}}"
                               class="btn btn-outline-primary float-end"><b><i
                                        class="fas fa-plus"></i></b> {{$title}}
                            </a>
                        </div>
                    @endcan
                </div>
            </div>

            <div class="card-body">
                <table id="" class="table table-striped datatables-reponsive">
                <thead>
                <tr>
                    <th>ID</th>
                    <th>Merchant Name</th>
                    <th>Phone No</th>
                    <th>Address</th>
                    <th>Approval Status</th>
                    <th>Confirmation Status</th>
                      <th class="text-center">Actions</th>
                </tr>
                </thead>
                <tbody>
                @foreach($merchants as $merchant)
                    <tr>
                        <td>{{$merchant->merchant_id}}</td>
                        <td>{{$merchant->name}}</td>
                         <td>{{$merchant->phone_number}}</td>
                        <td>{{$merchant->city}} , {{$merchant->country}} ,  {{$merchant->address}} </td>
                        <td>{{showBoolean($merchant->is_approved)}}</td>
                        <td>{{showBoolean($merchant->confirmation_status)}}</td>
                        <td>`
                            <div class="d-flex">
                                @can('edit-merchant')
                                    <a title="Edit" href="{{ route('edit-merchant', $merchant->id) }}"
                                       class="badge bg-primary m-1"><i
                                            class="fas fa-fw fa-edit"></i></a>
                                @endcan

                                @can('delete-merchant')
                                    <a href="javascript:void(0)" data-url="{{route('changeStatus-merchant')}}"
                                       data-status='0' data-label="delete"
                                       data-id="{{$merchant->id}}"
                                       class="badge bg-danger m-1 change-status-record"
                                       title="Suspend Record"><i class="fas fa-trash"></i></a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
        <!-- /basic datatable -->

    </div>
    <!-- /content area -->
@endsection

@push('script')
    <script src="{{asset('backend/js/datatables.js')}}"></script>

@endpush
