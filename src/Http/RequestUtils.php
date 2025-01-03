<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Additional method that makes the request object more useful
 */
trait RequestUtils
{
    protected ServerRequestInterface $request;

    public function getBaseRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function getPath(): string
    {
        return $this->request->getUri()->getPath();
    }

    /**
     * Get serverRequest content character set, if known.
     *
     * @return string|null
     */
    public function getContentCharset(): ?string
    {
        return $this->getMediaTypeParams()['charset'] ?? null;
    }

    /**
     * Get serverRequest content type.
     *
     * @return string|null The serverRequest content type, if known
     */
    public function getContentType(): ?string
    {
        $result = $this->request->getHeader('Content-Type');
        return $result ? $result[0] : null;
    }

    /**
     * Get serverRequest content length, if known.
     *
     * @return int|null
     */
    public function getContentLength(): ?int
    {
        $result = $this->request->getHeader('Content-Length');
        return $result ? (int) $result[0] : null;
    }

    /**
     * Fetch cookie value from cookies sent by the client to the server.
     *
     * @param string $key     The attribute name.
     * @param mixed  $default Default value to return if the attribute does not exist.
     * @return mixed
     */
    public function getCookieParam(string $key, mixed $default = null)
    {
        return $this->request->getCookieParams()[$key] ?? $default;
    }

    /**
     * Get serverRequest media type, if known.
     *
     * @return string|null The serverRequest media type, minus content-type params
     */
    public function getMediaType(): ?string
    {
        $contentType = $this->getContentType();

        if ($contentType) {
            $contentTypeParts = preg_split('/\s*[;,]\s*/', (string) $contentType);
            if ($contentTypeParts === false) {
                return null;
            }
            return strtolower($contentTypeParts[0]);
        }

        return null;
    }

    /**
     * Get serverRequest media type params, if known.
     *
     * @return string[]
     */
    public function getMediaTypeParams(): array
    {
        $contentType = $this->getContentType();
        $contentTypeParams = [];

        if ($contentType) {
            $contentTypeParts = preg_split('/\s*[;,]\s*/', (string) $contentType);
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
     * @return string
     */
    public function getIp(): string
    {
        return $this->getServerParam('REMOTE_ADDR') ?? '0.0.0.0';
    }

    /**
     * @return array<string,float>
     */
    public function parseAcceptedLanguages(): array
    {
        $header = $this->request->getHeader('Accept-Language')[0] ?? '';
        if (!$header) {
            $header = $this->request->getServerParams()['HTTP_ACCEPT_LANGUAGE'] ?? '';
        }
        assert(is_string($header));
        $arr = [];
        if (!$header) {
            return $arr;
        }
        foreach (explode(',', $header) as $part) {
            $subparts = explode(";q=", $part);
            $arr[$subparts[0]] = floatval($subparts[1] ?? 1);
        }
        arsort($arr);
        return $arr;
    }

    /**
     * @param array<string>|null $allowed
     */
    public function getPreferredLanguage(?array $allowed = null): ?string
    {
        $arr = $this->parseAcceptedLanguages();
        if ($allowed === null) {
            return key($arr);
        }
        foreach ($arr as $k => $v) {
            if (in_array($k, $allowed)) {
                return $k;
            }
        }
        return $allowed[0] ?? null;
    }

    /**
     * Fetch serverRequest parameter value from body or query string (in that order).
     *
     * @param  string $key The parameter key.
     * @param  mixed  $default The default value.
     * @return mixed The parameter value.
     */
    public function getRequestParam(string $key, mixed $default = null)
    {
        $postParams = $this->getParsedBody();
        $getParams = $this->getQueryParams();
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
     * @param string[] $priorityList
     */
    public function getPreferredContentType(array $priorityList = []): ?string
    {
        $accepted = $this->parseAcceptHeader();
        if (!empty($priorityList)) {
            foreach ($priorityList as $item) {
                if (in_array($item, $accepted)) {
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
    public function parseAcceptHeader(): array
    {
        $header = $this->getHeader('Accept')[0] ?? '';
        $arr = [];
        foreach (explode(',', (string) $header) as $part) {
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
     * Fetch associative array of body and query string parameters.
     *
     * @return array<string,mixed>
     */
    public function getRequestParams(): array
    {
        $params = $this->getQueryParams();
        $postParams = $this->getParsedBody();

        if ($postParams) {
            $params = array_merge($params, (array)$postParams);
        }

        //@phpstan-ignore-next-line
        return $params;
    }

    /**
     * Fetch parameter value from serverRequest body.
     *
     * @param string $key
     * @return mixed
     */
    public function getParsedBodyParam(string $key, mixed $default = null)
    {
        $postParams = $this->getParsedBody();
        $result = $default;

        if (is_array($postParams) && isset($postParams[$key])) {
            $result = $postParams[$key];
        } elseif (is_object($postParams) && property_exists($postParams, $key)) {
            $result = $postParams->$key;
        }

        return $result;
    }

    /**
     * Fetch parameter value from query string.
     *
     * @param string $key
     * @return mixed
     */
    public function getQueryParam(string $key, mixed $default = null)
    {
        $getParams = $this->getQueryParams();
        $result = $default;

        if (isset($getParams[$key])) {
            $result = $getParams[$key];
        }

        return $result;
    }

    /**
     * Retrieve a server parameter.
     *
     * @param string $key
     * @param ?string $default
     * @return ?string
     */
    public function getServerParam(string $key, ?string $default = null): ?string
    {
        $v = $this->request->getServerParams()[$key] ?? $default;
        assert(is_null($v) || is_string($v));
        return $v;
    }

    /**
     * Does this serverRequest use a given method?
     *
     * @param  string $method HTTP method
     * @return bool
     */
    public function isMethod(string $method): bool
    {
        return $this->request->getMethod() === $method;
    }

    /**
     * Is this a DELETE serverRequest?
     *
     * @return bool
     */
    public function isDelete(): bool
    {
        return $this->isMethod(Method::DELETE);
    }

    /**
     * Is this a GET serverRequest?
     *
     * @return bool
     */
    public function isGet(): bool
    {
        return $this->isMethod(Method::GET);
    }

    /**
     * Is this a HEAD serverRequest?
     *
     * @return bool
     */
    public function isHead(): bool
    {
        return $this->isMethod(Method::HEAD);
    }

    /**
     * Is this a OPTIONS serverRequest?
     *
     * @return bool
     */
    public function isOptions(): bool
    {
        return $this->isMethod(Method::OPTIONS);
    }

    /**
     * Is this a PATCH serverRequest?
     *
     * @return bool
     */
    public function isPatch(): bool
    {
        return $this->isMethod(Method::PATCH);
    }

    /**
     * Is this a POST serverRequest?
     *
     * @return bool
     */
    public function isPost(): bool
    {
        return $this->isMethod(Method::POST);
    }

    /**
     * Is this a PUT serverRequest?
     *
     * @return bool
     */
    public function isPut(): bool
    {
        return $this->isMethod(Method::PUT);
    }

    /**
     * Is this an XHR serverRequest?
     *
     * Note, X-Requested-With are not sent by default using fetch api
     *
     * @return bool
     */
    public function isXhr(): bool
    {
        return $this->request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }
}
