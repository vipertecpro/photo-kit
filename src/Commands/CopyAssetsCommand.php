<?php

namespace Vipertecpro\PhotoKit\Commands;

use Native\Mobile\Plugins\Commands\NativePluginHookCommand;

/**
 * Copy assets hook command for the Photo Kit plugin.
 *
 * Runs during the copy_assets phase of the build. Photo Kit ships no binary
 * assets today — the hook is kept so the plugin follows the standard
 * lifecycle and a future release can add files without changing the manifest.
 *
 * @see NativePluginHookCommand
 */
class CopyAssetsCommand extends NativePluginHookCommand
{
    protected $signature = 'nativephp:photo-kit:copy-assets';

    protected $description = 'Copy assets for the Photo Kit plugin';

    public function handle(): int
    {
        if ($this->isAndroid()) {
            $this->copyAndroidAssets();
        }

        if ($this->isIos()) {
            $this->copyIosAssets();
        }

        return self::SUCCESS;
    }

    protected function copyAndroidAssets(): void
    {
        $this->info('Android assets copied for PhotoKit');
    }

    protected function copyIosAssets(): void
    {
        $this->info('iOS assets copied for PhotoKit');
    }
}
