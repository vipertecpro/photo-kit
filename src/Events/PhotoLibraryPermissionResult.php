<?php

namespace Vipertecpro\PhotoKit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after PhotoKit::requestPermission() — once the user has answered
 * the OS prompt, or immediately when no prompt was needed.
 *
 * @property string $status `granted`, `limited`, `denied`, `restricted`, `notDetermined` or `notRequired`.
 * @property string $level `add` (add-only access) or `readWrite` (full library access).
 */
class PhotoLibraryPermissionResult
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $status,
        public string $level = 'add',
    ) {}
}
