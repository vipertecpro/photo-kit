# Photo Kit for NativePHP — save, compress, resize and fix photos natively

Save images and videos to the user's **photo library** with the right
permission prompt, **compress** and **resize** images on the device, **read
EXIF** metadata and **fix the EXIF orientation** so photos stop showing up
sideways — all from one PHP API, with hand-written Swift (Photos + ImageIO) and
Kotlin (MediaStore + BitmapFactory) behind it and **zero third-party native
libraries**.

Photo Kit is free and pairs naturally with the free **Image Cropper** plugin:
crop or edit a photo with the Image Cropper, then hand the result to Photo Kit
to shrink it for upload or save it to the camera roll.

## Features

- **Save to Photos / Gallery** — images and videos, optionally into a named album, with permission prompts handled for you
- **Permission aware** — read the status first, ask only when needed; add-only access on iOS by default
- **Compress** — re-encode at a quality to cut upload sizes
- **Resize** — fit an image into a bounding box, never upscaled, aspect ratio kept
- **EXIF orientation fixed for good** — every processed image is upright in the pixels, not just in a tag
- **EXIF reader** — dimensions, orientation, camera, date, exposure, GPS — same keys on both platforms
- **Events, not polling** — `PhotoSaved`, `PhotoSaveFailed`, `PhotoLibraryPermissionResult`
- **Zero dependencies** — no third-party native libraries, no network
- **iOS + Android** behind one PHP API

## Requirements

- PHP 8.4+
- NativePHP Mobile v4 (`nativephp/mobile: ^4.0`) — tested on iOS and Android against 4.6
- iOS 15+ / Android 10+ (API 29)

## Installation

```bash
composer require vipertecpro/photo-kit
php artisan vendor:publish --tag=nativephp-plugins-provider   # once per app
php artisan native:plugin:register vipertecpro/photo-kit
php artisan native:plugin:list       # verify "PhotoKit" and its five functions appear
php artisan native:run ios           # or: android — rebuild so the native code compiles in
```

> Requiring with Composer is **not** enough — an unregistered plugin does
> nothing. Always run `native:plugin:register` and confirm with
> `native:plugin:list`.

## Permissions

The plugin declares everything it needs; you only need to know what the user
will see.

| Platform | What the plugin adds | When it is used |
|---|---|---|
| iOS | `NSPhotoLibraryAddUsageDescription` (Info.plist) | Every `save()` — add-only access, the user is not asked to share their library |
| iOS | `NSPhotoLibraryUsageDescription` (Info.plist) | Only when `save()` is called with an `album` — iOS needs full access to list and create albums |
| Android 10+ | nothing | Files are inserted into MediaStore under `Pictures/` or `Movies/`; no runtime permission is required |

To change the iOS wording for your app (recommended for App Store review),
override the keys in `config/nativephp.php` under `permissions` — app-level
values always win over plugin defaults.

No `AndroidManifest.xml` changes are needed. Reading EXIF and processing
images work on files your app can already read (its own storage, the camera
plugin's output, downloads) and need no permission on either platform.

## Usage

Call the facade from a `NativeComponent`, then handle the result events.

```php
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
        Camera::pickImages('images', false);          // any image source works
    }

    #[On(MediaSelected::class)]
    public function onPicked(bool $success, array $files): void
    {
        if (! $success || $files === []) {
            return;
        }

        $path = is_array($files[0]) ? $files[0]['path'] : $files[0];

        // A small, upright JPEG for your API — EXIF orientation baked in:
        $small = PhotoKit::process($path, ['maxWidth' => 1600, 'maxHeight' => 1600, 'quality' => 80]);
        $this->upload = $small['path'];               // e.g. 4032×3024 / 3.1 MB → 1600×1200 / 310 KB

        // …and keep the original in the user's photo library:
        PhotoKit::save($path, ['album' => 'My App']);
    }

    #[On(PhotoSaved::class)]
    public function onSaved(string $path, ?string $uri): void
    {
        $this->status = 'Saved to your photos';
    }

    #[On(PhotoSaveFailed::class)]
    public function onSaveFailed(string $reason, ?string $message): void
    {
        $this->status = $reason === 'permission_denied'
            ? 'Please allow photo access in Settings'
            : ($message ?? 'Could not save');
    }
}
```

Show the processed file with `native:image`:

```blade
<native:image :src="$upload" :fit="1" class="w-full h-[240] rounded-xl" />
<native:text class="text-sm">{{ $status }}</native:text>
```

### Saving

```php
PhotoKit::save($path);                                  // image or video, by extension
PhotoKit::save($path, ['album' => 'Receipts']);         // into an album / folder
PhotoKit::saveVideo($clip, ['id' => 'clip-42']);        // force the type, correlate the event
```

Saving is asynchronous: `save()` returns immediately and the result arrives as
an event. The file you pass is **copied** into the library — your original is
untouched, so you can delete temp files once `PhotoSaved` arrives.

Photo Kit works on local files. To save something from the web, download it
first and pass the temp path:

```php
$tmp = storage_path('app/tmp/'.basename($url));
Http::sink($tmp)->get($url);
PhotoKit::save($tmp);
```

### Permission

```php
PhotoKit::permissionStatus();          // granted | limited | denied | restricted | notDetermined | notRequired
PhotoKit::permissionStatus(true);      // full-access status (needed for albums on iOS)
PhotoKit::requestPermission();         // → PhotoLibraryPermissionResult event
```

You never *have* to call these — `save()` asks on its own. They exist so a
screen can explain why it needs access before the system prompt, or show a
"open Settings" hint after a denial.

### Compress, resize, fix orientation

```php
$result = PhotoKit::process($path, [
    'maxWidth' => 1600,        // bounding box, px (0 = no limit)
    'maxHeight' => 1600,       // the image is scaled DOWN to fit, never up
    'quality' => 80,           // 1–100 for jpeg / webp / heic
    'format' => 'jpeg',        // jpeg (default) · png · webp (Android) · heic (iOS)
    'output' => null,          // absolute path, or null for a new temp file
    'keepMetadata' => false,   // keep camera / date / GPS tags (orientation reset to 1)
]);

// ['path' => '…/photokit_….jpg', 'width' => 1600, 'height' => 1200, 'bytes' => 318204,
//  'format' => 'jpeg', 'originalWidth' => 3024, 'originalHeight' => 4032,
//  'originalBytes' => 3145728, 'orientation' => 6]

PhotoKit::compress($path, 60);                       // smaller file, same size
PhotoKit::resize($path, 400, 400);                   // thumbnail
PhotoKit::fixOrientation($path);                     // upright copy, full size, metadata kept
```

Every call returns a **new** file and never changes the source. The output is
always upright: the EXIF orientation is applied to the pixels and reset in the
tag, so the file displays correctly in `<img>` tags, on servers that ignore
EXIF, and in image libraries that don't honour the flag.

`originalWidth` / `originalHeight` are the dimensions **as stored** in the
file; when `orientation` is 5–8 the upright image has them swapped — which is
exactly what `width` / `height` report.

### EXIF

```php
$meta = PhotoKit::exif($path);

// ['width' => 3024, 'height' => 4032, 'displayWidth' => 4032, 'displayHeight' => 3024,
//  'orientation' => 8, 'orientationLabel' => 'rotate-270', 'needsRotation' => true,
//  'mime' => 'image/jpeg', 'bytes' => 2816533,
//  'make' => 'Apple', 'model' => 'iPhone 17 Pro', 'software' => '26.0',
//  'dateTaken' => '2026-10-06 09:41:00', 'exposureTime' => 0.0083, 'fNumber' => 1.78,
//  'iso' => 80, 'focalLength' => 6.86, 'lensModel' => 'iPhone 17 Pro back camera 6.86mm f/1.78',
//  'latitude' => 12.9716, 'longitude' => 77.5946, 'altitude' => 920.3]
```

Keys that the file does not carry are simply absent, so read them with `??`.
On Android, a photo chosen with the system photo picker has its location
redacted by the OS; Photo Kit then leaves `latitude` / `longitude` out rather
than reporting 0, 0.
`dateTaken` is normalised to `YYYY-MM-DD HH:MM:SS` so `Carbon::parse()` works.

### API

```php
PhotoKit::save(string $path, array $options = []): void;
PhotoKit::saveImage(string $path, array $options = []): void;
PhotoKit::saveVideo(string $path, array $options = []): void;
PhotoKit::permissionStatus(bool $forAlbums = false): string;
PhotoKit::requestPermission(bool $forAlbums = false): void;
PhotoKit::process(string $path, array $options = []): array;
PhotoKit::compress(string $path, int $quality = 75, array $options = []): array;
PhotoKit::resize(string $path, int $maxWidth, int $maxHeight = 0, array $options = []): array;
PhotoKit::fixOrientation(string $path, array $options = []): array;
PhotoKit::exif(string $path): array;
```

| `save()` option | Values | Default |
|---|---|---|
| `type` | `image` \| `video` | by file extension, or by the file's first bytes when it has none |
| `album` | album / folder name (unsafe path characters are removed) | none |
| `id` | correlation id echoed back on the event | `null` |

Invalid input throws before the bridge is touched: a missing file, a remote
URL, a file that is neither an image nor a video, a format outside `jpeg` / `png` / `webp` / `heic`,
or a quality outside 1–100 raise `InvalidArgumentException`. A native failure
on a synchronous call (undecodable bytes, WebP requested on iOS, HEIC on
Android) throws `Vipertecpro\PhotoKit\Exceptions\PhotoKitException`.

### Events

| Event | Payload | Fired when |
|---|---|---|
| `Vipertecpro\PhotoKit\Events\PhotoSaved` | `string $path`, `string $type`, `?string $uri`, `?string $album`, `?string $id` | The file is in the photo library. `$uri` is a `PHAsset` local identifier on iOS and a `content://` URI on Android. |
| `Vipertecpro\PhotoKit\Events\PhotoSaveFailed` | `string $path`, `string $type`, `string $reason`, `?string $message`, `?string $id` | `reason` is `permission_denied`, `file_not_found`, `unsupported` or `write_failed`. |
| `Vipertecpro\PhotoKit\Events\PhotoLibraryPermissionResult` | `string $status`, `string $level` | After `requestPermission()` — once the prompt is answered, or immediately when no prompt was needed. |

Pass an `id` option when several saves could be in flight, to correlate the event.

### Legacy web-view apps

A JS bridge is shipped at `resources/js/photoKit.js`. `save()` is asynchronous
and reports through the native events — subscribe with the `#nativephp` `On()`
helper; `process()` and `exif()` return their result directly. See the file
header for an example.

## What you can build

Photo Kit is a building block: saving to the photo library, compressing, resizing,
fixing orientation and reading EXIF are done, and the product around them is
yours. These ideas sit comfortably inside store policy as long as the person
chooses the photos, knows when one is saved or uploaded, and is not surprised by
what leaves the device. Photo Kit works on local files; picking or capturing a
photo, and uploading the result, are yours.

**Camera and upload apps**

- **Lighter uploads for marketplace and classifieds apps.** `process()` shrinks a
  3 MB camera photo to a 1600-pixel JPEG before upload, so listings load fast and
  data plans last longer.
- **Profile and avatar uploads.** Resize to a small thumbnail with `resize()`,
  crop it first with Image Cropper if you want a round avatar, and upload the
  result to your own server.
- **Sideways photos fixed for good.** `fixOrientation()` writes the rotation into
  the pixels, so photos look right on a server or in a web view that ignores EXIF.

**Work and field apps**

- **Inspection, site and delivery photos.** Save each photo to a named album such
  as `Site visits` with `save($path, ['album' => ...])`, and upload a compressed
  copy to your own backend.
- **Receipts and expenses.** Compress a receipt photo for upload and keep a
  copy in a `Receipts` album, but only with the person's knowledge.
- **Real-estate and insurance photo reports.** Read `exif()` for the date taken
  and the camera model to put in a report. Treat any GPS data as personal data.

**Creative and community**

- **Photo-journal and travel apps.** Save edited pictures to the gallery and read
  the date taken to order entries.
- **Photo sharing and social apps.** Strip camera and location tags before upload
  (`keepMetadata` is off by default), so a shared picture does not give away where
  someone lives.

Storing, serving and moderating images needs your own backend. Read GPS only when
the feature needs it and say so in your privacy policy. Photo Kit does not scrape
other apps' galleries or watch the library; it saves, reads and processes files
you give it. Explain why the app wants library access before the system prompt.

## Limitations

- **Local files only.** Download remote media first (one line with `Http::sink()`).
- **Output formats are platform-specific:** WebP can be written on Android
  only, HEIC on iOS only. JPEG and PNG work everywhere.
- **iOS albums need full access.** Passing `album` on iOS triggers the full
  photo-library prompt instead of the add-only one; omit `album` if you want
  the lighter prompt.
- **Processing is synchronous.** A 12-megapixel photo takes roughly 100–400 ms
  on current devices; the screen is blocked for that time. Decode is
  downsampled, so memory stays bounded even for very large originals.
- **Videos are saved, not processed.** `process()` and `exif()` are for images;
  pass a video only to `save()`.
- **Android 9 and older are not supported** (minimum API 29, matching the
  NativePHP baseline).
- The iOS Simulator cannot encode HEIC on every host; the plugin reports that
  as a `PhotoKitException` instead of crashing.

## Verified on

- iOS Simulator, iPhone 17 Pro (iOS 26.5): add-only and full-access permission
  prompts, a denied prompt, saving an image and a video to Photos, saving into
  an album, compress, resize, fix orientation, PNG and HEIC output, the WebP
  error, and EXIF read (including camera and GPS tags), in light and dark mode.
- Android emulator, Pixel 9 (API 36): saving an image and a video to MediaStore
  (also from picker copies without an extension), saving into an album,
  compress, resize, fix orientation, PNG and WebP output, the HEIC error, and
  EXIF read (camera tags; the picker redacts GPS), in light and dark mode.
- Not tested on physical devices or on Android versions older than 16.

## Demo

The companion demo app **free-plugins-demo** contains four ready-made
screens — Save to Photos, Compress & Resize, EXIF & Orientation, and Crop then
Save (with the Image Cropper) — each a tiny `NativeComponent` you can copy from.

## Contributing

Issues and pull requests are welcome. See the `CONTRIBUTING.md` file included
with the package for local setup, the project layout and how it works.

## Changelog

See the `CHANGELOG.md` file included with the package for the full version history.

## Licence

MIT — see the `LICENSE` file included with the package.

vipertecpro is an independent developer. NativePHP, Laravel, Apple, Google, Firebase and other names are trademarks of their respective owners; this package is not affiliated with or endorsed by them. iOS and Apple are trademarks of Apple Inc. Android, Google Play and Firebase are trademarks of Google LLC.

Photo Kit is a free plugin from vipertecpro.com, home of the paid plugins for NativePHP Mobile: Rich-Text Editor, Onboarding & Tours, Health Data and Native Charts.
