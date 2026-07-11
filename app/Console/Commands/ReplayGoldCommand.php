<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Places\Data\Coordinates;
use App\Domain\Recommendations\Services\RankSession;
use App\Domain\Trips\Data\ExploreSessionData;
use App\Domain\Trips\Enums\ExploreSessionStatus;
use App\Domain\Trips\Enums\TravelMode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * The gold-trace regression suite (PRD §15.2): every recorded founder walk
 * replays through the current pipeline; a changed serve is a ranking change
 * you now have to explain. Runs against the dev world model, not CI's empty DB.
 */
final class ReplayGoldCommand extends Command
{
    protected $signature = 'replay:gold {--dir=tests/Fixtures/GoldTraces}';

    protected $description = 'Replay every gold trace and report serve drift';

    public function handle(RankSession $rank): int
    {
        $files = File::exists(base_path((string) $this->option('dir')))
            ? File::files(base_path((string) $this->option('dir')))
            : [];

        if ($files === []) {
            $this->components->warn('No gold traces recorded yet — record one with replay:record after a real walk.');

            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($files as $file) {
            $trace = json_decode($file->getContents(), true);
            $sessionTrace = $trace['session'];

            $data = new ExploreSessionData(
                id: $sessionTrace['id'],
                tripId: $sessionTrace['trip_id'],
                userId: (int) $sessionTrace['user_id'],
                origin: $sessionTrace['origin'] === null ? null : new Coordinates($sessionTrace['origin']['lat'], $sessionTrace['origin']['lng']),
                timeBudgetMinutes: (int) $sessionTrace['time_budget_minutes'],
                travelMode: TravelMode::from($sessionTrace['travel_mode']),
                heading: $sessionTrace['heading'],
                destinationPoint: $sessionTrace['destination_point'] === null ? null : new Coordinates($sessionTrace['destination_point']['lat'], $sessionTrace['destination_point']['lng']),
                status: ExploreSessionStatus::Active,
                startedAt: CarbonImmutable::parse($sessionTrace['started_at']),
                expiresAt: CarbonImmutable::parse($sessionTrace['started_at'])->addDay(),
                endedAt: null,
            );

            $at = $trace['served_at'] !== null ? CarbonImmutable::parse($trace['served_at']) : CarbonImmutable::parse($sessionTrace['started_at']);
            $plan = $rank->plan($data, $at);

            $expected = array_column($trace['served'], 'place_id');
            $actual = array_column($plan['picked'], 'place_id');
            $ok = $expected === $actual;
            $failures += $ok ? 0 : 1;

            $this->components->twoColumnDetail(
                $file->getFilenameWithoutExtension(),
                $ok ? 'serve unchanged' : sprintf('DRIFT — expected [%s], got [%s]',
                    implode(', ', array_column($trace['served'], 'name')),
                    implode(', ', array_column($plan['picked'], 'name')),
                ),
            );
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
