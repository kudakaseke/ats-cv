    <section class="admin-panel admin-data-panel">
        <header class="admin-panel-heading"><div><h2>Credits</h2>
            <p>Stored balance: {{ number_format(max(0, ($wallet->subscription_credits ?? 0) + ($wallet->topup_credits ?? 0) - ($wallet->credit_debt ?? 0))) }} &middot; Debt: {{ number_format($wallet->credit_debt ?? 0) }}</p>
            <p>Review the ledger and payment status first. Pending payments can be checked in <a href="{{ route('admin.billing') }}">payment reconciliation</a>. Credits may already have been used. Reconciliation or a later payment confirmation may also add credits.</p>
        </div></header>
        <form method="POST" action="{{ route('admin.users.credits', $customer->id) }}" class="admin-credit-grant" onsubmit="return confirm('Add these credits to this user?')">
            @csrf
            @if(request()->routeIs('admin.users.show'))<input type="hidden" name="return_to_profile" value="1">@endif
            <input type="hidden" name="request_id" value="{{ old('request_id', (string) \Illuminate\Support\Str::uuid()) }}">
            <label>Credits to add <input name="credits" type="number" min="1" max="100000" value="{{ old('credits') }}" required></label>
            <label>Reason <input name="reason" type="text" minlength="10" maxlength="500" value="{{ old('reason') }}" required></label>
            <label>Payment reference (optional) <input name="payment_reference" value="{{ old('payment_reference') }}"></label>
            <button class="admin-primary-button" type="submit">Add credits</button>
        </form>
        <p class="admin-updated">These credits do not expire. Any existing debt reduces the available balance.</p>
    </section>
