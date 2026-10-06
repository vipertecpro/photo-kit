/**
 * PhotoKit Plugin for NativePHP Mobile — JavaScript bridge (legacy web-view apps).
 *
 * NOTE: the primary consumer in v4 is a SuperNative `NativeComponent` calling
 * the PHP facade (`PhotoKit::save(...)`, `PhotoKit::compress(...)`) and handling
 * the `PhotoSaved` / `PhotoSaveFailed` events with `#[On]`. This JS wrapper is
 * provided for Livewire/Inertia web-view screens. Saving is asynchronous (the
 * result is delivered as a native event), so subscribe with the `#nativephp`
 * `On()` helper; `process()` and `exif()` return their result directly.
 *
 * @example
 *   import { photoKit } from '@vipertecpro/photo-kit';
 *   import { On } from '#nativephp';
 *
 *   On('native:Vipertecpro\\PhotoKit\\Events\\PhotoSaved', ({ path, uri }) => {
 *       // saved to the photo library
 *   });
 *
 *   await photoKit.save('/path/to/photo.jpg', { album: 'My App' });
 *   const small = await photoKit.process('/path/to/photo.jpg', { maxWidth: 1600, quality: 75 });
 *   const meta = await photoKit.exif('/path/to/photo.jpg');
 */

const baseUrl = '/_native/api/call';

/**
 * Internal bridge call function.
 * @private
 */
async function bridgeCall(method, params = {}) {
    const response = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
        },
        body: JSON.stringify({ method, params })
    });

    const result = await response.json();

    if (result.status === 'error') {
        throw new Error(result.message || 'Native call failed');
    }

    return result.data ?? result;
}

/**
 * Save a local image or video to the photo library. The result arrives as the
 * `PhotoSaved` / `PhotoSaveFailed` native event.
 *
 * @param {string} path - Absolute path to a local image or video file.
 * @param {Object} [options]
 * @param {string} [options.type] - 'image' or 'video' (default: by extension).
 * @param {string} [options.album] - Album / folder name.
 * @param {string} [options.id] - Correlation id echoed back on the event.
 * @returns {Promise<void>}
 */
export async function save(path, options = {}) {
    const extension = (path.split('.').pop() || '').toLowerCase();
    const videos = ['mp4', 'mov', 'm4v', '3gp', 'webm', 'mkv', 'avi'];

    return bridgeCall('PhotoKit.Save', {
        path,
        type: options.type ?? (videos.includes(extension) ? 'video' : 'image'),
        album: options.album ?? null,
        id: options.id ?? null
    });
}

/**
 * Current photo-library permission status (no prompt).
 * @param {boolean} [forAlbums=false] - Check full access instead of add-only access.
 * @returns {Promise<string>} granted | limited | denied | restricted | notDetermined | notRequired
 */
export async function permissionStatus(forAlbums = false) {
    const result = await bridgeCall('PhotoKit.PermissionStatus', { level: forAlbums ? 'readWrite' : 'add' });
    return result?.status ?? 'unknown';
}

/**
 * Show the permission prompt; the outcome arrives as the
 * `PhotoLibraryPermissionResult` native event.
 * @param {boolean} [forAlbums=false]
 * @returns {Promise<void>}
 */
export async function requestPermission(forAlbums = false) {
    return bridgeCall('PhotoKit.RequestPermission', { level: forAlbums ? 'readWrite' : 'add' });
}

/**
 * Resize / compress / re-encode an image. The output is always upright (EXIF
 * orientation baked in) and is a NEW file.
 *
 * @param {string} path - Absolute path to a local image.
 * @param {Object} [options]
 * @param {number} [options.maxWidth=0] - Bounding box width in px (0 = unlimited).
 * @param {number} [options.maxHeight=0] - Bounding box height in px (0 = unlimited).
 * @param {number} [options.quality=85] - 1–100 for lossy formats.
 * @param {string} [options.format='jpeg'] - jpeg | png | webp (Android) | heic (iOS).
 * @param {string} [options.output] - Absolute output path (default: temp file).
 * @param {boolean} [options.keepMetadata=false] - Keep camera/date/GPS tags.
 * @returns {Promise<{path: string, width: number, height: number, bytes: number, format: string, originalWidth: number, originalHeight: number, originalBytes: number, orientation: number}>}
 */
export async function process(path, options = {}) {
    return bridgeCall('PhotoKit.Process', {
        path,
        maxWidth: options.maxWidth ?? 0,
        maxHeight: options.maxHeight ?? 0,
        quality: options.quality ?? 85,
        format: options.format ?? 'jpeg',
        output: options.output ?? null,
        keepMetadata: options.keepMetadata ?? false
    });
}

/**
 * Read an image's dimensions, EXIF orientation, camera, date and GPS tags.
 * @param {string} path - Absolute path to a local image.
 * @returns {Promise<Object>}
 */
export async function exif(path) {
    return bridgeCall('PhotoKit.ReadExif', { path });
}

/**
 * PhotoKit namespace object.
 */
export const photoKit = { save, permissionStatus, requestPermission, process, exif };

export default photoKit;
