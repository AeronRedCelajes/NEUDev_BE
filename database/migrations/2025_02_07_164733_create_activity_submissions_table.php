<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('activity_submissions', function (Blueprint $table) {
            $table->id('submissionID');
            $table->unsignedBigInteger('actID');
            $table->unsignedBigInteger('studentID');
            // New column: itemID for per-item submission details.
            $table->unsignedBigInteger('itemID');
            
            // Track the attempt number for multiple attempts per student per item.
            $table->integer('attemptNo')->default(1);
            
            // Store the submitted files as a JSON string.
            // For multiple files, the JSON array might look like:
            // [{"id":0, "fileName": "main", "extension": "py", "content": "print('hello')"}, ...]
            $table->longText('codeSubmission')->nullable();
            
            $table->integer('score')->nullable();
            // timeSpent stored as integer (seconds) for easier calculations
            $table->integer('itemTimeSpent')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->timestamps();

            // Foreign key constraints.
            $table->foreign('actID')
                  ->references('actID')
                  ->on('activities')
                  ->onDelete('cascade');
            $table->foreign('studentID')
                  ->references('studentID')
                  ->on('students')
                  ->onDelete('cascade');
            $table->foreign('itemID')
                  ->references('itemID')
                  ->on('items')
                  ->onDelete('cascade');
        });

        // Pivot table for tracking overall activity progress per student, if needed.
        Schema::create('activity_student', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actID');
            $table->unsignedBigInteger('studentID');
            $table->integer('attemptsTaken')->default(0);
            $table->integer('finalScore')->nullable();
            $table->integer('finalTimeSpent')->nullable();
            $table->integer('rank')->nullable();
            $table->timestamps();

            $table->unique(['actID', 'studentID']);

            $table->foreign('actID')
                  ->references('actID')
                  ->on('activities')
                  ->onDelete('cascade');
            $table->foreign('studentID')
                  ->references('studentID')
                  ->on('students')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_submissions');
        Schema::dropIfExists('activity_student');
    }
};
