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
        Schema::create('location_status_events', function (Blueprint $table) {
            $table->id();

            $table->string('client_uuid')->unique();

            $table->foreignId('device_id')
                ->constrained('devices')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->enum('event_type', [
                'LOCATION_DISABLED',
                'LOCATION_ENABLED',
                'LOCATION_PERMISSION_DENIED_AT_SETUP',
                'LOCATION_PERMISSION_REVOKED',
                'BACKGROUND_LOCATION_PERMISSION_REVOKED',
                'LOCATION_PERMISSION_STATE_CHANGED',
                'LOCATION_PERMISSION_RESTORED',
            ])->default('LOCATION_ENABLED');

            $table->enum('category', [
                'LOCATION_SETTING',
                'FOREGROUND_LOCATION',
                'BACKGROUND_LOCATION',
            ]);

            $table->enum('previous_state', [
                'ENABLED',
                'DISABLED',
                'PRECISE',
                'APPROXIMATE',
                'DENIED',
                'GRANTED',
                'NOT_APPLICABLE',
            ]);

            $table->enum('current_state', [
                'ENABLED',
                'DISABLED',
                'PRECISE',
                'APPROXIMATE',
                'DENIED',
                'GRANTED',
                'NOT_APPLICABLE',
            ]);

            $table->bigInteger('detected_at');
            $table->string('detected_at_utc');
            $table->bigInteger('previous_observed_at');

            $table->enum('detection_source', [
                'MODE_CHANGED_BROADCAST',
                'SERVICE_START',
                'LOCATION_UPDATE',
                'LOCATION_AVAILABILITY',
                'MANUAL_CAPTURE',
                'APP_RESUME',
                'PERIODIC_JOB',
                'BOOT',
                'SETUP',
            ]);

            $table->date('day');
            $table->dateTime('received_at');

            $table->index(['user_id', 'day'], 'idx_lse_user_day');
            $table->index('event_type', 'idx_lse_type');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_status_events');
    }
};
