<?php

declare(strict_types=1);

namespace Kaly\Middleware\Builtin;

use Kaly\Core\App;
use Kaly\Util\Fs;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class FileServer implements MiddlewareInterface
{
    public function __construct(
        protected App $app,
    ) {}

    /**
     * Extensions that must never be served as static files.
     */
    protected const FORBIDDEN_EXTENSIONS = [
        'php',
        'phtml',
        'phar',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'pht',
        'inc',
        'cgi',
        'pl',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Only serve plain static files
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $handler->handle($request);
        }

        $app = $this->app;
        $publicDir = $app->getPublicDir();
        $path = $request->getUri()->getPath();
        $filename = Fs::toDir($publicDir, $path);

        // Reject path traversal, symlink escapes and non regular files
        if (!Fs::isInside($publicDir, $filename) || !is_file($filename)) {
            return $handler->handle($request);
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            return $handler->handle($request);
        }

        $contents = Fs::getFile($filename);
        $contentType = Fs::contentType($filename);
        $body = $request->getMethod() === 'HEAD' ? '' : $contents;

        return $app
            ->respond($body, 200)
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Length', (string) strlen($contents));
    }
}
