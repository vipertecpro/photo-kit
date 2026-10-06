# Contributing

Thanks for helping improve **Photo Kit**. This is a NativePHP Mobile plugin
with a thin PHP layer and hand-written native code (Swift on iOS, Kotlin on
Android). Contributions of all kinds are welcome — bug reports, docs, and code.

## Getting set up

Work on the plugin alongside a NativePHP app by pointing Composer at your local
checkout:

```json
"repositories": [
    { "type": "path", "url": "../photo-kit" }
]
```

```bash
composer require vipertecpro/photo-kit:@dev
php artisan native:plugin:register vipertecpro/photo-kit
php artisan native:run ios       # or: android — recompiles the native code
```

## Running the tests

```bash
vendor/bin/pest
```

Please keep tests green and add coverage for behaviour you change. The PHP
tests cover validation, option normalisation and result decoding; native
behaviour is verified by hand on a simulator / emulator (see below).

## Project layout

```
src/PhotoKit.php                              PHP entry point — validation, bridge calls, decoding
src/Facades/PhotoKit.php                      the PhotoKit facade
src/Events/PhotoSaved.php                     save succeeded (path, type, uri, album, id)
src/Events/PhotoSaveFailed.php                save failed (path, type, reason, message, id)
src/Events/PhotoLibraryPermissionResult.php   permission prompt answered (status, level)
src/Exceptions/PhotoKitException.php          native error for a synchronous call
src/Commands/CopyAssetsCommand.php            copy_assets lifecycle hook (no assets today)
resources/ios/PhotoKitFunctions.swift         Photos + ImageIO implementation
resources/android/PhotoKitFunctions.kt        MediaStore + BitmapFactory/ExifInterface implementation
resources/js/photoKit.js                      JS bridge for legacy web-view apps
resources/boost/guidelines/core.blade.php     Laravel Boost / AI usage guidelines
nativephp.json                                plugin manifest: bridge_functions, Info.plist keys, events
```

## How it works

```
PHP  PhotoKit::save($path, $options)
  └─ nativephp_call("PhotoKit.Save", {...})                  ← fire-and-forget
        └─ Native  PhotoKitFunctions.Save.execute()
              ├─ iOS:     PHPhotoLibrary.requestAuthorization(.addOnly | .readWrite)
              │           → performChanges { creationRequestForAssetFromImage/Video }
              ├─ Android: ContentResolver.insert(MediaStore …, IS_PENDING=1) → copy → IS_PENDING=0
              └─ dispatch  PhotoSaved { path, type, uri, album }  (or PhotoSaveFailed)

PHP  PhotoKit::process($path, $options)                      ← synchronous
  └─ nativephp_call("PhotoKit.Process", {...}) → JSON → array
        ├─ iOS:     CGImageSourceCreateThumbnailAtIndex (transform + max pixel size)
        │           → CGImageDestination (jpeg / png / heic, quality, metadata)
        └─ Android: BitmapFactory (inSampleSize) → Matrix (scale + orientation)
                    → Bitmap.compress (jpeg / png / webp) → ExifInterface copy (optional)
```

Both platforms produce the same result keys and the same event payloads, so
app code never branches on the platform.

## Verifying native changes

1. Build the plugin into a demo app on the iOS Simulator and an Android
   emulator (`php artisan native:run ios` / `android`).
2. Save: confirm the permission prompt appears once on iOS, the file shows up
   in Photos / the Gallery, and `PhotoSaved` carries a `uri`.
3. Process: use an image whose EXIF orientation is not 1 and confirm the
   output renders upright with the expected size and smaller byte count.
4. EXIF: confirm `orientation`, `displayWidth` / `displayHeight` and the
   camera tags of a real photo.

## Submitting changes

1. Open an issue first for anything non-trivial, so we can agree on the approach.
2. Branch, make your change, keep `vendor/bin/pest` green.
3. Update `CHANGELOG.md` if the change is user-visible.
4. Open a pull request against `main` with a clear description and, for native
   changes, a note on which platform(s) you tested on.

## Releasing (maintainers)

See `RELEASING.md` for the tag-and-publish checklist.
