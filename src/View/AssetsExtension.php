<?php

declare(strict_types=1);

namespace Kaly\View;

use RuntimeException;

/**
 * @link https://github.com/thephpleague/plates/blob/v3/src/Extension/Asset.php
 * @link https://github.com/devanych/view-renderer/blob/master/src/Extension/AssetExtension.php
 */
class AssetsExtension implements ExtensionInterface
{
    /**
     * @var string root directory storing the published asset files.
     */
    protected string $basePath;

    /**
     * @var string base URL through which the published asset files can be accessed.
     */
    protected string $baseUrl;

    /**
     * @param string $basePath root directory storing the published asset files.
     * @param string $baseUrl base URL through which the published asset files can be accessed.
     * @param bool $appendTimestamp whether to append a timestamp to the URL of every published asset.
     */
    public function __construct(string $basePath, string $baseUrl = '', protected bool $appendTimestamp = false)
    {
        $this->basePath = rtrim($basePath, '\/');
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * {@inheritDoc}
     */
    public function getFunctions(): array
    {
        return [
            'asset' => $this->asset(...),
        ];
    }

    /**
     * Includes the asset file and appends a timestamp with the last modification of that file.
     *
     * @param string $file
     * @return string
     */
    public function asset(string $file): string
    {
        $url = $this->baseUrl . '/' . ltrim($file, '/');
        $path = $this->basePath . '/' . ltrim($file, '/');

        if (!is_file($path)) {
            throw new RuntimeException(sprintf(
                'Asset file "%s" does not exist',
                $path
            ));
        }
        if ($this->appendTimestamp) {
            return $url . '?v=' . filemtime($path);
        }
        return $url;
    }

    /*
    't' => function (string $message, array $parameters = [], string $domain = null) {
        return t($message, $parameters, $domain);
    },
    'set_base_domain' => function (string $domain) use ($app) {
        $translator = $app->getDi()->get(Translator::class);
        $translator->setBaseDomain($domain);
    },
    'asset' => function (string $file) use ($app) {
        $route = $app->getRequest()->getAttribute(App::ATTR_ROUTE_REQUEST);
        if (!$route || !is_array($route)) {
            throw new RuntimeException("Invalid route request attribute");
        }
        $module = $route[RouterInterface::MODULE] ?? '';
        $resourcesFolder = App::FOLDER_RESOURCES;

        // Copy on the fly requested assets
        if ($app->getDebug()) {
            $destFile = $app->getResourceDir($module, true) . DIRECTORY_SEPARATOR . $file;
            $sourceFile = $app->getClientModuleDir($module) . DIRECTORY_SEPARATOR . $file;
            if (is_file($sourceFile)) {
                $destFileDir = dirname($destFile);
                if (!is_dir($destFileDir)) {
                    mkdir($destFileDir, 0755, true);
                }
                // File is overwritten if it already exists
                copy($sourceFile, $destFile);
            }
        }

        return "/$resourcesFolder/$module/$file";
    }
    */
}
