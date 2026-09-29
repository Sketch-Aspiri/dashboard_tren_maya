{{-- public/logo.png (792x480) trae ~9% de margen transparente por lado; el
     contenido visible mide ~654x318 y está centrado. Se recorta con un
     contenedor de esa proporción y se amplía la imagen 121% (792/654), de
     modo que la altura que se le dé al componente (h-11, h-14…) es la del
     logo real y no la de un lienzo casi vacío. --}}
<span {{ $attributes->merge(['class' => 'relative inline-block aspect-[654/318] shrink-0 overflow-hidden']) }}>
    <img src="{{ asset('logo.png') }}" alt="{{ __('Tren Maya') }}"
         class="absolute left-1/2 top-1/2 w-[121%] max-w-none -translate-x-1/2 -translate-y-1/2">
</span>
