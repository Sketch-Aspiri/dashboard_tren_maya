{{-- Miniatura de una celda de tipo Foto; al abrirla se ve completa. Variables: $seccion, $valor, $nombre (opcional). --}}
@php $archivoFoto = \App\Services\Secciones\FotoService::nombreDe($valor); @endphp
@if ($archivoFoto)
    <a href="{{ route('secciones.fotos.show', [$seccion, $archivoFoto]) }}" target="_blank" rel="noopener" class="inline-block">
        <img src="{{ route('secciones.fotos.show', [$seccion, $archivoFoto]) }}" alt="{{ $nombre ?? __('Foto') }}" loading="lazy"
             class="h-14 w-14 rounded-full border border-gray-200 object-cover">
    </a>
@else
    —
@endif
