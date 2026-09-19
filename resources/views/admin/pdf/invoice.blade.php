<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $invoice['number'] }}</title>
    <style>
        @page { margin: 28px 34px; }
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #1f2937; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .muted { color: #6b7280; }
        .small { font-size: 10px; }
        .right { text-align: right; }
        .title { font-size: 26px; letter-spacing: 3px; color: #111827; margin: 0; }
        .company-name { font-size: 16px; font-weight: bold; color: #111827; }
        .rule { border-top: 3px solid #1d4ed8; margin: 14px 0 18px; }
        .label { font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #6b7280; margin-bottom: 4px; }
        .paid { display: inline-block; border: 2px solid #15803d; color: #15803d; font-weight: bold; padding: 3px 12px; letter-spacing: 2px; }
        .items { margin-top: 22px; }
        .items th { background: #f3f4f6; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; color: #374151; padding: 8px; border-bottom: 1px solid #d1d5db; }
        .items td { padding: 10px 8px; border-bottom: 1px solid #e5e7eb; }
        .totals { margin-top: 12px; width: 46%; margin-left: 54%; }
        .totals td { padding: 6px 8px; }
        .totals .grand td { border-top: 2px solid #111827; font-size: 13px; font-weight: bold; padding-top: 9px; }
        .footer { margin-top: 34px; border-top: 1px solid #e5e7eb; padding-top: 10px; text-align: center; }
        .toolbar { background: #111827; padding: 10px 16px; text-align: right; }
        .toolbar a { color: #fff; text-decoration: none; background: #1d4ed8; padding: 6px 14px; border-radius: 4px; font-size: 12px; margin-left: 6px; }
        .sheet { max-width: 760px; margin: 0 auto; padding: 24px; }
        @media print { .toolbar { display: none; } .sheet { padding: 0; } }
    </style>
</head>
<body>
@if(! $isPdf)
    <div class="toolbar">
        <a href="{{ $pdfUrl }}">Download PDF</a>
        <a href="#" onclick="window.print(); return false;">Print</a>
    </div>
@endif

<div class="{{ $isPdf ? '' : 'sheet' }}">
    <table>
        <tr>
            <td style="width: 58%;">
                @if($invoice['company']['logo_path'])
                    <img src="{{ $invoice['company']['logo_path'] }}" alt="" style="max-height: 46px; max-width: 180px; margin-bottom: 6px;"><br>
                @endif
                <span class="company-name">{{ $invoice['company']['name'] }}</span>
                <div class="muted small" style="margin-top: 4px; line-height: 1.5;">
                    @if($invoice['company']['address']){{ $invoice['company']['address'] }}<br>@endif
                    @if($invoice['company']['phone']){{ $invoice['company']['phone'] }}<br>@endif
                    @if($invoice['company']['email']){{ $invoice['company']['email'] }}@endif
                    @if($invoice['company']['website']) &middot; {{ $invoice['company']['website'] }}@endif
                </div>
            </td>
            <td class="right">
                <p class="title">INVOICE</p>
                <div style="margin-top: 6px;"><strong>{{ $invoice['number'] }}</strong></div>
                <div class="muted small">Issued {{ $invoice['issued_at'] }}</div>
            </td>
        </tr>
    </table>

    <div class="rule"></div>

    <table>
        <tr>
            <td style="width: 34%;">
                <div class="label">Billed to</div>
                <strong>{{ $invoice['billed_to']['name'] }}</strong>
                <div class="muted" style="line-height: 1.5;">
                    @if($invoice['billed_to']['business'] && $invoice['billed_to']['business'] !== $invoice['billed_to']['name']){{ $invoice['billed_to']['business'] }}<br>@endif
                    @if($invoice['billed_to']['address']){{ $invoice['billed_to']['address'] }}<br>@endif
                    @if($invoice['billed_to']['phone']){{ $invoice['billed_to']['phone'] }}<br>@endif
                    @if($invoice['billed_to']['email']){{ $invoice['billed_to']['email'] }}@endif
                </div>
            </td>
            <td style="width: 38%;">
                <div class="label">Payment</div>
                <div style="line-height: 1.6;">
                    <span class="muted">Method:</span> {{ $invoice['payment']['method'] }}<br>
                    @if($invoice['payment']['paid_at'])<span class="muted">Paid:</span> {{ $invoice['payment']['paid_at'] }}<br>@endif
                    @if($invoice['payment']['reference'])<span class="muted">Reference:</span> {{ $invoice['payment']['reference'] }}<br>@endif
                    @if($invoice['payment']['invoice_ref'])<span class="muted">Invoice ref:</span> {{ $invoice['payment']['invoice_ref'] }}@endif
                </div>
            </td>
            <td class="right">
                <div class="label">Status</div>
                <span class="paid">{{ $invoice['status'] }}</span>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
        <tr>
            <th style="width: 6%;">#</th>
            <th>Description</th>
            <th class="right" style="width: 26%;">Amount</th>
        </tr>
        </thead>
        <tbody>
        @foreach($invoice['items'] as $item)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>
                    {{ $item['description'] }}
                    @if($item['detail'])<div class="muted small">{{ $item['detail'] }}</div>@endif
                </td>
                <td class="right">{{ $item['amount'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">Subtotal</td>
            <td class="right">{{ $invoice['total'] }}</td>
        </tr>
        <tr class="grand">
            <td>Total paid</td>
            <td class="right">{{ $invoice['total'] }}</td>
        </tr>
    </table>

    <div class="footer muted small">
        <div>Thank you for your business.</div>
        <div style="margin-top: 4px;">This invoice was generated automatically on {{ $invoice['generated_at'] }} and is valid without a signature.</div>
    </div>
</div>
</body>
</html>
