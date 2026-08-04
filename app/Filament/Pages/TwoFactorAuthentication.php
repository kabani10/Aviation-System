<?php

namespace App\Filament\Pages;

use App\Domain\Tenancy\Actions\TwoFactor\ConfirmTwoFactorAuthentication;
use App\Domain\Tenancy\Actions\TwoFactor\DisableTwoFactorAuthentication;
use App\Domain\Tenancy\Actions\TwoFactor\EnableTwoFactorAuthentication;
use App\Domain\Tenancy\Actions\TwoFactor\InvalidTwoFactorCodeException;
use App\Domain\Tenancy\Actions\TwoFactor\RegenerateRecoveryCodes;
use App\Domain\Tenancy\Services\TwoFactorAuthenticationService;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class TwoFactorAuthentication extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Two-Factor Authentication';

    protected static bool $shouldRegisterNavigation = false;

    protected static string $view = 'filament.pages.two-factor-authentication';

    public string $pendingSecret = '';

    public string $pendingQrSvg = '';

    public string $code = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public bool $justConfirmed = false;

    public string $currentPassword = '';

    public function startSetup(EnableTwoFactorAuthentication $enable, TwoFactorAuthenticationService $service): void
    {
        $secret = $enable($this->user());

        $this->pendingSecret = $secret;
        $this->pendingQrSvg = $service->qrCodeSvg(
            config('app.name'),
            $this->user()->email,
            $secret,
        );
    }

    public function confirmSetup(ConfirmTwoFactorAuthentication $confirm): void
    {
        try {
            $this->recoveryCodes = $confirm($this->user(), $this->code);
        } catch (InvalidTwoFactorCodeException $e) {
            $this->addError('code', $e->getMessage());

            return;
        }

        $this->justConfirmed = true;
        $this->pendingSecret = '';
        $this->pendingQrSvg = '';
        $this->code = '';
    }

    public function regenerateRecoveryCodes(RegenerateRecoveryCodes $regenerate): void
    {
        $this->recoveryCodes = $regenerate($this->user());
        $this->justConfirmed = true;

        Notification::make()->success()->title('Recovery codes regenerated')->send();
    }

    public function disable(DisableTwoFactorAuthentication $disable): void
    {
        if (! Hash::check($this->currentPassword, $this->user()->password)) {
            $this->addError('currentPassword', 'That password is incorrect.');

            return;
        }

        $disable($this->user());
        $this->currentPassword = '';

        Notification::make()->success()->title('Two-factor authentication disabled')->send();
    }

    private function user(): User
    {
        /** @var User */
        return Auth::user();
    }
}
