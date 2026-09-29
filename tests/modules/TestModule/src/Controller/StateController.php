<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;
use Kaly\Http\RedirectException;

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
     * What this request carries, to prove nothing leaks between worker cycles
     */
    public function echo(): string
    {
        $query = $this->request->getQueryParams()['q'] ?? '-';
        $cookie = $this->request->getCookieParams()['theme'] ?? '-';
        return "q={$query};theme={$cookie};";
    }
}
