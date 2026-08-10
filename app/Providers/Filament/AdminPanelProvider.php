<?php

namespace App\Providers\Filament;

use App\Filament\Pages\TwoFactorAuthentication;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\EditFlightRequest;
use App\Filament\Resources\FlightRequests\FlightRequestResource\Pages\ViewFlightRequest;
use App\Http\Middleware\EnsureTwoFactorChallengeCompleted;
use App\Http\Middleware\RequireTwoFactorForAdmins;
use App\Http\Middleware\SetCurrentCompany;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Vite;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            // Bell icon in the topbar, reading from the notifications table —
            // what SendFlightRequestDigests writes to (see ARCHITECTURE.md's
            // "Notifications & reminders"). Polling, not broadcast: no
            // websocket infrastructure exists yet, and a daily digest doesn't
            // need real-time delivery.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                Widgets\FilamentInfoWidget::class,
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Two-Factor Authentication')
                    ->icon('heroicon-o-shield-check')
                    ->url(fn () => TwoFactorAuthentication::getUrl()),
            ])
            // Filament panels ship their own prebuilt CSS bundle and never
            // scan our custom Blade partials (widgets, render-hook views) for
            // Tailwind classes — so utilities used only there (e.g. the
            // Mailpit panel's positioning classes below) were never actually
            // being generated. This loads a second, tiny utilities-only
            // stylesheet (no preflight, so it can't clash with Filament's own
            // base styles) scoped to resources/views/filament. See
            // ARCHITECTURE.md's "Flight legs" section.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => app(Vite::class)('resources/css/filament-extras.css')->toHtml(),
            )
            // Slide-out Mailpit panel on the flight request page — see
            // ARCHITECTURE.md's "Flight Requests" section. Scoped to these
            // two pages specifically, not global: it needs Mailpit (local
            // dev only, config('services.mailpit.url') is null everywhere
            // else) and only makes sense next to the itinerary you're
            // actually verifying mail against.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.flight-requests.mailpit-panel')->render(),
                scopes: [ViewFlightRequest::class, EditFlightRequest::class],
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                SetCurrentCompany::class,
                EnsureTwoFactorChallengeCompleted::class,
                RequireTwoFactorForAdmins::class,
            ]);
    }
}
