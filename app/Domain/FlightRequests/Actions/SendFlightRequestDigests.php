<?php

namespace App\Domain\FlightRequests\Actions;

use App\Domain\FlightRequests\DataTransferObjects\FlightDigestEntry;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The scheduled entry point — see the app:send-flight-request-digests
 * console command. Loops every company and sends one database notification
 * per user who has outstanding findings, via BuildFlightRequestDigest. A
 * genuine cross-tenant loop, the kind ARCHITECTURE.md's multi-tenancy
 * section calls out as the one legitimate use of iterating every company —
 * CurrentCompany is set explicitly before each company's digest, same
 * convention as every other job.
 */
class SendFlightRequestDigests
{
    public function __construct(private readonly BuildFlightRequestDigest $buildDigest) {}

    public function __invoke(): void
    {
        Company::query()->each(function (Company $company): void {
            app(CurrentCompany::class)->set($company->id);

            foreach (($this->buildDigest)($company) as $userId => $entries) {
                $this->notify((int) $userId, $entries);
            }
        });

        app(CurrentCompany::class)->clear();
    }

    /** @param  Collection<int, FlightDigestEntry>  $entries */
    private function notify(int $userId, Collection $entries): void
    {
        $user = User::query()->find($userId);

        if (! $user) {
            return;
        }

        $count = $entries->count();

        Notification::make()
            ->title($count === 1 ? '1 flight needs your attention' : "{$count} flights need your attention")
            ->body($entries->map(fn (FlightDigestEntry $entry): string => $entry->flightRequest->displayLabel().' — '.implode('; ', $entry->messages))->implode("\n"))
            ->warning()
            ->sendToDatabase($user);
    }
}
