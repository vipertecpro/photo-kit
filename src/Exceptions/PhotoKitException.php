<?php

namespace Vipertecpro\PhotoKit\Exceptions;

use RuntimeException;

/**
 * Thrown when the native side reports an error for a synchronous call
 * (process / exif) — an undecodable file, an unsupported output format on
 * this platform, or a write failure. `$errorCode` carries the bridge code
 * (e.g. `EXECUTION_FAILED`, `INVALID_PARAMETERS`).
 */
class PhotoKitException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'EXECUTION_FAILED')
    {
        parent::__construct($message);
    }
}
