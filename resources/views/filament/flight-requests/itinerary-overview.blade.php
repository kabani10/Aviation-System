<x-filament-widgets::widget>
    <div class="space-y-4">
        @foreach ($this->getLegs() as $leg)
            <x-filament::section>
                <x-slot name="heading">
                    Leg {{ $leg->sequence }}: {{ $leg->originAirport->icao_code }} &rarr; {{ $leg->destinationAirport->icao_code }}
                </x-slot>
                <x-slot name="description">
                    Departs {{ $leg->departure_at->toDayDateTimeString() }} &middot; Arrives {{ $leg->arrival_at->toDayDateTimeString() }}
                </x-slot>

                @if ($leg->services->isEmpty())
                    <p class="text-sm text-gray-500 dark:text-gray-400">No services added for this leg yet.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-gray-500 dark:text-gray-400 uppercase">
                                    <th class="pb-2 pr-4 font-medium">Service</th>
                                    <th class="pb-2 pr-4 font-medium">Status</th>
                                    <th class="pb-2 pr-4 font-medium">Supplier</th>
                                    @if ($this->canViewCosts())
                                        <th class="pb-2 pr-4 font-medium">Cost</th>
                                    @endif
                                    @if ($this->canViewPrices())
                                        <th class="pb-2 pr-4 font-medium">Price</th>
                                    @endif
                                    <th class="pb-2 font-medium">Deadline</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($leg->services as $service)
                                    <tr class="border-t border-gray-100 dark:border-white/5">
                                        <td class="py-2 pr-4 text-gray-950 dark:text-white">{{ $service->type->label() }}</td>
                                        <td class="py-2 pr-4">
                                            <x-filament::badge :color="$service->status->color()">
                                                {{ $service->status->label() }}
                                            </x-filament::badge>
                                        </td>
                                        <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">{{ $service->supplier?->name ?? '—' }}</td>
                                        @if ($this->canViewCosts())
                                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                                {{ $service->cost !== null ? '$'.number_format((float) $service->cost, 2) : '—' }}
                                            </td>
                                        @endif
                                        @if ($this->canViewPrices())
                                            <td class="py-2 pr-4 text-gray-500 dark:text-gray-400">
                                                {{ $service->selling_price !== null ? '$'.number_format((float) $service->selling_price, 2) : '—' }}
                                            </td>
                                        @endif
                                        <td class="py-2 {{ $service->isOverdue() ? 'font-medium text-danger-600' : 'text-gray-500 dark:text-gray-400' }}">
                                            {{ $service->deadline?->toDayDateTimeString() ?? '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-widgets::widget>
