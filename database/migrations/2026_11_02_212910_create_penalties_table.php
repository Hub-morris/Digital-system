<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penalties', function (Blueprint $table): void {
            $table->id();
            $table->string('penalty_reference', 40)->unique();
            $table->foreignId('booking_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('flight_id')->constrained()->restrictOnDelete();
            $table->foreignId('penalty_rule_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('penalty_amount', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->string('penalty_reason', 40)->default('No-Show');
            $table->string('status', 20)->default('Applied');
            $table->text('adjustment_reason')->nullable();
            $table->foreignId('adjusted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('adjusted_at')->nullable();
            $table->timestamps();
            $table->index(['created_at', 'status']);
            $table->index(['flight_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penalties');
    }
};
