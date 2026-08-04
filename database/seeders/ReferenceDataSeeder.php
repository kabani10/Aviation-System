<?php

namespace Database\Seeders;

use App\Domain\ReferenceData\Models\Airport;
use App\Domain\ReferenceData\Models\Country;
use Illuminate\Database\Seeder;

/**
 * Global reference data — not tenant-scoped, shared by every company. A
 * starting set of major business-aviation hubs, not an exhaustive import;
 * expand this list as real usage needs airports it's missing. See
 * ARCHITECTURE.md's "Reference data" section for why this is seeder-only
 * and has no Filament CRUD in the tenant panel.
 */
class ReferenceDataSeeder extends Seeder
{
    private const COUNTRIES = [
        ['code' => 'US', 'name' => 'United States'],
        ['code' => 'GB', 'name' => 'United Kingdom'],
        ['code' => 'FR', 'name' => 'France'],
        ['code' => 'DE', 'name' => 'Germany'],
        ['code' => 'CH', 'name' => 'Switzerland'],
        ['code' => 'IT', 'name' => 'Italy'],
        ['code' => 'ES', 'name' => 'Spain'],
        ['code' => 'NL', 'name' => 'Netherlands'],
        ['code' => 'IE', 'name' => 'Ireland'],
        ['code' => 'AE', 'name' => 'United Arab Emirates'],
        ['code' => 'QA', 'name' => 'Qatar'],
        ['code' => 'SA', 'name' => 'Saudi Arabia'],
        ['code' => 'EG', 'name' => 'Egypt'],
        ['code' => 'TR', 'name' => 'Turkey'],
        ['code' => 'ZA', 'name' => 'South Africa'],
        ['code' => 'SG', 'name' => 'Singapore'],
        ['code' => 'JP', 'name' => 'Japan'],
        ['code' => 'CN', 'name' => 'China'],
        ['code' => 'HK', 'name' => 'Hong Kong'],
        ['code' => 'IN', 'name' => 'India'],
        ['code' => 'AU', 'name' => 'Australia'],
        ['code' => 'CA', 'name' => 'Canada'],
        ['code' => 'BR', 'name' => 'Brazil'],
    ];

    /** [icao, iata, name, city, country_code] */
    private const AIRPORTS = [
        ['KJFK', 'JFK', 'John F Kennedy International', 'New York', 'US'],
        ['KTEB', 'TEB', 'Teterboro', 'Teterboro', 'US'],
        ['KVNY', 'VNY', 'Van Nuys', 'Los Angeles', 'US'],
        ['KLAX', 'LAX', 'Los Angeles International', 'Los Angeles', 'US'],
        ['KMIA', 'MIA', 'Miami International', 'Miami', 'US'],
        ['KORD', 'ORD', "Chicago O'Hare International", 'Chicago', 'US'],
        ['KIAD', 'IAD', 'Washington Dulles International', 'Washington', 'US'],
        ['KBOS', 'BOS', 'Boston Logan International', 'Boston', 'US'],
        ['KDAL', 'DAL', 'Dallas Love Field', 'Dallas', 'US'],
        ['KSFO', 'SFO', 'San Francisco International', 'San Francisco', 'US'],
        ['EGLL', 'LHR', 'London Heathrow', 'London', 'GB'],
        ['EGGW', 'LTN', 'London Luton', 'London', 'GB'],
        ['EGKB', 'BQH', 'London Biggin Hill', 'London', 'GB'],
        ['LFPG', 'CDG', 'Paris Charles de Gaulle', 'Paris', 'FR'],
        ['LFPB', 'LBG', 'Paris Le Bourget', 'Paris', 'FR'],
        ['EDDF', 'FRA', 'Frankfurt am Main', 'Frankfurt', 'DE'],
        ['EDDM', 'MUC', 'Munich', 'Munich', 'DE'],
        ['LSGG', 'GVA', 'Geneva', 'Geneva', 'CH'],
        ['LSZH', 'ZRH', 'Zurich', 'Zurich', 'CH'],
        ['LIRF', 'FCO', 'Rome Fiumicino', 'Rome', 'IT'],
        ['LEMD', 'MAD', 'Madrid Barajas', 'Madrid', 'ES'],
        ['EHAM', 'AMS', 'Amsterdam Schiphol', 'Amsterdam', 'NL'],
        ['EIDW', 'DUB', 'Dublin', 'Dublin', 'IE'],
        ['OMDB', 'DXB', 'Dubai International', 'Dubai', 'AE'],
        ['OMDW', 'DWC', 'Dubai World Central', 'Dubai', 'AE'],
        ['OMAA', 'AUH', 'Abu Dhabi International', 'Abu Dhabi', 'AE'],
        ['OTHH', 'DOH', 'Hamad International', 'Doha', 'QA'],
        ['OEJN', 'JED', 'King Abdulaziz International', 'Jeddah', 'SA'],
        ['HECA', 'CAI', 'Cairo International', 'Cairo', 'EG'],
        ['LTFM', 'IST', 'Istanbul Airport', 'Istanbul', 'TR'],
        ['FAJS', 'JNB', 'OR Tambo International', 'Johannesburg', 'ZA'],
        ['WSSS', 'SIN', 'Singapore Changi', 'Singapore', 'SG'],
        ['RJTT', 'HND', 'Tokyo Haneda', 'Tokyo', 'JP'],
        ['ZBAA', 'PEK', 'Beijing Capital International', 'Beijing', 'CN'],
        ['VHHH', 'HKG', 'Hong Kong International', 'Hong Kong', 'HK'],
        ['VABB', 'BOM', 'Chhatrapati Shivaji Maharaj International', 'Mumbai', 'IN'],
        ['YSSY', 'SYD', 'Sydney Kingsford Smith', 'Sydney', 'AU'],
        ['CYYZ', 'YYZ', 'Toronto Pearson International', 'Toronto', 'CA'],
        ['SBGR', 'GRU', 'São Paulo/Guarulhos International', 'São Paulo', 'BR'],
    ];

    public function run(): void
    {
        foreach (self::COUNTRIES as $country) {
            Country::query()->firstOrCreate(['code' => $country['code']], $country);
        }

        $countryIds = Country::query()->pluck('id', 'code');

        foreach (self::AIRPORTS as [$icao, $iata, $name, $city, $countryCode]) {
            Airport::query()->firstOrCreate(
                ['icao_code' => $icao],
                [
                    'iata_code' => $iata,
                    'name' => $name,
                    'city' => $city,
                    'country_id' => $countryIds[$countryCode],
                ],
            );
        }
    }
}
