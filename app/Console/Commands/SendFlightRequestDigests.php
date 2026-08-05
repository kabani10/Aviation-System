<?php

namespace App\Console\Commands;

use App\Domain\FlightRequests\Actions\SendFlightRequestDigests as SendFlightRequestDigestsAction;
use Illuminate\Console\Command;

/** Scheduled daily — see routes/console.php. Thin wrapper: all the logic lives in the Action, per the "one Action, one job" convention. */
class SendFlightRequestDigests extends Command
{
    protected $signature = 'app:send-flight-request-digests';

    protected $description = 'Send each user a digest notification of their flight requests that need attention';

    public function handle(SendFlightRequestDigestsAction $sendDigests): int
    {
        $sendDigests();

        $this->info('Flight request digests sent.');

        return self::SUCCESS;
    }
}
