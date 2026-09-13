<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Helpers that make a plain PSR-7 request more useful.
 *
 * These are deliberately static functions over a request rather than a request
 * wrapper: a wrapper has to be rebuilt on every immutable mutation, which makes
 * any state it carries unreliable. Request scoped state belongs to the
 * HttpContext instead.
 */
final class RequestUtils
{
    public static function getPath(ServerRequestInterface $request): string
    {
        return $request->getUri()->getPath();
    }

    /**
     * Get request content character set, if known.
     */
    public static function getContentCharset(ServerRequestInterface $request): ?string
    {
        return self::getMediaTypeParams($request)['charset'] ?? null;
    }

    /**
     * Get request content type, if known.
     */
    public static function getContentType(ServerRequestInterface $request): ?string
    {
        $result = $request->getHeader('Content-Type');
        return $result ? $result[0] : null;
    }

    /**
     * Get request content length, if known.
     */
    public static function getContentLength(ServerRequestInterface $request): ?int
    {
        $result = $request->getHeader('Content-Length');
        return $result ? (int) $result[0] : null;
    }

    /**
     * Fetch cookie value from cookies sent by the client to the server.
     *
     * @return mixed
     */
    public static function getCookieParam(ServerRequestInterface $request, string $key, mixed $default = null)
    {
        return $request->getCookieParams()[$key] ?? $default;
    }

    /**
     * Get request media type, minus content-type params, if known.
     */
    public static function getMediaType(ServerRequestInterface $request): ?string
    {
        $contentType = self::getContentType($request);

        if ($contentType) {
            $contentTypeParts = preg_split('/\s*[;,]\s*/', $contentType);
            if ($contentTypeParts === false) {
                return null;
            }
            return strtolower($contentTypeParts[0]);
        }

        return null;
    }

    /**
     * Get request media type params, if known.
     *
     * @return string[]
     */
    public static function getMediaTypeParams(ServerRequestInterface $request): array
    {
        $contentType = self::getContentType($request);
        $contentTypeParams = [];

        if ($contentType) {
            $contentTypeParts = preg_split('/\s*[;,]\s*/', $contentType);
            if ($contentTypeParts !== false) {
                $contentTypePartsLength = count($contentTypeParts);
                for ($i = 1; $i < $contentTypePartsLength; $i++) {
                    $paramParts = explode('=', $contentTypeParts[$i]);
                    /** @var string[] $paramParts */
                    $contentTypeParams[strtolower($paramParts[0])] = $paramParts[1];
                }
            }
        }

        return $contentTypeParams;
    }

    /**
     * The client ip as reported by the server, without any proxy resolution.
     *
     * Missing or blank values fall back to '0.0.0.0'.
     */
    public static function getIp(ServerRequestInterface $request): string
    {
        $ip = self::getServerParam($request, 'REMOTE_ADDR');
        return $ip !== null && $ip !== '' ? $ip : '0.0.0.0';
    }

    /**
     * @return array<string,float>
     */
    public static function parseAcceptedLanguages(ServerRequestInterface $request): array
    {
        $header = $request->getHeader('Accept-Language')[0] ?? '';
        if (!$header) {
            $header = $request->getServerParams()['HTTP_ACCEPT_LANGUAGE'] ?? '';
        }
        if (!is_string($header)) {
            $header = '';
        }
        $arr = [];
        if (!$header) {
            return $arr;
        }
        foreach (explode(',', $header) as $part) {
            $subparts = explode(';q=', $part);
            $language = trim($subparts[0]);
            // Ignore the wildcard and empty entries: they carry no language
            if ($language === '' || $language === '*') {
                continue;
            }
            $arr[$language] = floatval(trim($subparts[1] ?? '1'));
        }
        arsort($arr);
        return $arr;
    }

    /**
     * Return the preferred language for this request.
     *
     * When $allowed is provided, the best supported language is negotiated
     * (a request for "en-US" matches the supported "en"). Returns null when
     * nothing matches so the caller can fall back to its own default.
     *
     * @param array<string>|null $allowed
     */
    public static function getPreferredLanguage(ServerRequestInterface $request, ?array $allowed = null): ?string
    {
        $arr = self::parseAcceptedLanguages($request);
        if ($allowed === null) {
            return $arr === [] ? null : array_key_first($arr);
        }
        foreach (array_keys($arr) as $language) {
            foreach ($allowed as $candidate) {
                if (self::languageMatches($language, $candidate)) {
                    return $candidate;
                }
            }
        }
        return null;
    }

    private static function languageMatches(string $language, string $candidate): bool
    {
        if ($candidate === '') {
            return false;
        }
        if (strcasecmp($language, $candidate) === 0) {
            return true;
        }
        $language = strtolower($language);
        $candidate = strtolower($candidate);
        return str_starts_with($language, $candidate . '-') || str_starts_with($language, $candidate . '_');
    }

    /**
     * Fetch a parameter value from the body or the query string (in that order).
     *
     * @return mixed
     */
    public static function getRequestParam(ServerRequestInterface $request, string $key, mixed $default = null)
    {
        $postParams = $request->getParsedBody();
        $getParams = $request->getQueryParams();
        $result = $default;

        if (is_array($postParams) && isset($postParams[$key])) {
            $result = $postParams[$key];
        } elseif (is_object($postParams) && property_exists($postParams, $key)) {
            $result = $postParams->$key;
        } elseif (isset($getParams[$key])) {
            $result = $getParams[$key];
        }

        return $result;
    }

    /**
     * The best content type for this request. Falls back to the first entry of
     * the priority list, or to plain text when none is given.
     *
     * @param string[] $priorityList
     */
    public static function getPreferredContentType(ServerRequestInterface $request, array $priorityList = []): string
    {
        $accepted = self::parseAcceptHeader($request);
        if (!empty($priorityList)) {
            foreach ($priorityList as $item) {
                if (in_array($item, $accepted, true)) {
                    return $item;
                }
            }
            return $priorityList[0];
        }
        return $accepted[0] ?? ContentType::PLAIN;
    }

    /**
     * @return array<string>
     */
    public static function parseAcceptHeader(ServerRequestInterface $request): array
    {
        $header = $request->getHeader('Accept')[0] ?? '';
        $arr = [];
        foreach (explode(',', $header) as $part) {
            $subparts = explode(';', $part);
            $mime = $subparts[0] ?? '';
            $types = explode('/', $mime);

            // Ignore invalid mimetypes
            if (!isset($types[1])) {
                continue;
            }

            $arr[] = $mime;
        }
        return $arr;
    }

    /**
     * Fetch a parameter value from the request body.
     *
     * @return mixed
     */
    public static function getParsedBodyParam(ServerRequestInterface $request, string $key, mixed $default = null)
    {
        $postParams = $request->getParsedBody();
        $result = $default;

        if (is_array($postParams) && isset($postParams[$key])) {
            $result = $postParams[$key];
        } elseif (is_object($postParams) && property_exists($postParams, $key)) {
            $result = $postParams->$key;
        }

        return $result;
    }

    /**
     * Fetch a parameter value from the query string.
     *
     * @return mixed
     */
    public static function getQueryParam(ServerRequestInterface $request, string $key, mixed $default = null)
    {
        $getParams = $request->getQueryParams();
        $result = $default;

        if (isset($getParams[$key])) {
            $result = $getParams[$key];
        }

        return $result;
    }

    /**
     * Retrieve a server parameter.
     */
    public static function getServerParam(ServerRequestInterface $request, string $key, ?string $default = null): ?string
    {
        $v = $request->getServerParams()[$key] ?? $default;
        if (!is_string($v)) {
            return $default;
        }
        return $v;
    }

    public static function isMethod(ServerRequestInterface $request, string $method): bool
    {
        return $request->getMethod() === $method;
    }

    public static function isDelete(ServerRequestInterface $request): bool
    {
        return self::isMethod($request, Method::DELETE);
    }

    public static function isGet(ServerRequestInterface $request): bool
    {
        return self::isMethod($request, Method::GET);
    }

    public static function isHead(ServerRequestInterface $request): bool
    {
        return self::isMethod($request, Method::HEAD);
    }

    public static function isOptions(ServerRequestInterface $request): bool
    {
        return self::isMethod($request, Method::OPTIONS);
    }

    public static function isPatch(ServerRequestInterface $request): bool
    {
        return self::isMethod($request, Method::PATCH);
    }

    public static function isPost(ServerRequestInterface $request): bool
    {
        return self::isMethod($request, Method::POST);
    }

    public static function isPut(ServerRequestInterface $request): bool
    {
        return self::isMethod($request, Method::PUT);
    }

    /**
     * Is this an XHR request?
     *
     * Note, X-Requested-With is not sent by default using the fetch api
     */
    public static function isXhr(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }
}
