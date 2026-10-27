@props(['onBlue' => false])
<span class="brand-logo-image {{ $onBlue ? 'brand-logo-on-blue' : '' }}"><img src="{{ asset($onBlue ? 'logo-header.png' : 'logo.png') }}" alt="ATS CV Detector" width="1584" height="993" decoding="async"></span>
