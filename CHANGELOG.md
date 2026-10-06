# Changelog

All notable changes to `vipertecpro/photo-kit` are documented here.
The format is based on Keep a Changelog, and this project adheres to
Semantic Versioning.

## [1.0.0] - 2026-10-06

First release. Verified on the iOS Simulator (iPhone 17 Pro, iOS 26.5) and an
Android emulator (Pixel 9, API 36) against NativePHP Mobile 4.6.

### Added
- **Save to the photo library** — `PhotoKit::save()`, `saveImage()`,
  `saveVideo()` write a local image or video into Photos (iOS, via
  `PHPhotoLibrary`) or MediaStore (Android, `Pictures/` or `Movies/`),
  optionally into a named album. The outcome arrives as a `PhotoSaved` or
  `PhotoSaveFailed` event with a machine-readable reason.
- **Permission handling** — `permissionStatus()` and `requestPermission()`.
  iOS asks for add-only access by default and full access only when an album
  is requested; Android 10+ needs no runtime permission and reports
  `notRequired`.
- **Compress / resize / fix orientation** — `process()` plus the `compress()`,
  `resize()` and `fixOrientation()` shortcuts re-encode an image natively
  (ImageIO on iOS, BitmapFactory + Matrix on Android) to JPEG, PNG, WebP
  (Android) or HEIC (iOS), bounded to a box without upscaling, with the EXIF
  orientation always baked into the pixels. Metadata is stripped by default
  and kept (orientation reset to 1) with `keepMetadata`.
- **EXIF reader** — `exif()` returns dimensions, display dimensions,
  orientation (with label and `needsRotation`), MIME type, byte size, camera
  make/model/software, capture date, exposure, aperture, ISO, focal length,
  lens and GPS position, with the same keys on both platforms.
- Files without an extension (common for picker copies on Android) are
  recognised from their signature bytes and saved with the right type.
- Validation before the bridge: missing files, remote URLs, files that are
  not images or videos, bad formats or quality values throw `InvalidArgumentException`;
  native errors on synchronous calls throw `PhotoKitException`.
- A JS bridge for legacy web-view apps and Laravel Boost guidelines.

### Notes
- Zero third-party native dependencies.
- Local files only; download remote media first.
