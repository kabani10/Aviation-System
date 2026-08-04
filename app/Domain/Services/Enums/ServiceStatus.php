<?php

namespace App\Domain\Services\Enums;

/** The lifecycle from the original spec's Step 11, same source as FlightStatus. */
enum ServiceStatus: string
{
    case NotStarted = 'not_started';
    case InformationRequired = 'information_required';
    case SupplierRequestSent = 'supplier_request_sent';
    case QuotationReceived = 'quotation_received';
    case WaitingCustomerApproval = 'waiting_customer_approval';
    case Confirmed = 'confirmed';
    case AtRisk = 'at_risk';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InformationRequired => 'Information required',
            self::SupplierRequestSent => 'Supplier request sent',
            self::QuotationReceived => 'Quotation received',
            self::WaitingCustomerApproval => 'Waiting for customer approval',
            self::Confirmed => 'Confirmed',
            self::AtRisk => 'At risk',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotStarted, self::InformationRequired => 'gray',
            self::SupplierRequestSent, self::QuotationReceived, self::WaitingCustomerApproval => 'warning',
            self::Confirmed, self::Completed => 'success',
            self::AtRisk => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
