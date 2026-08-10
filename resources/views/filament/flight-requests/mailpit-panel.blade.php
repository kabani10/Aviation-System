@php
    $mailpitUrl = config('services.mailpit.url');
@endphp

@if ($mailpitUrl)
    <div
        x-data="{ open: false }"
        x-cloak
        class="fixed inset-y-0 right-0 z-[60] flex"
    >
        {{-- Tab handle — always visible, pinned to the right edge, same
             collapse/expand idea as the sidebar but mirrored. --}}
        <button
            x-show="! open"
            @click="open = true"
            type="button"
            class="my-auto flex items-center gap-1.5 rounded-l-lg border border-r-0 border-gray-200 bg-white px-2 py-3 text-xs font-medium text-gray-600 shadow-sm transition hover:bg-gray-50 dark:border-white/10 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-gray-700"
            style="writing-mode: vertical-rl;"
        >
            <x-heroicon-o-envelope class="h-4 w-4 rotate-90" />
            Emails
        </button>

        {{-- Backdrop --}}
        <div
            x-show="open"
            x-transition.opacity
            @click="open = false"
            class="fixed inset-0 bg-gray-950/50"
        ></div>

        {{-- Panel --}}
        <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="relative flex h-full w-screen max-w-2xl flex-col bg-white shadow-xl dark:bg-gray-900"
        >
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3 dark:border-white/10">
                <div>
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Emails (Mailpit)</h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Every email this app has sent, local dev only.</p>
                </div>
                <button
                    @click="open = false"
                    type="button"
                    class="rounded-md p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-gray-800 dark:hover:text-gray-200"
                >
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>

            <iframe
                x-show="open"
                :src="open ? '{{ $mailpitUrl }}' : ''"
                class="h-full w-full flex-1 border-0"
                title="Mailpit"
            ></iframe>
        </div>
    </div>
@endif
