<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id('assessmentID');
            $table->unsignedBigInteger('actID');
            $table->unsignedBigInteger('itemID')->nullable();
            $table->unsignedBigInteger('itemTypeID')->nullable();
            
            // Test cases provided by the teacher (e.g. in JSON format)
            $table->text('testCases')->nullable();
            // Extra configuration data stored as JSON
            $table->json('extraData')->nullable();

            $table->timestamps();

            // Foreign key constraints
            $table->foreign('actID')
                  ->references('actID')
                  ->on('activities')
                  ->onDelete('cascade');
            $table->foreign('itemID')
                  ->references('itemID')
                  ->on('items')
                  ->onDelete('cascade');
            $table->foreign('itemTypeID')
                  ->references('itemTypeID')
                  ->on('item_types')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};