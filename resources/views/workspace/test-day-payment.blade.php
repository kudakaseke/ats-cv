<x-app-layout>
    <div class="dashboard-heading"><div><h1>One-day test plan</h1><p>US$0.10 for one day and 15 AI credits.</p></div><a class="text-link" href="{{ route('subscription') }}">Back to plans</a></div>
    <article class="panel payment-method-card">
        <div class="payment-method-heading"><x-payment-provider-icon provider="paynow" /><h2 class="sr-only">Paynow</h2></div>
        <p>For testing only. This is a one-time payment with no automatic renewal.</p>
        <p>This button may take a real payment. It does not automatically add credits or activate access.</p>
        <a href="https://www.paynow.co.zw/Payment/BillPaymentLink/?q=aWQ9MjgzNDkmYW1vdW50PTAuMTAmYW1vdW50X3F1YW50aXR5PTAuMDAmbD0x" target="_blank" rel="noopener noreferrer"><img src="https://www.paynow.co.zw/Content/Buttons/Medium_buttons/button_pay-now_medium.png" alt="Pay US$0.10 with Paynow" style="border:0"></a>
        <p>After payment, contact support with your Paynow payment reference for manual verification and activation. If no payment is made, no credits are added.</p>
    </article>
</x-app-layout>
