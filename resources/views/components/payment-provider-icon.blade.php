@props(['provider'])
<span class="payment-provider-icon payment-provider-{{ $provider }}" aria-hidden="true">
    <svg viewBox="0 0 40 40" fill="currentColor" focusable="false">
        @if($provider === 'paystack')
            <rect x="7" y="6" width="27" height="5" rx="1"/><rect x="7" y="14" width="27" height="5" rx="1"/><rect x="7" y="22" width="27" height="5" rx="1"/><rect x="7" y="30" width="17" height="5" rx="1"/>
        @elseif($provider === 'paypal')
            <path opacity=".45" d="M16 10h11c12 0 10 20-2 20h-5l-2 8h-8z"/><path d="M11 3h12c14 0 12 21-1 21h-6l-2 10H5zm7 7-2 8h6c5 0 6-8 1-8z"/>
        @else
            <path d="M5 10h21l-5-5 4-4 12 12-12 12-4-4 5-5H5zm30 20H14l5 5-4 4L3 27l12-12 4 4-5 5h21z"/>
        @endif
    </svg>
</span>
