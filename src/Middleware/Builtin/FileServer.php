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
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $app = App::inst();
        $path = $request->getUri()->getPath();
        $filename = Fs::toDir($app->getPublicDir(), $path);
        if (!is_file($filename)) {
            return $handler->handle($request);
        }
        $contents = Fs::getFile($filename);
        $contentType = Fs::contentType($filename);
        return $app->respond($contents, 200)->withHeader('Content-Type', $contentType);
    }
}
