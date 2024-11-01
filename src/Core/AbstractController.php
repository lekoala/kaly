<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Http\ServerRequest;

abstract class AbstractController
{
    protected ServerRequest $request;
    protected App $app;

    public function __construct(ServerRequest $request, App $app)
    {
        $this->request = $request;
        $this->app = $app;
    }
}
