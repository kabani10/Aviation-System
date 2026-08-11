<?php

namespace App\Filament\Resources\FlightRequests\FlightRequestResource\RelationManagers;

use App\Domain\Services\Actions\ChooseSupplierInquiry;
use App\Domain\Services\Actions\RecordSupplierInquiryResponse;
use App\Domain\Services\Enums\SupplierInquiryStatus;
use App\Domain\Services\Models\SupplierInquiry;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Every RFQ round-trip across every service on this flight — where the
 * "select multiple suppliers, send inquiries, compare replies" workflow
 * Phase 15 adds actually gets compared and decided. Starting a new inquiry
 * happens on the Services tab instead (see ServicesRelationManager's "Send
 * RFQ" — a per-service action, which is the only place a single service is
 * already in scope to filter AI suggestions by); this tab is the follow-up
 * surface once at least one exists: record what came back, then choose a
 * winner. No CreateAction here for that reason — there's deliberately one
 * entry point for starting an inquiry, not two.
 */
class SupplierInquiriesRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierInquiries';

    protected static ?string $title = 'Supplier Inquiries';

    protected static ?string $recordTitleAttribute = 'id';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'service.flightLeg.originAirport',
                'service.flightLeg.destinationAirport',
                'supplier',
                'supplierContact',
            ]))
            // Grouped by service by default, same reasoning as Phase 14's
            // Services tab grouping — an inquiry only means something in
            // the context of which service it's trying to price.
            ->groups([
                Group::make('service_id')
                    ->label('Service')
                    ->getTitleFromRecordUsing(fn (SupplierInquiry $record): string => $record->service->displayLabel()),
            ])
            ->defaultGroup('service_id')
            ->columns([
                TextColumn::make('service')
                    ->label('Service')
                    ->state(fn (SupplierInquiry $record): string => $record->service->displayLabel()),
                TextColumn::make('supplier.name')->label('Supplier'),
                TextColumn::make('supplierContact.name')->label('Contact')->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SupplierInquiryStatus $state): string => $state->label())
                    ->color(fn (SupplierInquiryStatus $state): string => $state->color()),
                TextColumn::make('cost')
                    ->money('USD')
                    ->placeholder('—')
                    ->visible(fn (): bool => Auth::user()->can('finance.view_costs')),
                TextColumn::make('requested_at')->label('Requested')->dateTime()->placeholder('—'),
                TextColumn::make('responded_at')->label('Responded')->dateTime()->placeholder('—'),
            ])
            ->actions([
                Action::make('recordResponse')
                    ->label('Record response')
                    ->icon('heroicon-o-currency-dollar')
                    ->visible(fn (SupplierInquiry $record): bool => Auth::user()->can('services.manage')
                        && Auth::user()->can('finance.view_costs')
                        && $record->status !== SupplierInquiryStatus::Chosen)
                    ->form([
                        TextInput::make('cost')
                            ->numeric()
                            ->prefix('$')
                            ->required(),
                        Textarea::make('notes')
                            ->rows(2),
                    ])
                    ->action(function (SupplierInquiry $record, array $data): void {
                        app(RecordSupplierInquiryResponse::class)($record, (float) $data['cost'], $data['notes'] ?: null, Auth::user());
                    })
                    ->successNotificationTitle('Response recorded'),

                Action::make('chooseSupplier')
                    ->label('Choose this supplier')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (SupplierInquiry $record): bool => Auth::user()->can('services.manage')
                        && $record->status === SupplierInquiryStatus::QuoteReceived)
                    ->requiresConfirmation()
                    ->modalDescription('Sets this as the service\'s chosen supplier and price. Any other inquiry previously chosen for the same service reverts to "Quote received".')
                    ->action(fn (SupplierInquiry $record) => app(ChooseSupplierInquiry::class)($record))
                    ->successNotificationTitle('Supplier chosen'),
            ]);
    }
}
