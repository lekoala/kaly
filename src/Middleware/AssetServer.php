<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use InvalidArgumentException;
use Kaly\Asset\Assets;
use Kaly\Asset\AssetSources;
use Kaly\Http\FileResponseFactory;
use Kaly\Http\PublicFilePolicy;
use Kaly\Util\Fs;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves asset sources in development, sibling of FileServer.
 *
 * ```text
 * /_assets/app/app.js     -> <base>/assets/app.js
 * /_assets/admin/a.css    -> <base>/modules/Admin/assets/a.css
 * ```
 *
 * GET/HEAD only, directory jail, forbidden extensions, `Cache-Control:
 * no-store`. Anything else falls through to the next handler. The
 * application opts in explicitly, typically in debug mode:
 *
 * ```php
 * if ($app->isDebug()) {
 *     $app->middleware()->incoming(AssetServer::class);
 * }
 * ```
 */
final class AssetServer implements MiddlewareInterface
{
    public function __construct(
        private AssetSources $sources,
        private FileResponseFactory $files,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $path = $request->getUri()->getPath();
        if (!str_starts_with($path, Assets::DEV_PREFIX)) {
            return $handler->handle($request);
        }

        $remainder = substr($path, strlen(Assets::DEV_PREFIX));
        $slash = strpos($remainder, '/');
        if ($slash === false || $slash === 0 || $slash === (strlen($remainder) - 1)) {
            return $handler->handle($request);
        }

        $namespace = substr($remainder, 0, $slash);
        $relative = rawurldecode(substr($remainder, $slash + 1));

        try {
            $root = $this->sources->get($namespace);
            Assets::assertValidPath($relative);
        } catch (InvalidArgumentException) {
            return $handler->handle($request);
        }

        $filename = Fs::toDir($root, str_replace('/', DIRECTORY_SEPARATOR, $relative));

        if (is_link($filename) || !Fs::isInside($root, $filename) || !is_file($filename)) {
            return $handler->handle($request);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, PublicFilePolicy::FORBIDDEN_EXTENSIONS, true)) {
            return $handler->handle($request);
        }

        try {
            $response = $this->files->create($filename, method: $request->getMethod());
        } catch (InvalidArgumentException) {
            return $handler->handle($request);
        }

        return $response->withHeader('Cache-Control', 'no-store');
    }
}
