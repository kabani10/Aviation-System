<?php

namespace App\Domain\Shared\Enums;

/**
 * The fixed list of service categories a flight request can need, from the
 * original spec's Service Management section. Lives in Shared rather than
 * under Suppliers or a future Services domain because both need the same
 * vocabulary: a Supplier tags what it offers (this phase), a FlightRequest's
 * services reference the same categories (Phase 6).
 */
enum ServiceType: string
{
    case GroundHandling = 'ground_handling';
    case Fuel = 'fuel';
    case LandingPermit = 'landing_permit';
    case OverflightPermit = 'overflight_permit';
    case AirportSlots = 'airport_slots';
    case Parking = 'parking';
    case Catering = 'catering';
    case Hotel = 'hotel';
    case CrewTransport = 'crew_transport';
    case PassengerTransport = 'passenger_transport';
    case VipHandling = 'vip_handling';
    case Security = 'security';
    case AircraftCleaning = 'aircraft_cleaning';
    case MaintenanceSupport = 'maintenance_support';

    public function label(): string
    {
        return match ($this) {
            self::GroundHandling => 'Ground handling',
            self::Fuel => 'Fuel',
            self::LandingPermit => 'Landing permit',
            self::OverflightPermit => 'Overflight permit',
            self::AirportSlots => 'Airport slots',
            self::Parking => 'Parking',
            self::Catering => 'Catering',
            self::Hotel => 'Hotel',
            self::CrewTransport => 'Crew transport',
            self::PassengerTransport => 'Passenger transport',
            self::VipHandling => 'VIP handling',
            self::Security => 'Security',
            self::AircraftCleaning => 'Aircraft cleaning',
            self::MaintenanceSupport => 'Maintenance support',
        };
    }
}
