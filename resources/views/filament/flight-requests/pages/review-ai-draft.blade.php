<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-filament-panels::form
                id="form"
                :wire:key="$this->getId() . '.forms.' . $this->getFormStatePath()"
                wire:submit="save"
            >
                {{ $this->form }}

                <x-filament-panels::form.actions
                    :actions="$this->getCachedFormActions()"
                    :full-width="$this->hasFullWidthFormActions()"
                />
            </x-filament-panels::form>
        </div>

        <div class="lg:col-span-1">
            <x-filament::section>
                <x-slot name="heading">
                    Source email
                </x-slot>

                @php
                    $email = $this->getSourceEmail();
                @endphp

                @if ($email)
                    <div class="space-y-3 text-sm">
                        <div>
                            <p class="font-medium text-gray-950 dark:text-white">{{ $email->subject ?? '(no subject)' }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                From {{ $email->from_address ?? $email->authorName() }} &middot; {{ $email->occurred_at?->toDayDateTimeString() }}
                            </p>
                        </div>

                        <div class="max-h-96 overflow-y-auto whitespace-pre-wrap rounded-lg bg-gray-50 p-3 text-gray-700 dark:bg-white/5 dark:text-gray-300">{{ $email->body }}</div>

                        @if ($email->documents->isNotEmpty())
                            <div>
                                <p class="text-xs font-medium uppercase text-gray-500 dark:text-gray-400">Attachments</p>
                                <ul class="mt-1 space-y-1">
                                    @foreach ($email->documents as $document)
                                        <li class="text-xs text-gray-600 dark:text-gray-400">{{ $document->title }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">No source email found on this flight request.</p>
                @endif
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
