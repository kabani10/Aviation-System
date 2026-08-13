<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Global reference data — not tenant-scoped, shared by every company. Bulk-
 * loaded from database/data/{countries,airports}.csv, a filtered snapshot of
 * the public-domain OurAirports dataset (all countries; airports of type
 * large/medium/small with a real 4-letter ICAO code — heliports, seaplane
 * bases, balloonports, and closed airports are excluded as out of scope for
 * fixed-wing business aviation). See ARCHITECTURE.md's "Reference data"
 * section for why this is seeder-only and has no Filament CRUD in the
 * tenant panel, and why it's re-imported wholesale here rather than
 * maintained as a hand-picked list — see database/data/README.md for how to
 * refresh the source files.
 *
 * Uses raw bulk inserts, not Eloquent::create() per row — at ~10k airport
 * rows, per-row model instantiation/events would make this seeder (and by
 * extension every test that seeds reference data) noticeably slower for no
 * benefit, since neither model has observers or casts worth paying for here.
 */
class ReferenceDataSeeder extends Seeder
{
    private const CHUNK_SIZE = 1000;

    public function run(): void
    {
        $now = now();

        $countries = LazyCollection::make(function () {
            yield from $this->readCsv(database_path('data/countries.csv'));
        })->map(fn (array $row) => [
            'code' => $row['code'],
            'name' => $row['name'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($countries->chunk(self::CHUNK_SIZE) as $chunk) {
            DB::table('countries')->upsert($chunk->all(), ['code'], ['name', 'updated_at']);
        }

        $countryIds = DB::table('countries')->pluck('id', 'code');

        $airports = LazyCollection::make(function () use ($countryIds, $now) {
            foreach ($this->readCsv(database_path('data/airports.csv')) as $row) {
                $countryId = $countryIds[$row['country_code']] ?? null;

                if ($countryId === null) {
                    continue;
                }

                yield [
                    'icao_code' => $row['icao_code'],
                    'iata_code' => $row['iata_code'] !== '' ? $row['iata_code'] : null,
                    'name' => $row['name'],
                    'city' => $row['city'],
                    'country_id' => $countryId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        });

        foreach ($airports->chunk(self::CHUNK_SIZE) as $chunk) {
            DB::table('airports')->upsert(
                $chunk->all(),
                ['icao_code'],
                ['iata_code', 'name', 'city', 'country_id', 'updated_at'],
            );
        }
    }

    /** @return iterable<int, array<string, string>> */
    private function readCsv(string $path): iterable
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            yield array_combine($header, $row);
        }

        fclose($handle);
    }
}
