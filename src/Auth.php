<?php

declare(strict_types=1);

namespace Kaly;

use Kaly\Exceptions\AuthenticationException;
use Kaly\Exceptions\RedirectException;
use RuntimeException;

class Auth
{
    public const ATTR_USER_ID = "user-id";
    public const CALLBACK_SUCCESS = "success";
    public const CALLBACK_FAILED = "failed";
    public const CALLBACK_CLEARED = "cleared";

    protected App $app;
    protected string $loginUrl = "/auth/login/";
    protected string $logoutUrl = "/auth/logout/";

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function getUser(): ?string
    {
        $user = $this->app->getRequest()->getAttribute(self::ATTR_USER_ID);
        if (!$user) {
            return null;
        }
        if (is_string($user)) {
            return $user;
        }
        throw new RuntimeException("Invalid user attribute value");
    }

    public function setUser(string $id): self
    {
        $request = &$this->app->getRequest();
        $request = $request->withAttribute(self::ATTR_USER_ID, $id);
        return $this;
    }

    /**
     * @throws RedirectException
     */
    public function checkAuth(): void
    {
        if (!$this->app->getRequest()->getAttribute(self::ATTR_USER_ID)) {
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

        $request = $this->app->getRequest();

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
            $request = $request->withAttribute(self::ATTR_USER_ID, $server['PHP_AUTH_USER']);
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
            $this->app->runCallbacks(self::class, self::CALLBACK_FAILED);
            // This implements ResponseProvider interface and it's response will be served by our app
            throw new AuthenticationException($message);
        }

        $this->app->runCallbacks(self::class, self::CALLBACK_SUCCESS);
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
