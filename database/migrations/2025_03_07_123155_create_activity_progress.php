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
            // Replace studentID with polymorphic columns:
            $table->unsignedBigInteger('progressable_id');
            $table->string('progressable_type'); // e.g., App\Models\Student or App\Models\Teacher
            // Optionally, if you are still tracking progress per item:
            $table->unsignedBigInteger('itemID')->nullable();

            // Instead of a single draft code, store the files (an array of file objects) as a JSON string.
            $table->longText('draftFiles')->nullable();
            $table->json('draftTestCaseResults')->nullable();
            $table->integer('timeRemaining')->nullable(); // in seconds

            $table->timestamps();

            // Unique constraint per activity for each progressable and optionally per item.
            $table->unique(['actID', 'progressable_id', 'progressable_type', 'itemID'], 'ap_unique');

            // Foreign Key for actID (assumes activities table exists)
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