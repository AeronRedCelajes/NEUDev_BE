<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('concerns', function (Blueprint $table) {
            $table->id(); // Auto-increment primary key for the concern record.
            $table->unsignedInteger('classID');
            $table->unsignedBigInteger('studentID');
            $table->text('concern');
            $table->unsignedBigInteger('teacherID');
            $table->text('reply')->nullable();
            $table->timestamps();

            // Foreign key constraints with consistent field names.
            $table->foreign('classID')->references('classID')->on('classes')->onDelete('cascade');
            $table->foreign('studentID')->references('studentID')->on('students')->onDelete('cascade');
            $table->foreign('teacherID')->references('teacherID')->on('teachers')->onDelete('cascade');
        });
    }

    public function down(): void {
        Schema::dropIfExists('concerns');
    }
};