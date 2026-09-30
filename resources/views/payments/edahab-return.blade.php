@extends('layouts.app')

@section('content')
    <div class="row vh-100">
        <div class="col-sm-10 col-md-8 col-lg-6 mx-auto d-table h-100">
            <div class="d-table-cell align-middle">
                <div class="card">
                    <div class="card-body text-center p-5">
                        @if ($status === 'paid')
                            <h1 class="h4">Payment received</h1>
                            <p class="mb-0">{{ number_format((float) $amount) }} {{ $currency }}</p>
                        @elseif ($status === 'pending')
                            <h1 class="h4">Waiting for payment</h1>
                            <p class="mb-0">Return to the EXELO app. It will update when eDahab confirms.</p>
                        @else
                            <h1 class="h4">Payment {{ $status }}</h1>
                            <p class="mb-0">Return to the EXELO app to try again or pick another method.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
