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
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->string('origin')->nullable();
            $table->decimal('distance', 12, 2)->nullable();
            $table->string('kilometer_category')->default('business');
            $table->boolean('include_return')->default(false);
            $table->decimal('return_distance', 12, 2)->nullable();
        });
        Schema::table('trips', function (Blueprint $table) {
            $table->string('origin')->nullable();
            $table->uuid('pair_id')->nullable()->index();
            $table->string('direction')->nullable();
            $table->unique(['pair_id', 'direction']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropUnique(['pair_id', 'direction']);
            $table->dropIndex(['pair_id']);
            $table->dropColumn(['origin', 'pair_id', 'direction']);
        });
        Schema::table('trip_templates', function (Blueprint $table) {
            $table->dropColumn(['origin', 'distance', 'kilometer_category', 'include_return', 'return_distance']);
        });
    }
};
