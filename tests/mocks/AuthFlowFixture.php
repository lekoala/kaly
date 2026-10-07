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

    public function echoParsed(): string
    {
        $parsed = $this->request->getParsedBody();
        $value = is_array($parsed) && isset($parsed['a']) && is_scalar($parsed['a']) ? (string) $parsed['a'] : 'none';
        return $this->request->getMethod() . ':' . $value;
    }

    public function forward(): never
    {
        throw new RedirectException('/target', 307);
    }

    public function forwardParsed(): never
    {
        throw new RedirectException('/target-parsed', 307);
    }

    public function dotSource(): never
    {
        throw new RedirectException('../target', 307);
    }

    public function dotTarget(): string
    {
        return 'dot-ok';
    }

    public function protoSource(): never
    {
        throw new RedirectException('//evil.example/target', 307);
    }

    public function schemeSource(): never
    {
        throw new RedirectException('https://good.example/target-abs', 307);
    }

    public function portSource(): never
    {
        throw new RedirectException('http://good.example:8080/target-abs', 307);
    }

    public function absSource(): never
    {
        throw new RedirectException('http://good.example/target-abs', 307);
    }

    public function absTarget(): string
    {
        return 'abs-ok';
    }

    public function bounce(): never
    {
        throw new RedirectException('/bounce', 303);
    }
}
