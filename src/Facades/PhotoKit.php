<?php

namespace Vipertecpro\PhotoKit\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void save(string $path, array $options = [])
 * @method static void saveImage(string $path, array $options = [])
 * @method static void saveVideo(string $path, array $options = [])
 * @method static string permissionStatus(bool $forAlbums = false)
 * @method static void requestPermission(bool $forAlbums = false)
 * @method static array process(string $path, array $options = [])
 * @method static array compress(string $path, int $quality = 75, array $options = [])
 * @method static array resize(string $path, int $maxWidth, int $maxHeight = 0, array $options = [])
 * @method static array fixOrientation(string $path, array $options = [])
 * @method static array exif(string $path)
 * @method static string detectType(string $path)
 *
 * @see \Vipertecpro\PhotoKit\PhotoKit
 */
class PhotoKit extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Vipertecpro\PhotoKit\PhotoKit::class;
    }
}
