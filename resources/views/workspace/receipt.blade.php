<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Receipt {{ $receipt->number }}</title>
<style>body{font:16px system-ui;max-width:760px;margin:40px auto;padding:24px;color:#172b3a}dt{font-weight:600;margin-top:16px}dd{margin:4px 0}h1{font-size:28px}</style></head>
<body><h1>{{ $details['business'] }}</h1><p>{{ $details['address'] }}</p><h2>Payment receipt {{ $receipt->number }}</h2><p>This receipt is not a tax invoice.</p>
<dl><dt>Customer</dt><dd>{{ $details['customer'] }} ({{ $details['email'] }})</dd>
<dt>Transaction reference</dt><dd>{{ $details['reference'] }}</dd><dt>Paynow reference</dt><dd>{{ $details['paynow_reference'] }}</dd>
<dt>Purchase</dt><dd>{{ $details['plan'] }}</dd><dt>Amount</dt><dd>{{ $details['currency'] }} {{ number_format($details['amount_cents'] / 100, 2) }}</dd>
<dt>Paid on</dt><dd>{{ $details['paid_at'] }}</dd><dt>Current payment status</dt><dd>{{ ucfirst($payment->status) }} ({{ $payment->provider_status }})</dd>
@if($details['starts_at'])<dt>Billing period</dt><dd>{{ $details['starts_at'] }} to {{ $details['ends_at'] }}</dd>@endif</dl>
</body></html>
