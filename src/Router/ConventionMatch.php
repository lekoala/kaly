<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Http\Input\RequestInput;
use Psr\Http\Message\ServerRequestInterface;

/**
 * @internal The state of one ConventionResolver::resolve() call.
 *
 * The resolver itself is shared by every request, so what a match consumes
 * (remaining path segments, discovered input) lives here and dies with it.
 */
final class ConventionMatch
{
    /**
     * The trailing input of the matched action, if any
     * @var class-string<RequestInput>|null
     */
    public ?string $inputClass = null;

    /**
     * @param string[] $parts The path segments not consumed yet
     */
    public function __construct(
        public readonly ServerRequestInterface $request,
        public array $parts,
    ) {}
}
