<?php

declare(strict_types=1);

namespace Kaly\Http;

use JsonSerializable;

/**
 * This is (almost) exactly the same as psr7-sessions/storageless in case you want the same api
 */
interface ArrayDataInterface extends JsonSerializable
{
    /**
     * Retrieves a value - if the value doesn't exist, then it uses the given $default
     *
     * @param int|bool|string|float|array<mixed>|null $default
     * @return int|bool|string|float|array<mixed>|null
     */
    public function get(string $key, $default = null);

    /**
     * Stores a given value
     *
     * @param int|bool|string|float|array<mixed>|object|null $value
     */
    public function set(string $key, $value): void;

    /**
     * Removes an item
     */
    public function remove(string $key): void;

    /**
     * Clears the contents
     */
    public function clear(): void;

    /**
     * Checks whether a given key exists
     */
    public function has(string $key): bool;

    /**
     * Checks whether the data has changed its contents since its lifecycle start
     */
    public function hasChanged(): bool;

    /**
     * Get an array of changes
     * @return array<string,array<mixed>>
     */
    public function getChanges(): array;

    /**
     * Get all entries
     * @return array<string,mixed>
     */
    public function all(): array;

    /**
     * Checks whether it contains any data
     */
    public function isEmpty(): bool;

    /** {@inheritDoc} */
    public function jsonSerialize(): object;
}
