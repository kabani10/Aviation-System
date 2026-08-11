<x-filament-panels::page
    @class([
        'fi-resource-list-records-page',
        'fi-resource-' . str_replace('/', '-', $this->getResource()::getSlug()),
    ])
>
    <div class="flex flex-col gap-y-6">
        @if ($this->displayMode === 'kanban')
            @include('filament.flight-requests.kanban-board', ['columns' => $this->getKanbanColumns()])
        @else
            {{ $this->table }}
        @endif
    </div>
</x-filament-panels::page>
