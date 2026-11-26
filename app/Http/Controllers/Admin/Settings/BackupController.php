<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Services\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage settings');
    }

    public function index(BackupService $backups)
    {
        return Inertia::render('Admin/Settings/Backups', [
            'backups' => collect($backups->listBackups())
                ->map(fn (array $backup) => [
                    'filename' => $backup['filename'],
                    'size' => $backup['size'],
                    'created_at' => $backup['created_at'],
                    'type' => $backup['type'],
                    'app_version' => $backup['app_version'],
                    'db_driver' => $backup['db_driver'],
                ])
                ->values()
                ->all(),
            'retention' => max(1, (int) config('nebula.backup_retention', 5)),
            'scheduled' => (bool) config('nebula.scheduled_backups', true),
        ]);
    }

    public function create(BackupService $backups)
    {
        try {
            $backup = $backups->create('manual');
        } catch (\Throwable $e) {
            Log::error('Manual backup failed.', ['error' => $e->getMessage()]);

            return back()->with('error', 'Backup gagal dibuat: '.$e->getMessage());
        }

        $this->log('backup_created', ['filename' => $backup['filename']]);

        return back()->with('success', 'Backup dibuat: '.$backup['filename']);
    }

    public function download(BackupService $backups, string $filename): BinaryFileResponse
    {
        $backup = $this->find($backups, $filename);

        abort_unless($backup !== null, 404);

        return response()->download($backup['path'], $backup['filename'], [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function destroy(BackupService $backups, string $filename)
    {
        $backup = $this->find($backups, $filename);

        abort_unless($backup !== null, 404);

        if (! $backups->delete($backup['path'])) {
            return back()->with('error', 'Backup tidak bisa dihapus.');
        }

        $this->log('backup_deleted', ['filename' => $backup['filename']]);

        return back()->with('success', 'Backup dihapus: '.$backup['filename']);
    }

    public function restore(Request $request, BackupService $backups, string $filename)
    {
        $request->validate([
            'confirmation' => ['required', 'string', 'in:RESTORE'],
        ]);

        $backup = $this->find($backups, $filename);

        abort_unless($backup !== null, 404);

        $result = $backups->restore($backup['path']);

        if (! $result['success']) {
            return back()->with('error', $result['message']);
        }

        $this->log('backup_restored', ['filename' => $backup['filename']]);

        return back()->with('success', $result['message']);
    }

    /**
     * @return array{filename: string, path: string}|null
     */
    private function find(BackupService $backups, string $filename): ?array
    {
        foreach ($backups->listBackups() as $backup) {
            if ($backup['filename'] === $filename) {
                return $backup;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(string $event, array $properties): void
    {
        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->withProperties($properties)
                ->log($event);
        }
    }
}
