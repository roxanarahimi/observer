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
        Schema::create('location_records', function (Blueprint $table) {
            $table->id();
            $table->string('client_uuid');
            $table->bigInteger('device_id');
            $table->bigInteger('user_id');
            $table->double('latitude');
            $table->double('longitude');
            $table->bigInteger('timestamp');
            $table->bigInteger('elapsed_realtime');
            $table->float('accuracy');
            $table->float('speed');
            $table->boolean('is_mock')->default(0);
            $table->string('source');
            $table->date('day');
            $table->dateTime('received_at');
            $table->foreignId('device_id')
                ->constrained('devices')
                ->cascadeOnDelete();
            $table->foreign('user_id', 'fk_lr_user')->references('id')->on('users')->onDelete('cascade');
            $table->index(['user_id', 'day'], 'idx_lr_user_day');$table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_records');
    }
};
