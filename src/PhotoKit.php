<?php

namespace Vipertecpro\PhotoKit;

use InvalidArgumentException;
use Vipertecpro\PhotoKit\Events\PhotoLibraryPermissionResult;
use Vipertecpro\PhotoKit\Events\PhotoSaved;
use Vipertecpro\PhotoKit\Events\PhotoSaveFailed;
use Vipertecpro\PhotoKit\Exceptions\PhotoKitException;

/**
 * PHP entry point for Photo Kit.
 *
 * Four things, one API, both platforms:
 *
 *  - {@see save()} / {@see saveImage()} / {@see saveVideo()} put a file into
 *    the user's photo library (Photos on iOS, MediaStore / Gallery on Android),
 *    asking for permission first. The outcome arrives asynchronously as a
 *    {@see PhotoSaved} or {@see PhotoSaveFailed} event.
 *  - {@see permissionStatus()} and {@see requestPermission()} expose the
 *    photo-library permission so a screen can explain itself before the OS
 *    prompt appears.
 *  - {@see process()} (with the {@see compress()}, {@see resize()} and
 *    {@see fixOrientation()} shortcuts) re-encodes an image natively —
 *    smaller, bounded to a size, in another format — and ALWAYS bakes the
 *    EXIF orientation into the pixels, so the result is upright everywhere.
 *  - {@see exif()} reads an image's dimensions, EXIF orientation, camera,
 *    capture date and GPS position.
 *
 *     use Vipertecpro\PhotoKit\Facades\PhotoKit;
 *
 *     PhotoKit::save($path);                                     // → PhotoSaved / PhotoSaveFailed
 *     $small = PhotoKit::compress($path, 70);                    // ['path' => …, 'bytes' => …]
 *     $thumb = PhotoKit::resize($path, 400, 400);
 *     $meta  = PhotoKit::exif($path);                            // ['orientation' => 6, …]
 *
 * Every image returned by process() is a NEW file; the source is never touched.
 */
class PhotoKit
{
    /** Image formats the native side can decode (by extension — the real check is the decode). */
    public const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'heic', 'heif', 'avif', 'tif', 'tiff', 'dng'];

    /** Video containers the photo library accepts. */
    public const VIDEO_EXTENSIONS = ['mp4', 'mov', 'm4v', '3gp', 'webm', 'mkv', 'avi'];

    /**
     * Output formats {@see process()} can write. `webp` is Android-only and
     * `heic` is iOS-only; the native side reports an error for the other one.
     */
    public const OUTPUT_FORMATS = ['jpeg', 'png', 'webp', 'heic'];

    /** Permission statuses {@see permissionStatus()} can return. */
    public const STATUS_GRANTED = 'granted';

    public const STATUS_LIMITED = 'limited';

    public const STATUS_DENIED = 'denied';

    public const STATUS_RESTRICTED = 'restricted';

    public const STATUS_NOT_DETERMINED = 'notDetermined';

    public const STATUS_NOT_REQUIRED = 'notRequired';

    public const STATUS_UNKNOWN = 'unknown';

    /** Human labels for the eight EXIF orientation values. */
    public const ORIENTATIONS = [
        1 => 'normal',
        2 => 'flip-horizontal',
        3 => 'rotate-180',
        4 => 'flip-vertical',
        5 => 'transpose',
        6 => 'rotate-90',
        7 => 'transverse',
        8 => 'rotate-270',
    ];

    // --- Saving to the photo library -----------------------------------------

    /**
     * Save a local image or video file to the photo library. The media type
     * is detected from the extension; pass `type` to force it.
     *
     * @param  string  $path  Absolute path to a local image or video file.
     * @param  array{type?: string, album?: string, id?: string}  $options
     *                `type`: `image` or `video` (default: by extension).
     *                `album`: album / folder name. On Android this is the
     *                sub-folder under Pictures or Movies; on iOS the photo
     *                is also added to an album of that name, which needs
     *                full photo-library access instead of add-only access.
     *                `id`: correlation id echoed back on the result event.
     *
     * Fires {@see PhotoSaved} or {@see PhotoSaveFailed}.
     *
     * @throws InvalidArgumentException When the file does not exist or has an unknown extension.
     */
    public function save(string $path, array $options = []): void
    {
        $this->assertLocalFile($path);

        $type = $options['type'] ?? $this->detectType($path);

        if (! in_array($type, ['image', 'video'], true)) {
            throw new InvalidArgumentException("PhotoKit can save images and videos only — got type \"{$type}\".");
        }

        $payload = [
            'path' => $path,
            'type' => $type,
            'album' => $this->cleanAlbum($options['album'] ?? null),
            'id' => $options['id'] ?? null,
            'extension' => $this->extensionFor($path),
        ];

        if (! function_exists('nativephp_call')) {
            return;
        }

        nativephp_call('PhotoKit.Save', json_encode($payload));
    }

    /** {@see save()} with the type forced to `image`. */
    public function saveImage(string $path, array $options = []): void
    {
        $this->save($path, ['type' => 'image'] + $options);
    }

    /** {@see save()} with the type forced to `video`. */
    public function saveVideo(string $path, array $options = []): void
    {
        $this->save($path, ['type' => 'video'] + $options);
    }

    // --- Permission ----------------------------------------------------------

    /**
     * The current photo-library permission, without prompting.
     *
     * iOS: `granted`, `limited`, `denied`, `restricted` or `notDetermined`.
     * Android 10+: always `notRequired` — saving into the app's own entries
     * in MediaStore needs no runtime permission. Outside a native app:
     * `unknown`.
     *
     * @param  bool  $forAlbums  Check full (read/write) access instead of
     *                           add-only access — what `save()` needs when
     *                           an `album` is given on iOS.
     */
    public function permissionStatus(bool $forAlbums = false): string
    {
        $result = $this->call('PhotoKit.PermissionStatus', ['level' => $forAlbums ? 'readWrite' : 'add']);

        return is_string($result['status'] ?? null) ? $result['status'] : self::STATUS_UNKNOWN;
    }

    /**
     * Show the OS permission prompt (if the user has not answered it yet) and
     * report the outcome via {@see PhotoLibraryPermissionResult}. On Android
     * 10+ the event fires immediately with `notRequired`.
     */
    public function requestPermission(bool $forAlbums = false): void
    {
        $this->call('PhotoKit.RequestPermission', ['level' => $forAlbums ? 'readWrite' : 'add']);
    }

    // --- Processing: compress / resize / fix orientation ---------------------

    /**
     * Re-encode an image natively and return the NEW file.
     *
     * The output is always upright: the EXIF orientation is applied to the
     * pixels and the tag is reset, so the file displays correctly in browsers,
     * `<img>` tags and servers that ignore EXIF.
     *
     * @param  array{
     *     maxWidth?: int,
     *     maxHeight?: int,
     *     quality?: int,
     *     format?: string,
     *     output?: string,
     *     keepMetadata?: bool
     * }  $options
     *     `maxWidth` / `maxHeight`: bounding box in pixels (0 = no limit);
     *     the image is scaled down to fit, never up, keeping its ratio.
     *     `quality`: 1–100 for lossy formats (default 85).
     *     `format`: `jpeg` (default), `png`, `webp` (Android) or `heic` (iOS).
     *     `output`: absolute path to write to (default: a new file in the
     *     app's temp directory).
     *     `keepMetadata`: keep the EXIF tags (camera, date, GPS) in the output
     *     with the orientation reset to 1. Default false, which strips them —
     *     the privacy-friendly default for uploads.
     * @return array{path: string, width: int, height: int, bytes: int, format: string, originalWidth: int, originalHeight: int, originalBytes: int, orientation: int}
     *                Empty outside a native app.
     *
     * @throws InvalidArgumentException For a missing file or a bad option.
     * @throws PhotoKitException When the native side cannot decode or encode the image.
     */
    public function process(string $path, array $options = []): array
    {
        $this->assertLocalFile($path);

        if ($this->detectType($path) !== 'image') {
            throw new InvalidArgumentException('PhotoKit::process() works on images — pass a jpg, png, heic, webp, gif or similar file.');
        }

        return $this->call('PhotoKit.Process', $this->resolveProcessOptions($path, $options));
    }

    /**
     * Shrink the file size: re-encode at the given JPEG/WebP/HEIC quality,
     * optionally bounded to `maxWidth` × `maxHeight`.
     *
     * @return array{path: string, width: int, height: int, bytes: int, format: string, originalWidth: int, originalHeight: int, originalBytes: int, orientation: int}
     */
    public function compress(string $path, int $quality = 75, array $options = []): array
    {
        return $this->process($path, ['quality' => $quality] + $options);
    }

    /**
     * Scale the image down to fit inside `maxWidth` × `maxHeight` (keeping its
     * aspect ratio, never upscaling).
     *
     * @return array{path: string, width: int, height: int, bytes: int, format: string, originalWidth: int, originalHeight: int, originalBytes: int, orientation: int}
     */
    public function resize(string $path, int $maxWidth, int $maxHeight = 0, array $options = []): array
    {
        return $this->process($path, ['maxWidth' => $maxWidth, 'maxHeight' => $maxHeight] + $options);
    }

    /**
     * Write an upright copy of the image — the EXIF orientation baked into the
     * pixels at full size — keeping the camera/date metadata by default so
     * nothing but the orientation changes.
     *
     * @return array{path: string, width: int, height: int, bytes: int, format: string, originalWidth: int, originalHeight: int, originalBytes: int, orientation: int}
     */
    public function fixOrientation(string $path, array $options = []): array
    {
        return $this->process($path, ['quality' => 95, 'keepMetadata' => true] + $options);
    }

    // --- EXIF ----------------------------------------------------------------

    /**
     * Read an image's metadata. Keys (any may be missing when the file has no
     * such tag): `width`, `height` (as stored), `displayWidth`, `displayHeight`
     * (after applying the orientation), `orientation` (1–8),
     * `orientationLabel`, `needsRotation`, `mime`, `bytes`, `make`, `model`,
     * `software`, `dateTaken` (`YYYY-MM-DD HH:MM:SS`), `exposureTime`,
     * `fNumber`, `iso`, `focalLength`, `lensModel`, `latitude`, `longitude`,
     * `altitude`.
     *
     * @return array<string, mixed> Empty outside a native app.
     *
     * @throws PhotoKitException When the file cannot be read as an image.
     */
    public function exif(string $path): array
    {
        $this->assertLocalFile($path);

        $data = $this->call('PhotoKit.ReadExif', ['path' => $path]);

        if ($data === []) {
            return [];
        }

        $orientation = (int) ($data['orientation'] ?? 1);
        $orientation = isset(self::ORIENTATIONS[$orientation]) ? $orientation : 1;

        $data['orientation'] = $orientation;
        $data['orientationLabel'] = self::ORIENTATIONS[$orientation];
        $data['needsRotation'] = $orientation !== 1;

        return $data;
    }

    // --- Helpers -------------------------------------------------------------

    /**
     * `image`, `video` or `unknown`. The extension decides when it is a known
     * one; otherwise the first bytes of the file are checked, because pickers
     * and downloads often hand over paths without an extension.
     */
    public function detectType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match (true) {
            in_array($extension, self::IMAGE_EXTENSIONS, true) => 'image',
            in_array($extension, self::VIDEO_EXTENSIONS, true) => 'video',
            default => $this->sniff($path)['type'] ?? 'unknown',
        };
    }

    /**
     * Identify a file from its signature bytes.
     *
     * @return array{type: string, extension: string, mime: string}|null
     */
    public function sniff(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $head = (string) @file_get_contents($path, false, null, 0, 32);

        if (strlen($head) < 12) {
            return null;
        }

        $media = fn (string $type, string $extension, string $mime): array => compact('type', 'extension', 'mime');

        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return $media('image', 'jpg', 'image/jpeg');
        }

        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            return $media('image', 'png', 'image/png');
        }

        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return $media('image', 'gif', 'image/gif');
        }

        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return $media('image', 'webp', 'image/webp');
        }

        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'AVI ') {
            return $media('video', 'avi', 'video/x-msvideo');
        }

        if (str_starts_with($head, 'BM') && substr($head, 6, 4) === "\0\0\0\0") {
            return $media('image', 'bmp', 'image/bmp');
        }

        if (str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*")) {
            return $media('image', 'tiff', 'image/tiff');
        }

        if (str_starts_with($head, "\x1A\x45\xDF\xA3")) {
            return str_contains($head, 'webm') ? $media('video', 'webm', 'video/webm') : $media('video', 'mkv', 'video/x-matroska');
        }

        if (substr($head, 4, 4) === 'ftyp') {
            return match (strtolower(substr($head, 8, 4))) {
                'heic', 'heix', 'heim', 'heis', 'hevc', 'hevx' => $media('image', 'heic', 'image/heic'),
                'mif1', 'msf1', 'heif' => $media('image', 'heif', 'image/heif'),
                'avif', 'avis' => $media('image', 'avif', 'image/avif'),
                'qt  ' => $media('video', 'mov', 'video/quicktime'),
                'm4v ' => $media('video', 'm4v', 'video/x-m4v'),
                '3gp4', '3gp5', '3gp6', '3gg6', '3g2a' => $media('video', '3gp', 'video/3gpp'),
                default => $media('video', 'mp4', 'video/mp4'),
            };
        }

        return null;
    }

    /**
     * Normalise the process() options into the flat config the native side reads.
     *
     * @return array{path: string, maxWidth: int, maxHeight: int, quality: int, format: string, output: string|null, keepMetadata: bool}
     */
    protected function resolveProcessOptions(string $path, array $options): array
    {
        $format = strtolower((string) ($options['format'] ?? 'jpeg'));
        $format = $format === 'jpg' ? 'jpeg' : $format;

        if (! in_array($format, self::OUTPUT_FORMATS, true)) {
            throw new InvalidArgumentException(
                "PhotoKit cannot write \"{$format}\" — supported formats are: ".implode(', ', self::OUTPUT_FORMATS).'.'
            );
        }

        $quality = (int) ($options['quality'] ?? 85);

        if ($quality < 1 || $quality > 100) {
            throw new InvalidArgumentException('PhotoKit quality must be between 1 and 100.');
        }

        $maxWidth = max(0, (int) ($options['maxWidth'] ?? 0));
        $maxHeight = max(0, (int) ($options['maxHeight'] ?? 0));

        $output = $options['output'] ?? null;

        if ($output !== null && (! is_string($output) || $output === '' || $output === $path)) {
            throw new InvalidArgumentException('PhotoKit output must be a path different from the source.');
        }

        return [
            'path' => $path,
            'maxWidth' => $maxWidth,
            'maxHeight' => $maxHeight,
            'quality' => $quality,
            'format' => $format,
            'output' => $output,
            'keepMetadata' => (bool) ($options['keepMetadata'] ?? false),
        ];
    }

    /** Reject anything that is not a readable local file, loudly, before the bridge. */
    protected function assertLocalFile(string $path): void
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $path) === 1) {
            throw new InvalidArgumentException(
                'PhotoKit works on local files only — download remote media first (e.g. Http::sink($tmp)->get($url)).'
            );
        }

        if (! is_file($path)) {
            throw new InvalidArgumentException("PhotoKit: file not found at \"{$path}\".");
        }

        if ($this->detectType($path) === 'unknown') {
            throw new InvalidArgumentException(
                'PhotoKit does not recognise "'.basename($path).'" as an image or video — images: '
                .implode(', ', self::IMAGE_EXTENSIONS).'; videos: '.implode(', ', self::VIDEO_EXTENSIONS).'.'
            );
        }
    }

    /**
     * The real extension of a file — its own when known, else the sniffed
     * one — so the native side can name and type extension-less files.
     */
    protected function extensionFor(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, [...self::IMAGE_EXTENSIONS, ...self::VIDEO_EXTENSIONS], true)) {
            return $extension;
        }

        return $this->sniff($path)['extension'] ?? null;
    }

    /** Album names become folder names on Android, so keep them to safe characters. */
    protected function cleanAlbum(?string $album): ?string
    {
        if ($album === null) {
            return null;
        }

        $album = preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $album) ?? '';
        $album = trim(preg_replace('/\s+/', ' ', $album) ?? '');

        return $album === '' ? null : mb_substr($album, 0, 60);
    }

    /**
     * Call a synchronous bridge function and decode its result. A native
     * error response becomes a {@see PhotoKitException}; a missing bridge
     * (tests, web) yields an empty array.
     *
     * @return array<string, mixed>
     */
    protected function call(string $method, array $params): array
    {
        if (! function_exists('nativephp_call')) {
            return [];
        }

        $raw = nativephp_call($method, json_encode($params));

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        if (($decoded['status'] ?? null) === 'error') {
            throw new PhotoKitException(
                (string) ($decoded['message'] ?? 'Photo Kit native call failed.'),
                (string) ($decoded['code'] ?? 'EXECUTION_FAILED'),
            );
        }

        return $decoded;
    }
}
