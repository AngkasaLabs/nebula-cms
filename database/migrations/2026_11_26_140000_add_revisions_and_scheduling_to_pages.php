<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('status');
        });

        Schema::create('page_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('title');
            $table->longText('content');
            $table->string('featured_image')->nullable();
            $table->string('meta_description')->nullable();
            $table->string('meta_keywords')->nullable();
            $table->unsignedInteger('revision_number');
            $table->timestamps();

            $table->index(['page_id', 'revision_number']);
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE pages MODIFY COLUMN status ENUM('draft', 'published', 'scheduled', 'pending_review') NOT NULL DEFAULT 'draft'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE pages DROP CONSTRAINT IF EXISTS pages_status_check');
            DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_status_check CHECK (status IN ('draft', 'published', 'scheduled', 'pending_review'))");
        }
    }

    public function down(): void
    {
        DB::table('pages')->where('status', 'scheduled')->update([
            'status' => 'draft',
            'published_at' => null,
        ]);

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE pages MODIFY COLUMN status ENUM('draft', 'published', 'pending_review') NOT NULL DEFAULT 'draft'");
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE pages DROP CONSTRAINT IF EXISTS pages_status_check');
            DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_status_check CHECK (status IN ('draft', 'published', 'pending_review'))");
        }

        Schema::dropIfExists('page_revisions');

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
