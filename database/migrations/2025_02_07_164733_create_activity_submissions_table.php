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
        Schema::create('activity_submissions', function (Blueprint $table) {
            $table->id('submissionID');
            $table->unsignedBigInteger('actID');
            $table->unsignedBigInteger('studentID');
            $table->unsignedBigInteger('questionID'); // ✅ Ensure this column exists
            $table->text('codeSubmission'); // Stores the student's code
            $table->integer('score')->nullable(); // Auto-evaluation score
            $table->integer('rank')->nullable(); // Rank based on score
            $table->integer('timeSpent')->nullable(); // ✅ NEW: Time spent on submission
            $table->dateTime('submitted_at')->nullable();

            // Foreign key constraints
            $table->foreign('actID')->references('actID')->on('activities')->onDelete('cascade');
            $table->foreign('studentID')->references('studentID')->on('students')->onDelete('cascade');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_submissions');
    }
};