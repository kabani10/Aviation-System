<x-filament-panels::page>
    @php $user = auth()->user(); @endphp

    @if ($justConfirmed)
        <x-filament::section>
            <x-slot name="heading">Save your recovery codes</x-slot>
            <x-slot name="description">
                Each code works once, if you lose access to your authenticator app. Store them somewhere safe — this is the only time they're shown.
            </x-slot>

            <div class="grid grid-cols-2 gap-2 font-mono text-sm">
                @foreach ($recoveryCodes as $recoveryCode)
                    <div class="rounded bg-gray-50 px-3 py-2 dark:bg-gray-800">{{ $recoveryCode }}</div>
                @endforeach
            </div>

            <x-filament::button wire:click="$set('justConfirmed', false)" class="mt-4">
                I've saved these
            </x-filament::button>
        </x-filament::section>
    @elseif ($user->hasEnabledTwoFactorAuthentication())
        <x-filament::section>
            <x-slot name="heading">Two-factor authentication is enabled</x-slot>
            <x-slot name="description">Your account requires a code from your authenticator app to sign in.</x-slot>

            <x-filament::button wire:click="regenerateRecoveryCodes" color="gray">
                Regenerate recovery codes
            </x-filament::button>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Disable two-factor authentication</x-slot>
            <x-slot name="description">This removes the requirement to enter a code at sign-in. Confirm your password to continue.</x-slot>

            <form wire:submit="disable" class="flex items-end gap-3">
                <x-filament::input.wrapper :label="'Current password'" class="flex-1" :error="$errors->first('currentPassword')">
                    <x-filament::input type="password" wire:model="currentPassword" />
                </x-filament::input.wrapper>

                <x-filament::button type="submit" color="danger">
                    Disable
                </x-filament::button>
            </form>
        </x-filament::section>
    @elseif ($pendingSecret)
        <x-filament::section>
            <x-slot name="heading">Scan this with your authenticator app</x-slot>
            <x-slot name="description">Google Authenticator, 1Password, Authy — any TOTP app works.</x-slot>

            <div class="mb-4 w-48">{!! $pendingQrSvg !!}</div>

            <p class="mb-4 text-sm text-gray-500">
                Can't scan it? Enter this key manually:
                <code class="ml-1 rounded bg-gray-50 px-2 py-1 font-mono dark:bg-gray-800">{{ $pendingSecret }}</code>
            </p>

            <form wire:submit="confirmSetup" class="flex items-end gap-3">
                <x-filament::input.wrapper label="Enter the 6-digit code" class="flex-1" :error="$errors->first('code')">
                    <x-filament::input wire:model="code" inputmode="numeric" autocomplete="one-time-code" />
                </x-filament::input.wrapper>

                <x-filament::button type="submit">
                    Confirm
                </x-filament::button>
            </form>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">Two-factor authentication is off</x-slot>
            <x-slot name="description">
                @if ($user->hasRole('Admin'))
                    Admin accounts are required to enable it before using the rest of the panel.
                @else
                    Add an extra layer of security to your account.
                @endif
            </x-slot>

            <x-filament::button wire:click="startSetup">
                Enable two-factor authentication
            </x-filament::button>
        </x-filament::section>
    @endif
</x-filament-panels::page>
