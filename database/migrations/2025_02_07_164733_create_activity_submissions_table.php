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
            // For per-item submission details:
            $table->unsignedBigInteger('itemID');
            // Track attempt number (multiple attempts per student per activity)
            $table->integer('attemptNo')->default(1);
            
            // Store the submitted files (an array of file objects) as a JSON string.
            $table->longText('codeSubmission')->nullable();
            
            // Detailed test case results as a JSON string.
            $table->longText('testCaseResults')->nullable();

            // Optionally, store the remaining time (if relevant) at submission.
            $table->integer('timeRemaining')->nullable();

            // This can store the selected programming language for this submission.
            $table->string('selectedLanguage')->nullable();

            // Store the score for this item.
            $table->float('score')->nullable();
            // Time spent on this specific item, in seconds.
            $table->integer('itemTimeSpent')->nullable();

            $table->integer('timeSpent')->nullable();

            
            // Record the time when the submission was made.
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

        // Pivot table for overall activity progress per student.
        Schema::create('activity_student', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actID');
            $table->unsignedBigInteger('studentID');
            $table->integer('attemptsTaken')->default(0);
            $table->float('finalScore')->nullable();
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