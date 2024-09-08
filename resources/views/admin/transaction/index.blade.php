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

                </div>
            </div>

            <div class="card-body">
                <table id="" class="table table-striped datatables-reponsive">
                <thead>
                <tr>
                    <th>Merchant Name</th>
                    <th>Amount</th>
                    <th>Mobile No</th>
                    <th>Message</th>
                    <th>Transaction ID</th>
                    <th>Status</th>
                 </tr>
                </thead>
                <tbody>
                @foreach($transactions as $transaction)
                    <tr>
                        <td>{{$transaction->merchant->first_name . ' ' . $transaction->merchant->last_name .
 ' (' . $transaction->merchant->business_name  .')' }}</td>
                        <td>{{$transaction->transaction_amount}}</td>
                        <td>{{$transaction->phone_number}}</td>
                        <td>{{$transaction->transaction_message}}</td>
                        <td>{{$transaction->transaction_id}} </td>
                         <td>{{$transaction->transaction_status}} </td>


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
