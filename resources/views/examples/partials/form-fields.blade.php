@php
    /** @var \App\Models\Example|null $example */
@endphp

<div>
    <x-input-label for="name" :value="__('Nombre')" />
    <x-text-input id="name" name="name" type="text" class="block mt-1 w-full"
                  :value="old('name', $example?->name)" required autofocus />
    <x-input-error :messages="$errors->get('name')" class="mt-2" />
</div>

<div>
    <x-input-label for="value" :value="__('Valor')" />
    <x-text-input id="value" name="value" type="text" class="block mt-1 w-full"
                  :value="old('value', $example?->value)" />
    <x-input-error :messages="$errors->get('value')" class="mt-2" />
</div>

<div>
    <x-input-label for="status" :value="__('Estado')" />
    <select id="status" name="status" class="block mt-1 w-full border-gray-300 rounded-md shadow-sm">
        @foreach ($statuses as $status)
            <option value="{{ $status->value }}" @selected(old('status', $example?->status?->value) === $status->value)>
                {{ $status->label() }}
            </option>
        @endforeach
    </select>
    <x-input-error :messages="$errors->get('status')" class="mt-2" />
</div>
