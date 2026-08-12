@php
    use App\Domain\FlightRequests\Actions\CheckFlightReadinessWarning;
    use App\Domain\FlightRequests\Enums\FlightStatus;
    use App\Filament\Resources\FlightRequests\FlightRequestResource;

    // Dragging changes status (see HasFlightRequestBoardView::moveFlightRequest),
    // so only offer it to users who could make that change through the normal
    // edit form anyway — everyone else still gets the board, just not the
    // handle, so cards stay clickable without implying they're draggable.
    $canManageStatus = auth()->user()->can('flights.manage');
@endphp

<div class="-mx-1 overflow-x-auto pb-2">
    <div class="flex items-start gap-4 px-1" style="min-width: max-content;">
        @foreach (FlightStatus::cases() as $status)
            <div
                @if ($canManageStatus)
                    x-sortable
                    x-sortable-group="flight-request-status"
                    x-on:end.stop="
                        if ($event.from.dataset.status !== $event.to.dataset.status) {
                            $wire.moveFlightRequest(Number($event.item.getAttribute('x-sortable-item')), $event.to.dataset.status)
                        }
                    "
                @endif
                data-status="{{ $status->value }}"
                class="flex w-72 shrink-0 flex-col gap-3 rounded-xl bg-gray-50 p-3 dark:bg-white/5"
            >
                <div class="flex items-center justify-between px-1">
                    <x-filament::badge :color="$status->color()">
                        {{ $status->label() }}
                    </x-filament::badge>
                    <span class="text-xs font-medium text-gray-500 dark:text-gray-400">
                        {{ $columns[$status->value]->count() }}
                    </span>
                </div>

                <div class="flex min-h-16 flex-col gap-2">
                    @forelse ($columns[$status->value] as $flightRequest)
                        <a
                            href="{{ FlightRequestResource::getUrl($flightRequest->needsReview() ? 'review' : 'view', ['record' => $flightRequest]) }}"
                            @if ($canManageStatus)
                                x-sortable-item="{{ $flightRequest->id }}"
                                x-sortable-handle
                            @endif
                            class="block rounded-lg bg-white p-3 text-sm shadow-sm ring-1 ring-gray-950/5 hover:ring-primary-600 dark:bg-gray-900 dark:ring-white/10 dark:hover:ring-primary-400 {{ $canManageStatus ? 'cursor-grab' : '' }}"
                        >
                            <div class="flex items-center gap-1 font-medium text-gray-950 dark:text-white">
                                @if (app(CheckFlightReadinessWarning::class)($flightRequest))
                                    <x-heroicon-o-exclamation-triangle
                                        class="h-4 w-4 shrink-0 text-danger-500"
                                        title="Departing soon and not fully ready"
                                    />
                                @endif
                                <span>{{ $flightRequest->displayLabel() }}</span>
                            </div>

                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $flightRequest->customer?->name }}
                            </div>

                            @if ($departure = $flightRequest->earliestDepartureAt())
                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    {{ $departure->format('M j, Y H:i') }}
                                </div>
                            @endif

                            @if ($flightRequest->assignedUsers->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($flightRequest->assignedUsers as $user)
                                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                            {{ $user->name }}
                                        </span>
                                    @endforeach
                                </div>
                            @endif
                        </a>
                    @empty
                        <p class="px-1 text-xs text-gray-400 dark:text-gray-500">No requests</p>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>
</div>
