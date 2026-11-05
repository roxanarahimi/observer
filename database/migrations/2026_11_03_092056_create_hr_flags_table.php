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
        Schema::create('hr_flags', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('period_month', 7);

            $table->integer('flagged_days_count');

            $table->integer('threshold_days')->default(10);

            $table->decimal('threshold_percentage', 5, 2)->default(50.00);

            $table->string('status', 20)->default('pending');

            $table->unique(['user_id', 'period_month'], 'uq_user_month');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_flags');
    }
};
