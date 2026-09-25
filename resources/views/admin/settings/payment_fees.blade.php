@extends('admin.layouts.app')

@section('content')
    <!-- Page header -->
    <div class="page-header page-header-light">
        <div class="page-header-content header-elements-md-inline">
            <div class="page-title d-flex">
                <h4><span class="font-weight-semibold">{{ $title }}</span></h4>
            </div>
        </div>
    </div>
    <!-- /page header -->

    <!-- Content area -->
    <div class="content">
        <div class="card">
            <form action="{{ route('admin.settings.payment-fees.update') }}" method="post" id="paymentFeesForm">
                @csrf
                @method('PUT')

                <div class="card-body">
                    <p class="text-muted">
                        What a new merchant pays to sign up, and what a merchant pays to verify their payout wallets.
                        Amounts are in whole {{ $currency }}. The customer pays the base price plus the EXELO fee;
                        the app shows both separately. A change applies to new quotes straight away; payments already
                        requested keep the amount they were quoted.
                    </p>

                    @foreach(['registration' => 'Registration (signup)', 'verification' => 'Wallet verification'] as $purpose => $label)
                        <h5 class="mt-4 mb-2">
                            {{ $label }}
                            @if($fees[$purpose]['is_default'])
                                <span class="badge bg-secondary">Using the default</span>
                            @endif
                        </h5>

                        <div class="row fee-row" data-purpose="{{ $purpose }}">
                            <div class="col-md-3">
                                <label class="col-form-label" for="{{ $purpose }}_base">Base price ({{ $currency }})<span class="text-danger">*</span></label>
                                <input type="number" min="0" step="1" required class="form-control fee-input"
                                       id="{{ $purpose }}_base" name="fees[{{ $purpose }}][base]"
                                       value="{{ old("fees.$purpose.base", $fees[$purpose]['base']) }}">
                                @error("fees.$purpose.base")
                                    <span class="text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="col-md-3">
                                <label class="col-form-label" for="{{ $purpose }}_fee">EXELO fee ({{ $currency }})<span class="text-danger">*</span></label>
                                <input type="number" min="0" step="1" required class="form-control fee-input"
                                       id="{{ $purpose }}_fee" name="fees[{{ $purpose }}][fee]"
                                       value="{{ old("fees.$purpose.fee", $fees[$purpose]['fee']) }}">
                                @error("fees.$purpose.fee")
                                    <span class="text-danger">{{ $message }}</span>
                                @enderror
                            </div>

                            <div class="col-md-3">
                                <label class="col-form-label">Customer pays</label>
                                <p class="form-control-plaintext fw-bold fee-total">
                                    {{ number_format($fees[$purpose]['base'] + $fees[$purpose]['fee']) }} {{ $currency }}
                                </p>
                            </div>
                        </div>
                    @endforeach

                    @can('edit-setting')
                        <div class="mt-4">
                            <button type="submit" class="btn btn-outline-primary">Save fees</button>
                        </div>
                    @endcan
                </div>
            </form>
        </div>
    </div>
    <!-- /content area -->
@endsection

@push('script')
    <script>
        $(function () {
            var currency = @json($currency);

            $('.fee-input').on('input', function () {
                var row = $(this).closest('.fee-row');
                var total = row.find('.fee-input').toArray()
                    .reduce(function (sum, input) { return sum + (parseInt(input.value, 10) || 0); }, 0);

                row.find('.fee-total').text(total.toLocaleString() + ' ' + currency);
            });

            @cannot('edit-setting')
                $('#paymentFeesForm :input').prop('disabled', true);
            @endcannot
        });
    </script>
@endpush
