<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * The trailing-slash canonicalization of urls.
 *
 * - `Add`: urls end with a slash, other spellings redirect (except the query).
 * - `Remove`: urls carry no trailing slash, other spellings redirect.
 * - `Preserve`: both spellings match, no slash redirect; generation keeps the
 *   declared spelling (conventions generate without a slash, except the root).
 *   `Preserve` never declares `/foo` and `/foo/` as two different resources.
 */
enum TrailingSlash
{
    case Add;
    case Remove;
    case Preserve;
}
