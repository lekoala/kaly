<?php

declare(strict_types=1);

namespace Kaly\View;

use Kaly\Core\App;
use Kaly\Util\Fs;

class AssetsPublisher
{
    /**
     * Add this to your composer scripts
     *
     * "modules-publish": [
     *   "Kaly\\ViewBridge::composerPublishModulesAssets"
     * ]
     *
     * @param \Composer\Script\Event $event
     */
    public static function composerPublishModulesAssets($event): void
    {
        // @phpstan-ignore-next-line
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
        require $vendorDir . '/autoload.php';

        $baseDir = dirname((string) $vendorDir);
        $app = new App($baseDir, false);
        self::publishModulesAssets($app);
    }

    /**
     * @return array<string,int>
     */
    public static function publishModulesAssets(App $app): array
    {
        $modules = $app->getModules();
        $result = [];
        foreach ($modules as $module) {
            $moduleName = $module->getName();
            $clientDir = $app->getClientModuleDir($moduleName);
            if (!is_dir($clientDir)) {
                $result[$moduleName] = 0;
                continue;
            }
            $publicResourceDir = $app->getResourcesDir();

            // Copy all assets
            $files = Fs::glob($clientDir . '/*');
            $i = 0;
            foreach ($files as $file) {
                $baseFile = str_replace($clientDir, '', $file);
                $destFile = $publicResourceDir . DIRECTORY_SEPARATOR . $baseFile;
                $destFileDir = dirname($destFile);
                if (!is_dir($destFileDir)) {
                    mkdir($destFileDir, 0755, true);
                }
                copy($file, $destFile);
                $i++;
            }
            $result[$moduleName] = $i;
        }
        return $result;
    }
}
