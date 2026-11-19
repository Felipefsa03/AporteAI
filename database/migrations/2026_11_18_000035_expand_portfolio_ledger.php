<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_events', function (Blueprint $table): void {
            $table->uuid('order_id')->nullable()->after('external_id');
            $table->uuid('reverses_event_id')->nullable()->after('instrument_id');
            $table->decimal('quantity', 20, 6)->nullable()->after('amount_cents');
            $table->bigInteger('unit_price_cents')->nullable()->after('quantity');
            $table->bigInteger('cost_cents')->nullable()->after('unit_price_cents');
            $table->timestampTz('value_date')->nullable()->after('cost_cents');

            $table->foreign('reverses_event_id')
                ->references('id')->on('portfolio_events')->restrictOnDelete();
            $table->index(['portfolio_id', 'order_id'], 'portfolio_events_order_idx');
            $table->index(['portfolio_id', 'reverses_event_id'], 'portfolio_events_reversal_idx');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_events', function (Blueprint $table): void {
            $table->dropIndex('portfolio_events_order_idx');
            $table->dropIndex('portfolio_events_reversal_idx');
            $table->dropConstrainedForeignId('reverses_event_id');
            $table->dropColumn([
                'order_id',
                'quantity',
                'unit_price_cents',
                'cost_cents',
                'value_date',
            ]);
        });
    }
};
