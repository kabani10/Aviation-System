@if ($error ?? null)
    <p class="text-sm text-danger-600">{{ $error }}</p>
@elseif ($recommendations->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">No matching suppliers found for this service type — pick one manually below.</p>
@else
    <div class="space-y-3">
        @foreach ($recommendations as $recommendation)
            <div @class([
                'rounded-lg border p-3',
                'border-primary-400 bg-primary-50 dark:border-primary-500 dark:bg-primary-500/10' => $loop->first,
                'border-gray-200 dark:border-gray-700' => ! $loop->first,
            ])>
                <p class="font-medium text-sm text-gray-950 dark:text-white">
                    {{ $loop->iteration }}. {{ $supplierNames[$recommendation->supplierId] ?? "Supplier #{$recommendation->supplierId}" }}
                    @if ($loop->first)
                        <x-filament::badge color="primary" size="sm">Suggested</x-filament::badge>
                    @endif
                </p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $recommendation->rationale }}</p>
            </div>
        @endforeach
    </div>
@endif
