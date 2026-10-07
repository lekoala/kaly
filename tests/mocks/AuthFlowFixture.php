<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Kaly\Core\AbstractController;
use Kaly\Http\Exception\RedirectException;
use Kaly\Http\Exception\UnauthorizedException;

class AuthFlowFixture extends AbstractController
{
    public function login(): never
    {
        $this->ctx()->session()->set('user', 42);
        $this->ctx()->session()->regenerateId();
        throw new RedirectException('/me', 303);
    }

    public function me(): string
    {
        $user = $this->ctx()->session()->get('user');
        if (!is_scalar($user) || $user === '' || $user === false) {
            throw new UnauthorizedException('Cookie');
        }
        return 'user:' . $user;
    }

    public function logout(): never
    {
        $this->ctx()->session()->destroy();
        throw new RedirectException('/', 303);
    }

    public function echoPost(): string
    {
        return $this->request->getMethod() . ':' . (string) $this->request->getBody();
    }

    public function forward(): never
    {
        throw new RedirectException('/target', 307);
    }

    public function bounce(): never
    {
        throw new RedirectException('/bounce', 303);
    }
}
