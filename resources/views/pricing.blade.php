<x-public-layout title="Pricing">
    <section class="reference-pricing" id="pricing" aria-labelledby="pricing-heading">
        <div class="reference-container">
            <div class="reference-section-heading"><div><span class="reference-eyebrow">A PLAN FOR YOUR NEXT STEP</span><h1 class="page-section-heading" id="pricing-heading">Start free.<br><span>Move forward.</span></h1></div><p>Support that fits your ambition.<br>No credit card needed to get started.</p></div>
            <div class="reference-plan-grid">
                @foreach ($plans->where('slug', '!=', 'pro') as $basePlan)
                    <article class="reference-plan" x-data="{ pro: false }">
                        @if($basePlan->slug === 'monthly')<x-plan-pro-switch />@endif
                        @foreach ($basePlan->slug === 'monthly' ? $plans->whereIn('slug', ['monthly', 'pro']) : collect([$basePlan]) as $plan)
                        <div class="plan-variant" @if($basePlan->slug === 'monthly') x-show="{{ $plan->slug === 'pro' ? 'pro' : '!pro' }}" @if($plan->slug === 'pro') x-cloak @endif @endif>
                        <h3>{{ $plan->name }}</h3>
                        <p class="reference-price">${{ $plan->displayPrice() }}<span>{{ $plan->price_cents === 0 ? 'Forever' : ($plan->billing_interval === 'annually' ? '/ month, billed annually' : '/ month') }}</span></p>
                        @if($plan->billing_interval === 'annually')<p class="plan-billing-note">US$47.40 billed yearly.</p>@endif
                        <a class="reference-plan-link" href="{{ $plan->price_cents === 0 ? route('builder.start', ['questions' => 1]) : route('subscription').'#plan-'.$plan->slug }}">{{ $plan->price_cents === 0 ? 'Get Started Free' : 'Choose plan' }} </a>
                        <x-plan-features :plan="$plan" />
                        <small>{{ $plan->price_cents > 0 ? 'Credits added after successful payment.' : '15 welcome credits. No credit card required.' }}</small>
                    </div>
                @endforeach
            </article>
                @endforeach
            </div>
            <p class="reference-release-note">Prices shown in USD. Choose an available payment method at checkout. Paystack's exact charge and currency are shown before payment. Job-to-CV ATS Detector uses 5 credits and a CV revamp uses 10 credits.</p>
        </div>
    </section>
</x-public-layout>
