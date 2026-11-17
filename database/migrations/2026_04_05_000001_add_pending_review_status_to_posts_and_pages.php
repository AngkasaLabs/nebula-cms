<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE posts MODIFY COLUMN status ENUM('draft', 'published', 'scheduled', 'pending_review') NOT NULL DEFAULT 'draft'");
            DB::statement("ALTER TABLE pages MODIFY COLUMN status ENUM('draft', 'published', 'pending_review') NOT NULL DEFAULT 'draft'");
        } elseif ($driver === 'sqlite') {
            Schema::table('posts', function (Blueprint $table) {
                $table->string('status', 32)->default('draft')->change();
            });
            Schema::table('pages', function (Blueprint $table) {
                $table->string('status', 32)->default('draft')->change();
            });
        } elseif ($driver === 'pgsql') {
            // Laravel compiles enum() on PostgreSQL to an inline CHECK constraint.
            DB::statement('ALTER TABLE posts DROP CONSTRAINT IF EXISTS posts_status_check');
            DB::statement("ALTER TABLE posts ADD CONSTRAINT posts_status_check CHECK (status IN ('draft', 'published', 'scheduled', 'pending_review'))");
            DB::statement('ALTER TABLE pages DROP CONSTRAINT IF EXISTS pages_status_check');
            DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_status_check CHECK (status IN ('draft', 'published', 'pending_review'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('posts')->where('status', 'pending_review')->update(['status' => 'draft']);
        DB::table('pages')->where('status', 'pending_review')->update(['status' => 'draft']);

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE posts MODIFY COLUMN status ENUM('draft', 'published', 'scheduled') NOT NULL DEFAULT 'draft'");
            DB::statement("ALTER TABLE pages MODIFY COLUMN status ENUM('draft', 'published') NOT NULL DEFAULT 'draft'");
        } elseif ($driver === 'sqlite') {
            Schema::table('posts', function (Blueprint $table) {
                $table->enum('status', ['draft', 'published', 'scheduled'])->default('draft')->change();
            });
            Schema::table('pages', function (Blueprint $table) {
                $table->enum('status', ['draft', 'published'])->default('draft')->change();
            });
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE posts DROP CONSTRAINT IF EXISTS posts_status_check');
            DB::statement("ALTER TABLE posts ADD CONSTRAINT posts_status_check CHECK (status IN ('draft', 'published', 'scheduled'))");
            DB::statement('ALTER TABLE pages DROP CONSTRAINT IF EXISTS pages_status_check');
            DB::statement("ALTER TABLE pages ADD CONSTRAINT pages_status_check CHECK (status IN ('draft', 'published'))");
        }
    }
};
