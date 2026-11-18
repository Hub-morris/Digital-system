<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penalty_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('cabin_class', 20)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('penalty_type', 20)->default('percentage');
            $table->decimal('penalty_value', 10, 2)->default(30);
            $table->unsignedSmallInteger('cancellation_deadline_hours')->default(3);
            $table->timestamps();
        });

        foreach (['Economy', 'Business', 'First Class'] as $cabinClass) {
            DB::table('penalty_rules')->insert([
                'cabin_class' => $cabinClass,
                'enabled' => false,
                'penalty_type' => 'percentage',
                'penalty_value' => 30,
                'cancellation_deadline_hours' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('penalty_rules');
    }
};
