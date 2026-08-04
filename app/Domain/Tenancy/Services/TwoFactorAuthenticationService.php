<?php

namespace App\Domain\Tenancy\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Thin wrapper around pragmarx/google2fa + bacon/bacon-qr-code. Nothing here
 * touches the database — see the Actions in this namespace for the
 * stateful operations (enable, confirm, disable).
 */
class TwoFactorAuthenticationService
{
    public function __construct(private readonly Google2FA $engine) {}

    public function generateSecretKey(): string
    {
        return $this->engine->generateSecretKey();
    }

    /**
     * Inline SVG, no external network call — the QR image is rendered
     * server-side and embedded directly in the response.
     */
    public function qrCodeSvg(string $companyName, string $email, string $secret): string
    {
        $url = $this->engine->getQRCodeUrl($companyName, $email, $secret);

        $renderer = new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code);
    }

    /**
     * @return list<string>
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, 8))
            ->map(fn () => Str::random(10).'-'.Str::random(10))
            ->all();
    }
}
