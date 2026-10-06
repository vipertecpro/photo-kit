<?php

use Vipertecpro\PhotoKit\Events\PhotoLibraryPermissionResult;
use Vipertecpro\PhotoKit\Events\PhotoSaved;
use Vipertecpro\PhotoKit\Events\PhotoSaveFailed;
use Vipertecpro\PhotoKit\Exceptions\PhotoKitException;
use Vipertecpro\PhotoKit\PhotoKit;

/**
 * Behaviour of the PHP layer: validation before the bridge, option
 * normalisation, and result decoding. nativephp_call() does not exist in this
 * environment, so valid calls are silent no-ops and invalid ones throw.
 */
beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/photo-kit-tests-'.uniqid();
    mkdir($this->dir);
    $this->photo = $this->dir.'/photo.jpg';
    $this->video = $this->dir.'/clip.mp4';
    file_put_contents($this->photo, 'not really a jpeg');
    file_put_contents($this->video, 'not really a video');
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*') ?: []);
    rmdir($this->dir);
});

describe('type detection', function () {
    it('detects images and videos by extension', function () {
        $kit = new PhotoKit;

        expect($kit->detectType('/a/b.JPG'))->toBe('image')
            ->and($kit->detectType('/a/b.heic'))->toBe('image')
            ->and($kit->detectType('/a/b.webp'))->toBe('image')
            ->and($kit->detectType('/a/b.mov'))->toBe('video')
            ->and($kit->detectType('/a/b.mp4'))->toBe('video')
            ->and($kit->detectType('/a/b.pdf'))->toBe('unknown')
            ->and($kit->detectType('/a/noext'))->toBe('unknown');
    });

    it('identifies extension-less files from their signature bytes', function () {
        $kit = new PhotoKit;
        $write = function (string $name, string $bytes): string {
            file_put_contents($path = $this->dir.'/'.$name, $bytes.str_repeat("\0", 32));

            return $path;
        };

        $jpeg = $write('picked_jpeg', "\xFF\xD8\xFF\xE0\x00\x10JFIF");
        $png = $write('picked_png', "\x89PNG\r\n\x1A\n");
        $webp = $write('picked_webp', 'RIFF'."\x10\x00\x00\x00".'WEBPVP8 ');
        $heic = $write('picked_heic', "\x00\x00\x00\x18".'ftypheic');
        $mov = $write('picked_mov', "\x00\x00\x00\x14".'ftypqt  ');
        $mp4 = $write('picked_mp4', "\x00\x00\x00\x20".'ftypisom');
        $text = $write('notes', 'just some text, not media');

        expect($kit->detectType($jpeg))->toBe('image')
            ->and($kit->sniff($jpeg))->toBe(['type' => 'image', 'extension' => 'jpg', 'mime' => 'image/jpeg'])
            ->and($kit->sniff($png)['extension'])->toBe('png')
            ->and($kit->sniff($webp)['extension'])->toBe('webp')
            ->and($kit->sniff($heic))->toBe(['type' => 'image', 'extension' => 'heic', 'mime' => 'image/heic'])
            ->and($kit->detectType($mov))->toBe('video')
            ->and($kit->sniff($mp4)['extension'])->toBe('mp4')
            ->and($kit->detectType($text))->toBe('unknown')
            ->and($kit->sniff($this->dir.'/missing'))->toBeNull();
    });

    it('sends the real extension for extension-less files', function () {
        $extensionFor = new ReflectionMethod(PhotoKit::class, 'extensionFor');
        file_put_contents($picked = $this->dir.'/gallery_selected_1', "\xFF\xD8\xFF\xE1".str_repeat("\0", 32));

        expect($extensionFor->invoke(new PhotoKit, $picked))->toBe('jpg')
            ->and($extensionFor->invoke(new PhotoKit, $this->photo))->toBe('jpg')
            ->and($extensionFor->invoke(new PhotoKit, $this->video))->toBe('mp4');

        (new PhotoKit)->save($picked);
    });

    it('lists only lowercase extensions', function () {
        foreach ([...PhotoKit::IMAGE_EXTENSIONS, ...PhotoKit::VIDEO_EXTENSIONS] as $ext) {
            expect($ext)->toBe(strtolower($ext));
        }
    });
});

describe('save()', function () {
    it('accepts an existing image or video file', function () {
        (new PhotoKit)->save($this->photo);
        (new PhotoKit)->save($this->video);
        (new PhotoKit)->saveImage($this->photo, ['album' => 'Tests']);
        (new PhotoKit)->saveVideo($this->video, ['id' => 'v1']);
        expect(true)->toBeTrue();
    });

    it('rejects a missing file', function () {
        expect(fn () => (new PhotoKit)->save($this->dir.'/missing.jpg'))
            ->toThrow(InvalidArgumentException::class, 'file not found');
    });

    it('rejects remote URLs with a hint', function () {
        expect(fn () => (new PhotoKit)->save('https://example.com/photo.jpg'))
            ->toThrow(InvalidArgumentException::class, 'local files only');
    });

    it('rejects unknown extensions', function () {
        $pdf = $this->dir.'/doc.pdf';
        file_put_contents($pdf, 'pdf');

        expect(fn () => (new PhotoKit)->save($pdf))
            ->toThrow(InvalidArgumentException::class, 'does not recognise');
    });

    it('rejects an invalid forced type', function () {
        expect(fn () => (new PhotoKit)->save($this->photo, ['type' => 'audio']))
            ->toThrow(InvalidArgumentException::class, 'images and videos only');
    });

    it('cleans album names into safe folder names', function () {
        $clean = new ReflectionMethod(PhotoKit::class, 'cleanAlbum');

        expect($clean->invoke(new PhotoKit, 'My / App: Shots?'))->toBe('My App Shots')
            ->and($clean->invoke(new PhotoKit, '   '))->toBeNull()
            ->and($clean->invoke(new PhotoKit, null))->toBeNull()
            ->and(mb_strlen($clean->invoke(new PhotoKit, str_repeat('a', 100))))->toBe(60);
    });
});

describe('process options', function () {
    it('fills sane defaults', function () {
        $resolve = new ReflectionMethod(PhotoKit::class, 'resolveProcessOptions');

        expect($resolve->invoke(new PhotoKit, $this->photo, []))->toBe([
            'path' => $this->photo,
            'maxWidth' => 0,
            'maxHeight' => 0,
            'quality' => 85,
            'format' => 'jpeg',
            'output' => null,
            'keepMetadata' => false,
        ]);
    });

    it('normalises jpg to jpeg and clamps negative sizes to zero', function () {
        $resolve = new ReflectionMethod(PhotoKit::class, 'resolveProcessOptions');
        $config = $resolve->invoke(new PhotoKit, $this->photo, ['format' => 'JPG', 'maxWidth' => -5, 'maxHeight' => 300, 'keepMetadata' => 1]);

        expect($config['format'])->toBe('jpeg')
            ->and($config['maxWidth'])->toBe(0)
            ->and($config['maxHeight'])->toBe(300)
            ->and($config['keepMetadata'])->toBeTrue();
    });

    it('rejects unsupported formats and bad quality', function () {
        expect(fn () => (new PhotoKit)->process($this->photo, ['format' => 'gif']))
            ->toThrow(InvalidArgumentException::class, 'supported formats are');
        expect(fn () => (new PhotoKit)->process($this->photo, ['quality' => 0]))
            ->toThrow(InvalidArgumentException::class, 'between 1 and 100');
        expect(fn () => (new PhotoKit)->process($this->photo, ['quality' => 101]))
            ->toThrow(InvalidArgumentException::class, 'between 1 and 100');
    });

    it('rejects an output equal to the source', function () {
        expect(fn () => (new PhotoKit)->process($this->photo, ['output' => $this->photo]))
            ->toThrow(InvalidArgumentException::class, 'different from the source');
    });

    it('refuses to process a video', function () {
        expect(fn () => (new PhotoKit)->process($this->video))
            ->toThrow(InvalidArgumentException::class, 'works on images');
    });

    it('returns an empty array outside a native app', function () {
        $kit = new PhotoKit;

        expect($kit->process($this->photo))->toBe([])
            ->and($kit->compress($this->photo, 60))->toBe([])
            ->and($kit->resize($this->photo, 800))->toBe([])
            ->and($kit->fixOrientation($this->photo))->toBe([])
            ->and($kit->exif($this->photo))->toBe([])
            ->and($kit->permissionStatus())->toBe(PhotoKit::STATUS_UNKNOWN);
    });

    it('builds the shortcut calls on top of process()', function () {
        $kit = new class extends PhotoKit
        {
            public array $seen = [];

            public function process(string $path, array $options = []): array
            {
                $this->seen = $options;

                return [];
            }
        };

        $kit->compress($this->photo, 60, ['maxWidth' => 1200]);
        expect($kit->seen)->toBe(['quality' => 60, 'maxWidth' => 1200]);

        $kit->resize($this->photo, 400, 300);
        expect($kit->seen)->toBe(['maxWidth' => 400, 'maxHeight' => 300]);

        $kit->fixOrientation($this->photo, ['format' => 'png']);
        expect($kit->seen)->toBe(['quality' => 95, 'keepMetadata' => true, 'format' => 'png']);
    });
});

describe('result decoding', function () {
    it('turns a native error response into a PhotoKitException', function () {
        $call = new ReflectionMethod(PhotoKit::class, 'call');
        $kit = new PhotoKit;

        // Simulate the bridge by defining the polyfill only if absent.
        if (! function_exists('nativephp_call')) {
            eval('function nativephp_call(string $method, string $params = "{}"): ?string { return $GLOBALS["__pk_response"] ?? null; }');
        }

        $GLOBALS['__pk_response'] = json_encode(['status' => 'error', 'code' => 'INVALID_PARAMETERS', 'message' => 'WebP output is not supported on iOS']);
        try {
            $call->invoke($kit, 'PhotoKit.Process', []);
            expect(false)->toBeTrue('expected an exception');
        } catch (PhotoKitException $e) {
            expect($e->getMessage())->toContain('WebP')->and($e->errorCode)->toBe('INVALID_PARAMETERS');
        }

        $GLOBALS['__pk_response'] = json_encode(['status' => 'granted', 'level' => 'add']);
        expect($kit->permissionStatus())->toBe('granted');

        $GLOBALS['__pk_response'] = json_encode(['orientation' => 6, 'width' => 4000, 'height' => 3000]);
        $exif = $kit->exif($this->photo);
        expect($exif['orientation'])->toBe(6)
            ->and($exif['orientationLabel'])->toBe('rotate-90')
            ->and($exif['needsRotation'])->toBeTrue();

        $GLOBALS['__pk_response'] = json_encode(['orientation' => 99]);
        expect($kit->exif($this->photo)['orientation'])->toBe(1);

        $GLOBALS['__pk_response'] = null;
        expect($kit->exif($this->photo))->toBe([]);
    });
});

describe('events', function () {
    it('carry the native payload by name', function () {
        $saved = new PhotoSaved(path: '/a.jpg', type: 'image', uri: 'content://x', album: 'App', id: 'one');
        expect($saved->uri)->toBe('content://x')->and($saved->album)->toBe('App')->and($saved->id)->toBe('one');

        $failed = new PhotoSaveFailed(path: '/a.jpg', type: 'video', reason: PhotoSaveFailed::PERMISSION_DENIED, message: 'denied');
        expect($failed->reason)->toBe('permission_denied')->and($failed->id)->toBeNull();

        $permission = new PhotoLibraryPermissionResult(status: 'limited');
        expect($permission->status)->toBe('limited')->and($permission->level)->toBe('add');
    });

    it('document every orientation value', function () {
        expect(array_keys(PhotoKit::ORIENTATIONS))->toBe(range(1, 8));
    });
});
