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
            $table->json('draftCheckCodeRuns')->nullable();
            $table->json('draftDeductedScore')->nullable();
            $table->integer('draftTimeRemaining')->nullable();
            $table->string('draftSelectedLanguage')->nullable();
            $table->float('draftScore')->nullable();
            $table->longText('draftItemTimes')->nullable();
            

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