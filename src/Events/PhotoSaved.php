<?php

namespace Vipertecpro\PhotoKit\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a file has been written into the photo library.
 *
 * Public properties are populated by name from the native payload, so a
 * `#[On(PhotoSaved::class)]` handler can type-hint any subset of them.
 *
 * @property string $path The local source file that was saved.
 * @property string $type `image` or `video`.
 * @property ?string $uri The library entry: a PHAsset local identifier on iOS, a content:// URI on Android.
 * @property ?string $album The album / folder the file was added to, if any.
 * @property ?string $id The correlation id passed to PhotoKit::save(), if any.
 */
class PhotoSaved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $path,
        public string $type = 'image',
        public ?string $uri = null,
        public ?string $album = null,
        public ?string $id = null,
    ) {}
}
