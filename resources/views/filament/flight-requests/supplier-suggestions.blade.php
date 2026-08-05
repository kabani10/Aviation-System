@if ($error ?? null)
    <p class="text-sm text-danger-600">{{ $error }}</p>
@elseif ($recommendations->isEmpty())
    <p class="text-sm text-gray-500 dark:text-gray-400">No matching suppliers found for this service type.</p>
@else
    <div class="space-y-3">
        @foreach ($recommendations as $recommendation)
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                <p class="font-medium text-sm text-gray-950 dark:text-white">
                    {{ $loop->iteration }}. {{ $supplierNames[$recommendation->supplierId] ?? "Supplier #{$recommendation->supplierId}" }}
                </p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $recommendation->rationale }}</p>
            </div>
        @endforeach
    </div>
@endif
