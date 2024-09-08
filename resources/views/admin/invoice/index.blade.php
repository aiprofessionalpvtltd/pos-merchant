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
                    <th>ID</th>
                    <th>Phone No</th>
                    <th>Transaction ID</th>
                    <th>Amount</th>
                    <th>Currency</th>
                    <th>Status</th>
                 </tr>
                </thead>
                <tbody>
                @foreach($invoices as $invoice)
                    <tr>
                        <td>{{$invoice->invoice_id}}</td>
                        <td>{{$invoice->mobile_number}}</td>
                        <td>{{$invoice->transaction_id}}</td>
                        <td>{{$invoice->amount}} </td>
                        <td>{{$invoice->currency}} </td>
                        <td>{{$invoice->status}} </td>


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
