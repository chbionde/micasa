<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopping_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shopping_list_id')->constrained()->cascadeOnDelete();
            // Texto livre: sem catálogo de produtos (ADR-010). Só o nome é
            // obrigatório — o resto é opcional para não cansar quem digita.
            $table->string('name');
            $table->decimal('quantity', 10, 3)->nullable();
            $table->string('unit', 20)->nullable();
            // Dinheiro sempre em centavos, nunca float (ADR-015).
            $table->integer('estimated_price_cents')->nullable();
            $table->string('priority')->nullable();
            $table->string('store')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Ordem manual dentro da lista: no mercado, a ordem importa.
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['shopping_list_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopping_list_items');
    }
};
