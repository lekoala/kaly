<?php

declare(strict_types=1);

namespace Kaly;

use Throwable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class ErrorHandler implements MiddlewareInterface
{
    protected App $app;
    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $ex) {
            if ($this->app->getDi()->has(LoggerInterface::class)) {
                /** @var LoggerInterface $logger  */
                $logger = $this->app->getDi()->get(LoggerInterface::class);
                $logger->error($ex->getMessage());
            }

            $code = 500;
            $body = 'Server error';
            if ($this->app->getDebug()) {
                $line = $ex->getLine();
                $file = $ex->getFile();
                $type = get_class($ex);
                $message = $ex->getMessage();
                $trace = $ex->getTraceAsString();
                if (in_array(\PHP_SAPI, ['cli', 'phpdbg'])) {
                    $body = "$type in $file:$line\n---\n$message\n---\n$trace";
                } else {
                    $idePlaceholder = $_ENV['DUMP_IDE_PLACEHOLDER'] ?? 'vscode://file/{file}:{line}:0';
                    $ideLink = str_replace(['{file}', '{line}'], [$file, $line], $idePlaceholder);
                    $body = "<pre>$type in <a href=\"$ideLink\">$file:$line</a>";
                    $body .= "<h1>$message</h1>Trace:<br/>$trace</pre>";
                }
            }

            return $this->app->prepareResponse($request, [], $body, $code);
        }
    }
}
