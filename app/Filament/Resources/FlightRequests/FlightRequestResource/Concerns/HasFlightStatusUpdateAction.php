<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\Concerns;

use App\Domain\FlightRequests\Actions\SendFlightStatusUpdate;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * "Throughout the process, the user can send the client a flight status" —
 * kept as its own trait, not folded into HasFlightRequestReviewActions or
 * HasFlightExecutionActions, since it doesn't check anything or move
 * FlightStatus forward the way those do; it's a point-in-time customer
 * communication, callable regardless of where the flight is in its
 * lifecycle.
 */
trait HasFlightStatusUpdateAction
{
    /** @return array<int, Action> */
    protected function flightStatusUpdateHeaderActions(): array
    {
        return [
            Action::make('sendStatusUpdate')
                ->label('Send status update')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => Auth::user()->can('flights.manage'))
                ->form([
                    Textarea::make('message')
                        ->label('Additional message (optional)')
                        ->rows(3),
                ])
                ->action(function (array $data): void {
                    try {
                        app(SendFlightStatusUpdate::class)($this->getRecord(), $data['message'] ?: null, Auth::user());
                    } catch (RuntimeException $exception) {
                        Notification::make()->title('Could not send status update')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Status update sent')->success()->send();
                }),
        ];
    }
}
