<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Create the Activities Table
        Schema::create('activities', function (Blueprint $table) {
            $table->id('actID');
            $table->unsignedInteger('classID');  // Foreign key to classes
            $table->unsignedBigInteger('teacherID'); // Foreign key to teachers
            
            $table->string('actTitle');
            $table->text('actDesc');
            $table->enum('actDifficulty', ['Beginner', 'Intermediate', 'Advanced']);
            $table->string('actDuration', 8)->nullable();
            $table->integer('actAttempts');
            $table->dateTime('openDate');
            $table->dateTime('closeDate');
            $table->float('maxPoints');
            $table->float('classAvgScore')->nullable();
            $table->enum('finalScorePolicy', ['last_attempt', 'highest_score'])
            ->default('last_attempt');
            $table->boolean('examMode')->default(false);
            $table->boolean('randomizedItems')->default(false);
            
            // Enable or disable check code deductions globally for the activity
            $table->boolean('checkCodeRestriction')->default(false);
            // Maximum allowed check code runs per item (e.g., 3 runs)
            $table->integer('maxCheckCodeRuns')->nullable();
            // Deduction percentage applied per extra run after the first (e.g., 10 for 10%)
            $table->float('checkCodeDeduction')->nullable();

            $table->timestamp('completed_at')->nullable();

            // Foreign Key Constraints
            $table->foreign('classID')
                  ->references('classID')
                  ->on('classes')
                  ->onDelete('cascade');

            $table->foreign('teacherID')
                  ->references('teacherID')
                  ->on('teachers')
                  ->onDelete('cascade');

            $table->timestamps();
        });

        // Create the Activity Items Table (renamed from "activity_questions")
        Schema::create('activity_items', function (Blueprint $table) {
            $table->id('id');
            $table->unsignedBigInteger('actID');
            $table->unsignedBigInteger('itemID');     // was "questionID"
            $table->unsignedBigInteger('itemTypeID'); // same reference as items
            $table->float('actItemPoints');

            // Foreign Key Constraints
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

        // Create the Activity Programming Languages Table (Pivot Table)
        Schema::create('activity_programming_languages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actID');
            $table->unsignedBigInteger('progLangID');

            // Foreign Key Constraints
            $table->foreign('actID')
                  ->references('actID')
                  ->on('activities')
                  ->onDelete('cascade');

            $table->foreign('progLangID')
                  ->references('progLangID')
                  ->on('programming_languages')
                  ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop in correct order
        Schema::dropIfExists('activity_programming_languages');
        Schema::dropIfExists('activity_items');
        Schema::dropIfExists('activities');
    }
};