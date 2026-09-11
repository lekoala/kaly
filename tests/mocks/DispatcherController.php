<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Kaly\Core\AbstractController;
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
        return ['route' => $this->request->getAttribute('route')];
    }

    /**
     * @return array<string,mixed>
     */
    public function localeResult(): array
    {
        return ['locale' => $this->request->getAttribute('locale')];
    }

    public function viewResultWithI18n(): View
    {
        // i18n is reserved: the dispatcher always overrides it
        return new View('template', ['i18n' => 'should be overridden']);
    }
}
