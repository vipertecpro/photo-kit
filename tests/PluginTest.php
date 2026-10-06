<?php

/**
 * Plugin structure tests for Photo Kit — manifest, native files, PHP classes.
 *
 * Run with: ./vendor/bin/pest
 */
beforeEach(function () {
    $this->pluginPath = dirname(__DIR__);
    $this->manifestPath = $this->pluginPath.'/nativephp.json';
    $this->manifest = json_decode(file_get_contents($this->manifestPath), true);
});

describe('Plugin Manifest', function () {
    it('has a valid nativephp.json file', function () {
        expect(file_exists($this->manifestPath))->toBeTrue();
        expect(json_last_error())->toBe(JSON_ERROR_NONE);
    });

    it('has required fields', function () {
        expect($this->manifest)->toHaveKeys(['name', 'namespace', 'bridge_functions', 'version']);
        expect($this->manifest['name'])->toBe('vipertecpro/photo-kit');
        expect($this->manifest['namespace'])->toBe('PhotoKit');
        expect($this->manifest['version'])->toBe('1.0.0');
    });

    it('has valid bridge functions', function () {
        expect($this->manifest['bridge_functions'])->toBeArray();

        foreach ($this->manifest['bridge_functions'] as $function) {
            expect($function)->toHaveKeys(['name', 'android', 'ios', 'description']);
            expect($function['name'])->toStartWith('PhotoKit.');
        }
    });

    it('exposes the five Photo Kit bridge functions', function () {
        $names = array_column($this->manifest['bridge_functions'], 'name');

        expect($names)->toBe([
            'PhotoKit.Save',
            'PhotoKit.PermissionStatus',
            'PhotoKit.RequestPermission',
            'PhotoKit.Process',
            'PhotoKit.ReadExif',
        ]);
    });

    it('has marketplace metadata filled in', function () {
        expect($this->manifest['keywords'])->toBeArray()->not->toBeEmpty();
        expect($this->manifest['category'])->toBe('media');
        expect($this->manifest['pricing']['type'])->toBe('free');
        expect($this->manifest['platforms'])->toBe(['android', 'ios']);
        expect($this->manifest['icon'])->toBe('resources/icon.png');
        expect(file_exists($this->pluginPath.'/resources/icon.png'))->toBeTrue();
    });

    it('declares the photo-library usage strings for iOS and no Android runtime permission', function () {
        expect($this->manifest['ios']['info_plist'])->toHaveKeys([
            'NSPhotoLibraryAddUsageDescription',
            'NSPhotoLibraryUsageDescription',
        ]);
        expect($this->manifest['android']['permissions'])->toBe([]);
        expect($this->manifest['android']['min_version'])->toBe(29);
    });

    it('declares the three events', function () {
        expect($this->manifest['events'])->toBe([
            'Vipertecpro\\PhotoKit\\Events\\PhotoSaved',
            'Vipertecpro\\PhotoKit\\Events\\PhotoSaveFailed',
            'Vipertecpro\\PhotoKit\\Events\\PhotoLibraryPermissionResult',
        ]);

        foreach ($this->manifest['events'] as $event) {
            expect(class_exists($event))->toBeTrue();
        }
    });
});

describe('Native Code', function () {
    it('has an Android Kotlin file with every bridge class', function () {
        $kotlinFile = $this->pluginPath.'/resources/android/PhotoKitFunctions.kt';

        expect(file_exists($kotlinFile))->toBeTrue();

        $content = file_get_contents($kotlinFile);
        expect($content)->toContain('package com.vipertecpro.plugins.photo_kit');
        expect($content)->toContain('object PhotoKitFunctions');
        expect($content)->toContain('BridgeFunction');

        foreach ($this->manifest['bridge_functions'] as $function) {
            $parts = explode('.', $function['android']);
            expect($content)->toContain('class '.end($parts));
        }
    });

    it('has an iOS Swift file with every bridge class', function () {
        $swiftFile = $this->pluginPath.'/resources/ios/PhotoKitFunctions.swift';

        expect(file_exists($swiftFile))->toBeTrue();

        $content = file_get_contents($swiftFile);
        expect($content)->toContain('enum PhotoKitFunctions');
        expect($content)->toContain('BridgeFunction');

        foreach ($this->manifest['bridge_functions'] as $function) {
            $parts = explode('.', $function['ios']);
            expect($content)->toContain('class '.end($parts));
        }
    });

    it('dispatches the declared events from both platforms', function () {
        $swift = file_get_contents($this->pluginPath.'/resources/ios/PhotoKitFunctions.swift');
        $kotlin = file_get_contents($this->pluginPath.'/resources/android/PhotoKitFunctions.kt');

        foreach ($this->manifest['events'] as $event) {
            $escaped = str_replace('\\', '\\\\', $event);
            expect($swift)->toContain($escaped);
            expect($kotlin)->toContain($escaped);
        }
    });

    it('uses the platform photo library APIs and bakes the EXIF orientation', function () {
        $swift = file_get_contents($this->pluginPath.'/resources/ios/PhotoKitFunctions.swift');
        $kotlin = file_get_contents($this->pluginPath.'/resources/android/PhotoKitFunctions.kt');

        // Saving goes through the system photo library, not a plain file copy.
        expect($swift)->toContain('PHPhotoLibrary')->and($swift)->toContain('creationRequestForAssetFromImage');
        expect($kotlin)->toContain('MediaStore')->and($kotlin)->toContain('IS_PENDING');

        // Permission is add-only by default, full access only for albums.
        expect($swift)->toContain('.addOnly')->and($swift)->toContain('.readWrite');

        // Orientation is applied to the pixels on both platforms.
        expect($swift)->toContain('kCGImageSourceCreateThumbnailWithTransform');
        expect($kotlin)->toContain('ORIENTATION_TRANSVERSE')->and($kotlin)->toContain('postRotate');
    });
});

describe('PHP Classes', function () {
    it('has service provider', function () {
        $file = $this->pluginPath.'/src/PhotoKitServiceProvider.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Vipertecpro\PhotoKit');
        expect($content)->toContain('class PhotoKitServiceProvider');
    });

    it('has facade', function () {
        $file = $this->pluginPath.'/src/Facades/PhotoKit.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Vipertecpro\PhotoKit\Facades');
        expect($content)->toContain('class PhotoKit extends Facade');

        foreach (['save(', 'saveImage(', 'saveVideo(', 'permissionStatus(', 'requestPermission(', 'process(', 'compress(', 'resize(', 'fixOrientation(', 'exif('] as $method) {
            expect($content)->toContain($method);
        }
    });

    it('has main implementation class', function () {
        $file = $this->pluginPath.'/src/PhotoKit.php';
        expect(file_exists($file))->toBeTrue();

        $content = file_get_contents($file);
        expect($content)->toContain('namespace Vipertecpro\PhotoKit');
        expect($content)->toContain('class PhotoKit');
    });
});

describe('Composer Configuration', function () {
    it('has valid composer.json', function () {
        $composerPath = $this->pluginPath.'/composer.json';
        expect(file_exists($composerPath))->toBeTrue();

        $composer = json_decode(file_get_contents($composerPath), true);

        expect(json_last_error())->toBe(JSON_ERROR_NONE);
        expect($composer['name'])->toBe('vipertecpro/photo-kit');
        expect($composer['type'])->toBe('nativephp-plugin');
        expect($composer['license'])->toBe('MIT');
        expect($composer['extra']['nativephp']['manifest'])->toBe('nativephp.json');
        expect($composer['extra']['laravel']['providers'])->toBe(['Vipertecpro\\PhotoKit\\PhotoKitServiceProvider']);
    });
});

describe('Lifecycle Hooks', function () {
    it('has valid hooks configuration', function () {
        $validHooks = ['pre_compile', 'post_compile', 'copy_assets', 'post_build'];

        foreach (array_keys($this->manifest['hooks']) as $hook) {
            expect($hook)->toBeIn($validHooks);
        }
    });

    it('has a copy_assets hook command with the manifest signature', function () {
        $expectedSignature = $this->manifest['hooks']['copy_assets'];
        $commandFile = $this->pluginPath.'/src/Commands/CopyAssetsCommand.php';

        expect(file_exists($commandFile))->toBeTrue();

        $content = file_get_contents($commandFile);
        expect($content)->toContain('extends NativePluginHookCommand');
        expect($content)->toContain('use Native\Mobile\Plugins\Commands\NativePluginHookCommand');
        expect($content)->toContain('$signature = \''.$expectedSignature.'\'');
        expect($content)->toContain('$this->isAndroid()');
        expect($content)->toContain('$this->isIos()');
    });
});

describe('Documentation', function () {
    it('ships the product files', function () {
        foreach (['README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'RELEASING.md', 'LICENSE', 'resources/boost/guidelines/core.blade.php'] as $file) {
            expect(file_exists($this->pluginPath.'/'.$file))->toBeTrue("missing {$file}");
        }
    });

    it('keeps the README free of links', function () {
        $readme = file_get_contents($this->pluginPath.'/README.md');

        expect($readme)->not->toMatch('/\]\(/');          // no markdown links or images
        expect($readme)->not->toMatch('/https?:\/\//');   // no raw URLs
        expect($readme)->not->toContain('<a ');
    });

    it('states that the package is independent of the brands it names', function () {
        $readme = file_get_contents($this->pluginPath.'/README.md');

        expect($readme)->toContain('vipertecpro is an independent developer.')
            ->toContain('this package is not affiliated with or endorsed by them.')
            ->not->toMatch('/\\b(official|certified|partner)\\b/i');
    });

    it('lists the 1.0.0 release in the changelog', function () {
        expect(file_get_contents($this->pluginPath.'/CHANGELOG.md'))->toContain('## [1.0.0]');
    });
});
