<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;
use Kaly\Http\Exception\ForbiddenException;
use Kaly\Http\Exception\RedirectException;

class StateController extends AbstractController
{
    public function cookie(): string
    {
        $this->ctx()->cookies()->set('theme', 'dark');
        return 'ok';
    }

    public function login(): never
    {
        $this->ctx()->session()->set('user', 42);
        $this->ctx()->cookies()->set('remember', 'yes');
        throw new RedirectException('/', 303);
    }

    public function untouched(): string
    {
        return 'ok';
    }

    /**
     * A generic error whose message must be escaped on the debug page
     */
    public function crash(): never
    {
        throw new \RuntimeException('<b>boom</b>');
    }

    /**
     * An application refusal: the framework has no opinion on access rights,
     * it only turns this into a 403 that leaks nothing
     */
    public function forbidden(): never
    {
        throw new ForbiddenException();
    }

    /**
     * What this request carries, to prove nothing leaks between worker cycles
     */
    public function echo(): string
    {
        $query = $this->request->getQueryParams()['q'] ?? '-';
        $cookie = $this->request->getCookieParams()['theme'] ?? '-';
        $query = is_scalar($query) ? (string) $query : '-';
        $cookie = is_scalar($cookie) ? (string) $cookie : '-';
        return "q={$query};theme={$cookie};";
    }
}
