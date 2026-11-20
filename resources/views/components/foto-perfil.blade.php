@props(['user' => null, 'tam' => 'h-7 w-7 text-xs'])

{{-- Foto de perfil redonda; sin foto, la inicial del nombre. Decorativa (el nombre va al lado). --}}
@php $url = $user?->urlFoto(); @endphp
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-brand-green font-semibold uppercase text-white '.$tam]) }} aria-hidden="true">
    @if ($url)
        <img src="{{ $url }}" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover">
    @else
        {{ mb_substr((string) ($user?->name ?? '?'), 0, 1) }}
    @endif
</span>
