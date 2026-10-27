@props(['plan'])
@php
    $features = $plan->slug === 'free'
        ? ['15 welcome credits', 'CV analysis and AI rewriting', 'Selected CV templates', 'One saved CV', 'PDF and Word downloads']
        : [number_format(config('ai_credits.plans.'.$plan->slug)).($plan->slug === 'annual' ? ' credits upfront per year' : ' credits each month'), 'CV analysis and AI rewriting', 'All CV templates', 'Unlimited saved CVs', 'PDF and Word downloads'];
@endphp
<ul>@foreach($features as $feature)<li><x-icon name="check" /> {{ $feature }}</li>@endforeach</ul>
