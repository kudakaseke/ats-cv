@props(['onBlue' => false])
@php($logoFilterId = 'brand-logo-'.\Illuminate\Support\Str::uuid())
<span {{ $attributes->class(['brand-logo-image', 'brand-logo-on-blue' => $onBlue]) }}>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1584 993" width="1584" height="993" role="img" aria-label="ATS CV Detector">
        <defs>
            {{-- Separate the original black and blue ink from its white paper.
                 The small alpha cutoff removes the paper texture as well. --}}
            <filter id="{{ $logoFilterId }}" x="0" y="0" width="100%" height="100%" color-interpolation-filters="sRGB">
                <feColorMatrix in="SourceGraphic" type="matrix" values="0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 -1 0 1" result="blackInk" />
                <feComponentTransfer in="blackInk" result="blackMark">
                    <feFuncA type="linear" slope="1.052632" intercept="-0.052632" />
                </feComponentTransfer>
                <feColorMatrix in="SourceGraphic" type="matrix" values="0 0 0 0 {{ $onBlue ? '1' : '0' }}  0 0 0 0 {{ $onBlue ? '1' : '0.439216' }}  0 0 0 0 1  -1 0 1 0 0" result="cvInk" />
                <feComponentTransfer in="cvInk" result="cvMark">
                    <feFuncA type="linear" slope="1.052632" intercept="-0.052632" />
                </feComponentTransfer>
                <feMerge><feMergeNode in="blackMark" /><feMergeNode in="cvMark" /></feMerge>
            </filter>
        </defs>
        <image href="{{ asset('logo.png') }}" width="1584" height="993" filter="url(#{{ $logoFilterId }})" />
    </svg>
</span>
