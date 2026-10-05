<?php

declare(strict_types=1);

namespace Kaly\Http\Input;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Builds the typed input of an action from the query string and the parsed
 * body of a request.
 *
 * Route segments are never a source: they are part of the route and are passed
 * to the action as scalar parameters by the dispatcher.
 */
interface InputMapperInterface
{
    /**
     * @template T of RequestInput
     * @param class-string<T> $class
     * @return T
     * @throws InputException When the data cannot be represented by the type
     * @throws ValidationException When the input is well typed but refused
     */
    public function map(ServerRequestInterface $request, string $class): RequestInput;

    /**
     * The non-throwing variant: a form re-renders the submitted values with
     * their violations instead of catching an exception.
     *
     * @template T of RequestInput
     * @param class-string<T> $class
     * @return InputResult<T>
     */
    public function mapResult(ServerRequestInterface $request, string $class): InputResult;
}
