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
        Schema::create('activity_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('studentID');
            $table->unsignedBigInteger('actID');
            $table->timestamps();
            
            // Ensure each student gets notified only once per activity
            $table->unique(['studentID', 'actID']);

            // Foreign key constraints
            $table->foreign('studentID')->references('studentID')->on('students')->onDelete('cascade');
            $table->foreign('actID')->references('actID')->on('activities')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_notifications');
    }
};
