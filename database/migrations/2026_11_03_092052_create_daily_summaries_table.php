<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('daily_summaries', function (Blueprint $table) {
            $table->id();

            $table->string('client_uuid');

            $table->foreignId('device_id')
                ->constrained('devices')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->date('day');

            $table->integer('mock_count')->default(0);
            $table->integer('fake_gps_app_count')->default(0);

            $table->boolean('fake_gps_app_used_today')->default(0);

            $table->integer('impossible_movement_count')->default(0);
            $table->integer('location_jump_count')->default(0);
            $table->integer('total_points')->default(0);

            $table->decimal('fake_percentage', 5, 2)->default(0);

            $table->boolean('is_above_threshold')->default(0);

            $table->bigInteger('computed_at');
            $table->dateTime('received_at');

            $table->unique(['user_id', 'day'], 'uq_user_day');

            $table->index(['is_above_threshold', 'day'], 'idx_ds_threshold');
            $table->index(['fake_gps_app_used_today', 'day'], 'idx_ds_fakegps');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_summaries');
    }
};
