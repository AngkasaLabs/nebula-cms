<?php

namespace App\Contracts;

use App\Exceptions\UploadScanException;

interface UploadScanner
{
    /**
     * Scan a file on disk. Throws if malware is detected or if configured to fail on scanner errors.
     *
     * @throws UploadScanException
     */
    public function scanPath(string $absolutePath): void;

    public function isEnabled(): bool;
}
