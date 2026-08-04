<?php

namespace App\Domain\Communications\Enums;

enum CommunicationType: string
{
    case EmailIn = 'email_in';
    case EmailOut = 'email_out';
    case Note = 'note';
    case CallSummary = 'call_summary';
    case WhatsApp = 'whatsapp';
    case SystemEvent = 'system_event';

    public function label(): string
    {
        return match ($this) {
            self::EmailIn => 'Email received',
            self::EmailOut => 'Email sent',
            self::Note => 'Note',
            self::CallSummary => 'Call summary',
            self::WhatsApp => 'WhatsApp',
            self::SystemEvent => 'System event',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::EmailIn => 'heroicon-o-inbox-arrow-down',
            self::EmailOut => 'heroicon-o-paper-airplane',
            self::Note => 'heroicon-o-pencil-square',
            self::CallSummary => 'heroicon-o-phone',
            self::WhatsApp => 'heroicon-o-chat-bubble-left-right',
            self::SystemEvent => 'heroicon-o-cog-6-tooth',
        };
    }
}
