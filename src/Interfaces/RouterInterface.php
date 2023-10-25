<?php

declare(strict_types=1);

namespace Kaly\Interfaces;

use Exception;
use Kaly\Exceptions\NotFoundException;
use Kaly\Exceptions\RedirectException;
use Kaly\Exceptions\ValidationException;
use Kaly\Exceptions\AuthenticationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Routers should implement a simple "match" function
 * that should return something that is processable
 * by our app
 */
interface RouterInterface
{
    public const MODULE = "module";
    public const NAMESPACE = "namespace";
    public const CONTROLLER = "controller";
    public const ACTION = "action";
    public const PARAMS = "params";
    public const LOCALE = "locale";
    public const SEGMENTS = "segments";
    public const TEMPLATE = "template";
    public const FALLBACK_ACTION = "__invoke";

    /**
     * @throws RedirectException Will be converted to 3xx redirect
     * @throws NotFoundException Will be converted to 404 error
     * @throws Exception Will be converted to 500 error
     * @return array<string, string>
     */
    public function match(ServerRequestInterface $request): array;

    /**
     * @param string|array<mixed> $handler
     * @param array<string, mixed> $params
     * @return string
     */
    public function generate($handler, array $params = []): string;
}
