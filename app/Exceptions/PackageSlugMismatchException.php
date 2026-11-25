<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Raised when the slug declared by a signed package does not match the slug
 * found inside the archive that is being installed.
 */
class PackageSlugMismatchException extends InvalidArgumentException
{
    //
}
