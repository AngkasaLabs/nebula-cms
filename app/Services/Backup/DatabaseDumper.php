<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Dumps and restores the application database for full-site backups.
 *
 * Client binaries (`mysqldump`, `mysql`, `pg_dump`, `psql`) are preferred
 * because they stream large databases without loading them into PHP memory.
 * Credentials are passed through environment variables, never as arguments.
 */
class DatabaseDumper
{
    private const TIMEOUT = 1800;

    /**
     * @return array{file: string, driver: string}
     */
    public function dump(string $directory): array
    {
        $driver = DB::connection()->getDriverName();

        File::ensureDirectoryExists($directory);

        return match ($driver) {
            'mysql', 'mariadb' => $this->dumpMysql($directory),
            'pgsql' => $this->dumpPgsql($directory),
            'sqlite' => $this->dumpSqlite($directory),
            default => throw new RuntimeException("Database driver [{$driver}] is not supported for backups."),
        };
    }

    public function restore(string $file, string $driver): void
    {
        if (! is_file($file)) {
            throw new RuntimeException('The backup does not contain a database dump.');
        }

        match ($driver) {
            'mysql', 'mariadb' => $this->restoreMysql($file),
            'pgsql' => $this->restorePgsql($file),
            'sqlite' => $this->restoreSqlite($file),
            default => throw new RuntimeException("Database driver [{$driver}] is not supported for restores."),
        };

        DB::purge();
    }

    /**
     * @return array{file: string, driver: string}
     */
    private function dumpMysql(string $directory): array
    {
        $config = config('database.connections.'.config('database.default'));
        $binary = (string) config('nebula.mysqldump_path', 'mysqldump');
        $file = $directory.'/database-backup.sql';
        $database = (string) ($config['database'] ?? '');

        if ($database === '' || $database === ':memory:') {
            throw new RuntimeException('No MySQL database is configured for the backup.');
        }

        $this->ensureBinary($binary);

        $process = new Process([
            $binary,
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 3306),
            '--user='.(string) ($config['username'] ?? ''),
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            '--default-character-set=utf8mb4',
            '--result-file='.$file,
            $database,
        ], null, $this->mysqlEnv((string) ($config['password'] ?? '')), null, self::TIMEOUT);

        $process->run();

        if (! $process->isSuccessful() || ! is_file($file)) {
            throw new RuntimeException('mysqldump failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return ['file' => 'database-backup.sql', 'driver' => 'mysql'];
    }

    /**
     * @return array{file: string, driver: string}
     */
    private function dumpPgsql(string $directory): array
    {
        $config = config('database.connections.'.config('database.default'));
        $binary = (string) config('nebula.pg_dump_path', 'pg_dump');
        $file = $directory.'/database-backup.sql';

        $this->ensureBinary($binary);

        $process = new Process([
            $binary,
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 5432),
            '--username='.(string) ($config['username'] ?? ''),
            '--no-owner',
            '--no-privileges',
            '--file='.$file,
            (string) ($config['database'] ?? ''),
        ], null, $this->pgsqlEnv((string) ($config['password'] ?? '')), null, self::TIMEOUT);

        $process->run();

        if (! $process->isSuccessful() || ! is_file($file)) {
            throw new RuntimeException('pg_dump failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        return ['file' => 'database-backup.sql', 'driver' => 'pgsql'];
    }

    /**
     * @return array{file: string, driver: string}
     */
    private function dumpSqlite(string $directory): array
    {
        $path = (string) config('database.connections.sqlite.database');

        if ($path === '' || $path === ':memory:' || ! is_file($path)) {
            throw new RuntimeException('SQLite backups need a database file (in-memory databases cannot be dumped).');
        }

        $file = $directory.'/database-backup.sqlite';
        File::copy($path, $file);

        return ['file' => 'database-backup.sqlite', 'driver' => 'sqlite'];
    }

    private function restoreMysql(string $file): void
    {
        $config = config('database.connections.'.config('database.default'));
        $binary = (string) config('nebula.mysql_path', 'mysql');

        $this->ensureBinary($binary);

        $process = new Process([
            $binary,
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 3306),
            '--user='.(string) ($config['username'] ?? ''),
            '--default-character-set=utf8mb4',
            (string) ($config['database'] ?? ''),
        ], null, $this->mysqlEnv((string) ($config['password'] ?? '')), null, self::TIMEOUT);

        $process->setInput(fopen($file, 'rb'));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysql restore failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    private function restorePgsql(string $file): void
    {
        $config = config('database.connections.'.config('database.default'));
        $binary = (string) config('nebula.psql_path', 'psql');

        $this->ensureBinary($binary);

        $process = new Process([
            $binary,
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.(string) ($config['port'] ?? 5432),
            '--username='.(string) ($config['username'] ?? ''),
            '--dbname='.(string) ($config['database'] ?? ''),
            '--file='.$file,
        ], null, $this->pgsqlEnv((string) ($config['password'] ?? '')), null, self::TIMEOUT);

        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('psql restore failed: '.trim($process->getErrorOutput() ?: $process->getOutput()));
        }
    }

    private function restoreSqlite(string $file): void
    {
        $path = (string) config('database.connections.sqlite.database');

        if ($path === '' || $path === ':memory:') {
            throw new RuntimeException('SQLite restores need a database file.');
        }

        File::copy($file, $path);
    }

    /**
     * @return array<string, string>
     */
    private function mysqlEnv(string $password): array
    {
        return $password === '' ? [] : ['MYSQL_PWD' => $password];
    }

    /**
     * @return array<string, string>
     */
    private function pgsqlEnv(string $password): array
    {
        return $password === '' ? [] : ['PGPASSWORD' => $password];
    }

    private function ensureBinary(string $binary): void
    {
        $process = new Process([$binary, '--version'], null, null, null, 10);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("Required database tool [{$binary}] was not found on this server.");
        }
    }
}
