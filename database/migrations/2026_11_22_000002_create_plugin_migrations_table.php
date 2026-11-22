<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_migrations', function (Blueprint $table) {
            $table->id();
            $table->string('plugin')->index();
            $table->string('migration');
            $table->unsignedInteger('batch');
            $table->timestamps();

            $table->unique(['plugin', 'migration']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_migrations');
    }
};
