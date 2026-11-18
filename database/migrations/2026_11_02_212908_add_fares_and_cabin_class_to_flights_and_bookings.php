<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('flights', function (Blueprint $table): void {
            $table->decimal('economy_fare', 12, 2)->default(0);
            $table->decimal('business_fare', 12, 2)->default(0);
            $table->decimal('first_class_fare', 12, 2)->default(0);
        });

        Schema::table('bookings', function (Blueprint $table): void {
            $table->string('cabin_class', 20)->default('Economy');
            $table->decimal('fare_amount', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table): void {
            $table->dropColumn(['cabin_class', 'fare_amount']);
        });

        Schema::table('flights', function (Blueprint $table): void {
            $table->dropColumn(['economy_fare', 'business_fare', 'first_class_fare']);
        });
    }
};
