<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's stock notifications migration types `data` as text, but
 * Filament's notification bell filters on data->>'format' — an operator
 * Postgres only supports on json/jsonb. Raw SQL avoids a doctrine/dbal
 * dependency just for this one column-type change. Only Postgres needs
 * this — the test suite runs on SQLite, which has no such ALTER syntax
 * and doesn't distinguish text from json anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text');
        }
    }
};
