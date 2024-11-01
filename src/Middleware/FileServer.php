<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Kaly\Util\Fs;
use Kaly\Core\App;

class FileServer implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $app = App::inst();
        $path = $request->getUri()->getPath();
        $filename = Fs::toDir($app->getPublicDir(), $path);
        if (!is_file($filename)) {
            throw new SkipMiddlewareException();
        }
        $contents = Fs::getFile($filename);
        $contentType = Fs::contentType($filename);
        return $app->respond($contents, 200);
    }
}
