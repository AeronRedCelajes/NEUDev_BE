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
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            // Polymorphic fields for students and teachers.
            $table->unsignedBigInteger('notifiable_id');
            $table->string('notifiable_type');
            
            $table->string('type');
            $table->json('data')->nullable(); // JSON format is ideal for payload data.
            $table->boolean('isRead')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};