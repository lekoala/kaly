<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * What the router needs from a routing owner.
 *
 * A module is a route scope, but the router never knows that: anything able
 * to answer where it mounts, whether it is localized and how it resolves
 * urls can be registered in a Router.
 */
interface RouteScope
{
    /**
     * The identity of the scope in route names (`shop:cart`)
     */
    public function id(): string;

    /**
     * The root namespace of the scope classes (`Shop\...`)
     */
    public function getNamespace(): string;

    /**
     * @return array<string,string> Url segment by locale, '*' for every locale
     */
    public function getMount(): array;

    /**
     * Whether its urls carry the locale prefix (`/fr/boutique/...`)
     */
    public function isLocalized(): bool;

    /**
     * Whether `controller/action/params` urls resolve by convention
     */
    public function hasConventionRouting(): bool;

    /**
     * The routing declarations, in declaration order: resolvers with their
     * priority, and RoutesDeclaration sources merged into one route table
     * that keeps the position of its first declaration.
     *
     * @return list<array{priority:int,resolver:ResolverInterface|class-string<ResolverInterface>|RoutesDeclaration}>
     */
    public function resolvers(): array;

    /**
     * Path prefixes owned outside the scope segment, each with its own table
     *
     * @return list<array{prefix:array<string,string>,routes:RoutesDeclaration}>
     */
    public function claims(): array;
}
