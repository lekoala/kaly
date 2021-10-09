<?php

declare(strict_types=1);

namespace Kaly\Interfaces;

interface RouteInterface
{
    public const MODULE = "module";
    public const NAMESPACE = "namespace";
    public const CONTROLLER = "controller";
    public const ACTION = "action";
    public const PARAMS = "params";
    public const LOCALE = "locale";
    public const SEGMENTS = "segments";
    public const TEMPLATE = "template";
}
