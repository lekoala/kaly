<?php

declare(strict_types=1);

namespace Kaly;

use Throwable;
use Kaly\Exceptions\AuthenticationException;
use Psr\Http\Message\ServerRequestInterface;

class Util
{
    /**
     * Gets sequential array of all previously-chained errors
     * @return Throwable[]
     */
    public static function getExceptionChain(Throwable $error): array
    {
        $chain = [];
        do {
            $chain[] = $error;
        } while ($error = $error->getPrevious());
        return $chain;
    }

    /**
     * Gets sequential array of all previously-chained error messages
     * @return Throwable[]
     */
    public static function getExceptionMessageChain(Throwable $error): array
    {
        $chain = [];
        do {
            $chain[] = $error->getMessage();
        } while ($error = $error->getPrevious());
        return $chain;
    }

    /**
     * Gets sequential array of all previously-chained error messages
     * @return Throwable[]
     */
    public static function getExceptionMessageChainAsString(Throwable $error): string
    {
        $chain = self::getExceptionMessageChain($error);
        return implode("; ", $chain);
    }

    public static function camelize(string $str, bool $firstChar = true): string
    {
        if (!$str) {
            return $str;
        }
        $str = str_replace(' ', '', ucwords(str_replace('-', ' ', $str)));
        if (!$firstChar) {
            $str[0] = strtolower($str[0]);
        }
        return $str;
    }

    /**
     * This function replaces array_merge_recursive which transforms string
     * keys into arrays
     * Arguments are passed as reference for performance reason
     */
    public static function mergeArrays(array &$arr1, array &$arr2, bool $deep = true): array
    {
        foreach ($arr2 as $k => $v) {
            // regular array values are appended
            if (is_int($k)) {
                $arr1[] = $v;
                continue;
            }
            // associative arrays work by keys
            if (isset($arr1[$k]) && is_array($arr1[$k])) {
                if ($deep) {
                    $arr1[$k] = self::mergeArrays($arr1[$k], $v, $deep);
                } else {
                    $arr1[$k] = array_merge($arr1[$k], $v);
                }
            } else {
                $arr1[$k] = $v;
            }
        }
        return $arr1;
    }

    /**
     * @throws AuthenticationException
     */
    public static function basicAuth(ServerRequestInterface $request, string $username = '', string $password = ''): void
    {
        if (!$username || !$password) {
            return;
        }

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
            $_SERVER['PHP_AUTH_USER'] = strip_tags($name);
            $_SERVER['PHP_AUTH_PW'] = strip_tags($password);
        }
        $authSuccess = false;
        if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
            if ($_SERVER['PHP_AUTH_USER'] == $username && $_SERVER['PHP_AUTH_PW'] == $password) {
                $authSuccess = true;
            }
        }
        if (!$authSuccess) {
            if (isset($_SERVER['PHP_AUTH_USER'])) {
                $message = "That username / password isn't recognised";
            } else {
                $message = "Please enter a username and password.";
            }
            // This implements ResponseProvider interface and it's response will be served by our app
            throw new AuthenticationException($message);
        }
    }
}
