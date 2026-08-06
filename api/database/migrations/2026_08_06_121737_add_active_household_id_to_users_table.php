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
        Schema::table('users', function (Blueprint $table) {
            // Casa em contexto na UI e no bot. Nula só entre o registro e a
            // criação da casa; casa apagada devolve o usuário a esse estado.
            $table->foreignId('active_household_id')
                ->nullable()
                ->after('password')
                ->constrained('households')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_household_id');
        });
    }
};
