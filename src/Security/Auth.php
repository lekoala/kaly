<?php

declare(strict_types=1);

namespace Kaly\Security;

use RuntimeException;
use Psr\Http\Message\ServerRequestInterface;
use Kaly\Core\App;
use Kaly\Http\RedirectException;

class Auth
{
    protected const ONE_WEEK = 604800;

    public const KEY_USER_ID = "user_id";
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
        $user = $_SESSION[self::KEY_USER_ID] ?? null;
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
        $_SESSION[self::KEY_USER_ID] = $id;
        return $this;
    }

    public function clearUser(): self
    {
        unset($_SESSION[self::KEY_USER_ID]);
        return $this;
    }

    /**
     * @throws RedirectException
     */
    public function checkAuth(): void
    {
        if (empty($_SESSION[self::KEY_USER_ID])) {
            throw new RedirectException($this->loginUrl);
        }
    }

    public function login(string $username, bool $remember = false): void
    {
        if (!$username) {
            return;
        }
        $params = [];
        session_regenerate_id(true);
        $this->setUser($username);
        $this->app->runCallbacks(self::class, self::CALLBACK_SUCCESS, [$username]);
    }

    public function logout(): void
    {
        $username = $this->getUser();
        if (!$username) {
            return;
        }
        $this->clearUser();
        $this->app->runCallbacks(self::class, self::CALLBACK_CLEARED, [$username]);
        session_destroy();
    }

    /**
     * Throws a basic auth exception that will interrupt application flow if no user is set
     * @throws BasicAuthenticationException
     */
    public static function basicAuth(
        ServerRequestInterface $request,
        string $username = '',
        string $password = ''
    ): void {
        if (!$username || !$password) {
            return;
        }

        $server = $request->getServerParams();
        $authHeader = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        assert(is_null($authHeader) || is_string($authHeader));

        $phpAuthUser = $server['PHP_AUTH_USER'] ?? null;
        $phpAuthPw = $server['PHP_AUTH_PW'] ?? null;

        $matches = [];
        if (
            $authHeader &&
            preg_match('/Basic\s+(.*)$/i', $authHeader, $matches)
        ) {
            [$name, $password] = explode(':', base64_decode($matches[1]));
            $phpAuthUser = strip_tags($name);
            $phpAuthPw = strip_tags($password);
        }
        $authSuccess = false;
        if ($phpAuthUser && $phpAuthPw) {
            if ($phpAuthUser == $username && $phpAuthPw == $password) {
                $authSuccess = true;
            }
        }
        if (!$authSuccess) {
            if ($phpAuthUser) {
                $message = t(self::class . ".user_not_found", [], "kaly");
            } else {
                $message = t(self::class . ".enter_your_credentials", [], "kaly");
            }

            // This implements ResponseProvider interface and it's response will be served by our app
            throw new BasicAuthenticationException($message);
        }
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
