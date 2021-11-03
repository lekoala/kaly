<?php

declare(strict_types=1);

namespace LangModule\Controller;

use Kaly\App;
use Psr\Http\Message\ServerRequestInterface;

class IndexController
{
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function getlang(ServerRequestInterface $request)
    {
        return $request->getAttribute(App::ATTR_LOCALE_REQUEST);
    }
}
