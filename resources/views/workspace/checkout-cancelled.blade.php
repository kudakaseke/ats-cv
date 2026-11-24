<x-app-layout>
    <div class="dashboard-heading"><div><h1>Payment cancelled</h1><p>You returned from the Paynow cancellation page.</p></div></div>
    <section class="panel workspace-settings-panel">
        <p>This return page does not confirm a payment or add credits. If you were charged, contact support with your Paynow reference before trying again.</p>
        <a class="btn btn-outline" href="{{ route('subscription') }}">Back to plans</a>
    </section>
</x-app-layout>
