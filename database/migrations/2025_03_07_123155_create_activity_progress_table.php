<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('activity_progress', function (Blueprint $table) {
            $table->id('progressID');
            $table->unsignedBigInteger('actID');
            $table->unsignedBigInteger('progressable_id');
            $table->string('progressable_type');
            // Removed the itemID column because progress is now unified per activity:
            // $table->unsignedBigInteger('itemID')->nullable();

            $table->longText('draftFiles')->nullable();
            $table->json('draftTestCaseResults')->nullable();
            $table->integer('timeRemaining')->nullable();
            $table->string('selected_language')->nullable();
            $table->integer('draftScore')->nullable();

            $table->timestamps();

            // Unique record per activity for each user.
            $table->unique(['actID', 'progressable_id', 'progressable_type'], 'ap_unique');

            $table->foreign('actID')
                  ->references('actID')
                  ->on('activities')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_progress');
    }
};