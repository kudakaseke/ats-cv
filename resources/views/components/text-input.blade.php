@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-slate-200 focus:border-blue-500 focus:ring-blue-500 rounded-lg shadow-sm']) }}>
