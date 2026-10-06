## vipertecpro/photo-kit

A NativePHP Mobile plugin with hand-written Swift and Kotlin that (1) saves
images and videos to the photo library with the proper permission prompt,
(2) compresses / resizes images and bakes the EXIF orientation into the
pixels, and (3) reads EXIF metadata. Works with NativePHP Mobile v4.

### What it does / does not do

- Input is always an absolute path to a LOCAL file. It does not download —
  fetch remote media first (`Http::sink($tmp)->get($url)`), then pass `$tmp`.
- It does not pick or capture photos. Use `nativephp/mobile-camera`
  (`Camera::pickImages()` / `Camera::getPhoto()`) or any file you already have.
- Saving is asynchronous: `save()` returns `void` and the outcome arrives as a
  `PhotoSaved` or `PhotoSaveFailed` event.
- Processing is synchronous: `process()` / `compress()` / `resize()` /
  `fixOrientation()` return an array describing the NEW file; the source is
  never modified. The output is always upright (EXIF orientation applied).
- Outside a native app (tests, web) the methods are no-ops: `save()` does
  nothing, the array-returning methods return `[]`, `permissionStatus()`
  returns `unknown`. Invalid input still throws `InvalidArgumentException`.

### Facade methods

`use Vipertecpro\PhotoKit\Facades\PhotoKit;`

- `PhotoKit::save(string $path, array $options = []): void` — options `type` (`image`|`video`, default by extension), `album` (string), `id` (echoed on the event).
- `PhotoKit::saveImage($path, $options)` / `PhotoKit::saveVideo($path, $options)` — same with the type forced.
- `PhotoKit::permissionStatus(bool $forAlbums = false): string` — `granted`, `limited`, `denied`, `restricted`, `notDetermined` (iOS), `notRequired` (Android 10+), `unknown` (no bridge).
- `PhotoKit::requestPermission(bool $forAlbums = false): void` — fires `PhotoLibraryPermissionResult`.
- `PhotoKit::process(string $path, array $options = []): array` — options `maxWidth`, `maxHeight` (px, 0 = unlimited, fit, never upscale), `quality` (1–100, default 85), `format` (`jpeg` default, `png`, `webp` Android-only, `heic` iOS-only), `output` (absolute path), `keepMetadata` (bool, default false). Returns `path`, `width`, `height`, `bytes`, `format`, `originalWidth`, `originalHeight`, `originalBytes`, `orientation`.
- `PhotoKit::compress($path, int $quality = 75, array $options = [])`, `PhotoKit::resize($path, int $maxWidth, int $maxHeight = 0, array $options = [])`, `PhotoKit::fixOrientation($path, array $options = [])` — shortcuts over `process()`.
- `PhotoKit::exif(string $path): array` — `width`, `height`, `displayWidth`, `displayHeight`, `orientation` (1–8), `orientationLabel`, `needsRotation`, `mime`, `bytes`, and when present `make`, `model`, `software`, `dateTaken`, `exposureTime`, `fNumber`, `iso`, `focalLength`, `lensModel`, `latitude`, `longitude`, `altitude`.

A native error for a synchronous call (undecodable file, unsupported format
on this platform) throws `Vipertecpro\PhotoKit\Exceptions\PhotoKitException`.

### Usage (SuperNative / NativeComponent)

@verbatim
<code-snippet name="Pick a photo, shrink it for upload, save the original to Photos" lang="php">
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;
use Vipertecpro\PhotoKit\Events\PhotoSaved;
use Vipertecpro\PhotoKit\Events\PhotoSaveFailed;
use Vipertecpro\PhotoKit\Facades\PhotoKit;

class UploadPhoto extends NativeComponent
{
    public ?string $upload = null;
    public string $status = '';

    public function pick(): void
    {
        Camera::pickImages('images', false);
    }

    #[On(MediaSelected::class)]
    public function onPicked(bool $success, array $files): void
    {
        if (! $success || $files === []) {
            return;
        }

        $path = is_array($files[0]) ? $files[0]['path'] : $files[0];

        // A small, upright JPEG for the API (EXIF orientation baked in):
        $this->upload = PhotoKit::process($path, ['maxWidth' => 1600, 'maxHeight' => 1600, 'quality' => 80])['path'];

        // Keep the original in the user's photo library:
        PhotoKit::save($path, ['album' => 'My App']);
    }

    #[On(PhotoSaved::class)]
    public function onSaved(string $path, ?string $uri): void
    {
        $this->status = 'Saved to Photos';
    }

    #[On(PhotoSaveFailed::class)]
    public function onSaveFailed(string $reason, ?string $message): void
    {
        $this->status = $reason === 'permission_denied' ? 'Allow photo access in Settings' : ($message ?? $reason);
    }
}
</code-snippet>
@endverbatim

### Events

All under `Vipertecpro\PhotoKit\Events`; listen with `#[On(Event::class)]`.

- `PhotoSaved` — `string $path`, `string $type`, `?string $uri` (PHAsset local identifier on iOS, content:// URI on Android), `?string $album`, `?string $id`.
- `PhotoSaveFailed` — `string $path`, `string $type`, `string $reason` (`permission_denied`, `file_not_found`, `unsupported`, `write_failed`), `?string $message`, `?string $id`.
- `PhotoLibraryPermissionResult` — `string $status`, `string $level` (`add` or `readWrite`).

### Permissions

- iOS: the plugin declares `NSPhotoLibraryAddUsageDescription` (add-only, used
  by default) and `NSPhotoLibraryUsageDescription` (full access, requested only
  when `album` is passed). Override the wording per app in
  `config('nativephp.permissions')`.
- Android 10+: no runtime permission — files are inserted into MediaStore
  under `Pictures/<album>` or `Movies/<album>`.

### Installation & registration

@verbatim
<code-snippet name="Install & register the plugin" lang="bash">
composer require vipertecpro/photo-kit
php artisan vendor:publish --tag=nativephp-plugins-provider   # once per app
php artisan native:plugin:register vipertecpro/photo-kit
php artisan native:plugin:list      # verify "PhotoKit" and its five functions appear
php artisan native:run ios          # or: android  — rebuild to compile native code
</code-snippet>
@endverbatim

### Showing a processed image

@verbatim
<code-snippet name="Display the processed file" lang="blade">
<native:image :src="$upload" :fit="1" class="w-full h-[240] rounded-xl" />
</code-snippet>
@endverbatim

### Legacy web-view apps

A JS bridge is shipped at `resources/js/photoKit.js`. `save()` is async via
native events (subscribe with the `#nativephp` `On()` helper); `process()` and
`exif()` return their result directly. Prefer `NativeComponent` + `#[On]`.
