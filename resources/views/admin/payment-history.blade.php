<x-admin-layout>
    <section class="admin-page-heading"><div><h1>Payment history</h1><p>{{ $customer->name }} &middot; {{ $customer->email }}</p></div><a href="{{ route('admin.users.show', $customer->id) }}">User profile</a></section>
    @include('admin.partials.credit-grant')
    @include('admin.partials.payment-table')
    @include('admin.partials.credit-ledger')
</x-admin-layout>
