<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Kaly\Core\AbstractController;
use Kaly\Http\JsonResult;
use Kaly\View\View;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class DispatcherController extends AbstractController
{
    public function stringResult(): string
    {
        return 'hello';
    }

    /**
     * @return array<string,int>
     */
    public function arrayResult(): array
    {
        return ['a' => 1];
    }

    public function nullResult(): void {}

    public function viewResult(): View
    {
        return new View('template', ['title' => 'Test']);
    }

    public function rawResult(): ResponseInterface
    {
        return new Response(201, ['X-Raw' => 'yes'], 'raw');
    }

    /**
     * @return array<string,mixed>
     */
    public function routeResult(): array
    {
        $route = $this->ctx()->route();
        return ['route' => [
            'controller' => $route->controller,
            'action' => $route->action,
            'name' => $route->name,
            'module' => $route->module,
            'locale' => $route->locale,
            'params' => $route->params,
            'middlewares' => $route->middlewares,
        ]];
    }

    /**
     * @return array<string,mixed>
     */
    public function localeResult(): array
    {
        return ['locale' => $this->ctx()->locale()];
    }

    public function viewResultWithI18n(): View
    {
        // i18n is reserved: the dispatcher always overrides it
        return new View('template', ['i18n' => 'should be overridden']);
    }

    public function viewResultWithUrl(): View
    {
        // url is reserved: the dispatcher always overrides it
        return new View('template', ['url' => 'should be overridden']);
    }

    public function jsonResponseResult(): JsonResult
    {
        return JsonResult::of(['a' => 1], 201, ['X-Test' => 'yes']);
    }

    public function viewResultWithStatus(): View
    {
        return View::of('template', ['title' => 'Test'])->withStatus(404);
    }

    public function redirectResult(): never
    {
        $this->redirectToRoute('shop:product', ['slug' => 'velo']);
    }
}
