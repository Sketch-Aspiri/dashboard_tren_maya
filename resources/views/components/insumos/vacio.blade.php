@props(['titulo', 'columnas' => null])

{{-- Estado vacío de una tabla o lista: qué falta y, en el slot, qué hacer. --}}
@if ($columnas)
    <tr>
        <td colspan="{{ $columnas }}" class="!px-4 !py-10 text-center">
            <p class="font-medium text-gray-700">{{ $titulo }}</p>
            @if (trim($slot) !== '')<p class="mx-auto mt-1 max-w-md text-sm text-gray-500">{{ $slot }}</p>@endif
        </td>
    </tr>
@else
    <div {{ $attributes->merge(['class' => 'ins-card px-4 py-10 text-center']) }}>
        <p class="font-medium text-gray-700">{{ $titulo }}</p>
        @if (trim($slot) !== '')<p class="mx-auto mt-1 max-w-md text-sm text-gray-500">{{ $slot }}</p>@endif
    </div>
@endif
