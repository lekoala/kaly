<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * Marker for an object built from the query string and the parsed body.
 *
 * An action receives its arguments from exactly three places, and the type of
 * each argument says which one:
 *
 * ```text
 * constructor                 services, request, context
 * action scalar parameters    route segments
 * one trailing RequestInput   query string and parsed body
 * ```
 *
 * An input is a plain readonly class whose promoted constructor is the schema:
 *
 * ```php
 * final readonly class SearchPatientsInput implements RequestInput
 * {
 *     public function __construct(
 *         public string $query,
 *         public int $page = 1,
 *     ) {}
 * }
 * ```
 *
 * An action declares zero or one of them, always as its last parameter. Route
 * segments are never mapped into an input: a segment that does not fit its
 * type is a routing failure (404), an input that cannot be built is a bad
 * request (400).
 *
 * @see docs/input.md
 */
interface RequestInput {}
