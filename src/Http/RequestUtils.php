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
    /**
     * Get the request path.
     */
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
        $media = MediaType::parse(self::getContentType($request));
        return $media === null || $media->isEmpty() ? null : (string) $media;
    }

    /**
     * Get request media type params, if known.
     *
     * A parameter without a value yields an empty string, and quoted values
     * keep the separators they contain.
     *
     * @return array<string,string>
     */
    public static function getMediaTypeParams(ServerRequestInterface $request): array
    {
        $media = MediaType::parse(self::getContentType($request));
        return $media === null ? [] : $media->params;
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
     * Return the preferred language for this request.
     *
     * When $allowed is provided, the best supported language is negotiated
     * (a request for "en-US" matches the supported "en", and a language the
     * client refused with q=0 is never a match). Returns null when nothing
     * matches so the caller can fall back to its own default.
     *
     * @param array<string>|null $allowed
     */
    public static function getPreferredLanguage(ServerRequestInterface $request, ?array $allowed = null): ?string
    {
        return AcceptLanguage::fromRequest($request)->best($allowed);
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
     * The best content type for this request, from a server priority list.
     *
     * The client leads: an entry weighs as much as its own q value says. The
     * priority list is the server preference, in order, and breaks ties. When
     * the client accepts nothing from the list, the first entry of the list is
     * the answer; with no list either, the best type the client asked for, or
     * plain text.
     *
     * @param string[] $priorityList
     */
    public static function getPreferredContentType(ServerRequestInterface $request, array $priorityList = []): string
    {
        $accept = Accept::fromRequest($request);
        if ($priorityList !== []) {
            return $accept->negotiate($priorityList) ?? $priorityList[0];
        }
        $accepted = $accept->toArray();
        // A client that expressed no preference is served the wildcard
        return $accepted[0] === '*/*' ? ContentType::PLAIN : $accepted[0];
    }

    /**
     * The media types the client accepts, best first.
     *
     * @return array<string>
     */
    public static function parseAcceptHeader(ServerRequestInterface $request): array
    {
        return Accept::fromRequest($request)->toArray();
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
