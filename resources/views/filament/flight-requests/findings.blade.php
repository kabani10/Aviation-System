<div class="space-y-3">
    @forelse ($findings as $finding)
        <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-3">
            <p class="font-medium text-sm text-gray-950 dark:text-white">{{ $finding->message }}</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">{{ $finding->why }}</p>
            @if ($finding->affectedService)
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">Affects: {{ $finding->affectedService }}</p>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ $emptyMessage ?? 'Nothing found.' }}</p>
    @endforelse
</div>
