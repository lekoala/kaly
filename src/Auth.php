<?php

declare(strict_types=1);

namespace Kaly;

use Kaly\Exceptions\AuthenticationException;
use Kaly\Exceptions\RedirectException;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

class Auth
{
    public const USER_ID_ATTR = "user-id";
    public const CALLBACK_SUCCESS = "success";
    public const CALLBACK_FAILED = "failed";
    public const CALLBACK_CLEARED = "cleared";

    protected App $app;
    protected ServerRequestInterface $request;
    protected string $loginUrl = "/auth/login/";
    protected string $logoutUrl = "/auth/logout/";

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->request = $app->getRequest();
    }

    public function getUser(): ?string
    {
        $user = $this->request->getAttribute(self::USER_ID_ATTR);
        if (!$user) {
            return null;
        }
        if (is_string($user)) {
            return $user;
        }
        throw new RuntimeException("Invalid user attribute value");
    }

    /**
     * @throws RedirectException
     */
    public function checkAuth(): void
    {
        if (!$this->request->getAttribute(self::USER_ID_ATTR)) {
            throw new RedirectException($this->loginUrl);
        }
    }

    /**
     * @throws AuthenticationException
     */
    public function basicAuth(string $username = '', string $password = ''): void
    {
        if (!$username || !$password) {
            return;
        }

        $request = $this->request;

        $app = App::inst();

        $server = $request->getServerParams();
        $authHeader = null;
        if (isset($server['HTTP_AUTHORIZATION'])) {
            $authHeader = $server['HTTP_AUTHORIZATION'];
        } elseif (isset($server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $authHeader = $server['REDIRECT_HTTP_AUTHORIZATION'];
        }

        $matches = [];
        if (
            $authHeader &&
            preg_match('/Basic\s+(.*)$/i', $authHeader, $matches)
        ) {
            list($name, $password) = explode(':', base64_decode($matches[1]));
            $server['PHP_AUTH_USER'] = strip_tags($name);
            $server['PHP_AUTH_PW'] = strip_tags($password);
        }
        $authSuccess = false;
        if (isset($server['PHP_AUTH_USER']) && isset($server['PHP_AUTH_PW'])) {
            $request = $request->withAttribute(self::USER_ID_ATTR, $server['PHP_AUTH_USER']);
            if ($server['PHP_AUTH_USER'] == $username && $server['PHP_AUTH_PW'] == $password) {
                $authSuccess = true;
            }
        }
        if (!$authSuccess) {
            if (isset($server['PHP_AUTH_USER'])) {
                $message = t(self::class . ".user_not_found", [], "kaly");
            } else {
                $message = t(self::class . ".enter_your_credentials", [], "kaly");
            }
            $app->runCallbacks(self::class, self::CALLBACK_FAILED, [ServerRequestInterface::class => $request]);
            // This implements ResponseProvider interface and it's response will be served by our app
            throw new AuthenticationException($message);
        }

        $app->runCallbacks(self::class, self::CALLBACK_SUCCESS, [ServerRequestInterface::class => $request]);
    }

    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function setRequest(ServerRequestInterface $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function getLoginUrl(): string
    {
        return $this->loginUrl;
    }

    public function setLoginUrl(string $loginUrl): self
    {
        $this->loginUrl = $loginUrl;
        return $this;
    }

    public function getLogoutUrl(): string
    {
        return $this->logoutUrl;
    }

    public function setLogoutUrl(string $logoutUrl): self
    {
        $this->logoutUrl = $logoutUrl;
        return $this;
    }
}
