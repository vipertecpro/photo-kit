<?php

namespace Vipertecpro\PhotoKit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a file could not be saved to the photo library.
 *
 * @property string $path The local source file.
 * @property string $type `image` or `video`.
 * @property string $reason Machine-readable: `permission_denied`, `file_not_found`, `unsupported`, `write_failed`.
 * @property ?string $message A human-readable detail from the platform, when there is one.
 * @property ?string $id The correlation id passed to PhotoKit::save(), if any.
 */
class PhotoSaveFailed
{
    use Dispatchable, SerializesModels;

    public const PERMISSION_DENIED = 'permission_denied';

    public const FILE_NOT_FOUND = 'file_not_found';

    public const UNSUPPORTED = 'unsupported';

    public const WRITE_FAILED = 'write_failed';

    public function __construct(
        public string $path,
        public string $type = 'image',
        public string $reason = self::WRITE_FAILED,
        public ?string $message = null,
        public ?string $id = null,
    ) {}
}
