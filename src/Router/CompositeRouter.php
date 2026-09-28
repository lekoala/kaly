<?php

declare(strict_types=1);

namespace Kaly\Router;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Explicit routes first, convention as fallback.
 *
 * The collection router owns every declared RouteDefinition (from
 * per-module routes.php files and #[Route] sugar — the same model either way).
 * ClassRouter stays a purely conventional fallback with no knowledge of
 * attributes or declarations: for plain CRUD no declaration is needed, and
 * only the exception gets declared.
 */
class CompositeRouter implements RouterInterface
{
    public function __construct(
        protected RouteCollectionRouter $explicit,
        protected ClassRouter $convention,
    ) {}

    public function explicitRouter(): RouteCollectionRouter
    {
        return $this->explicit;
    }

    public function conventionRouter(): ClassRouter
    {
        return $this->convention;
    }

    /**
     * An explicit route claims its match space: a 405 from the explicit
     * table is authoritative and never falls through to the convention,
     * which could otherwise match something else and silently override a
     * deliberate declaration. Only a 404 (unknown path) reaches ClassRouter.
     */
    public function match(ServerRequestInterface $request): Route
    {
        try {
            return $this->explicit->match($request);
        } catch (RouteNotFoundException) {
            // Explicit table missed: fall through to convention.
            // @mago-expect lint:no-empty-catch-clause
        }

        return $this->convention->match($request);
    }

    /**
     * @param string|array<mixed> $handler Route name, handler or route array.
     * @param array<string,mixed> $params
     */
    public function generate($handler, array $params = []): string
    {
        if (is_string($handler) && !str_contains($handler, '::') && !str_contains($handler, '->') && !str_contains($handler, '\\')) {
            return $this->explicit->generate($handler, $params);
        }
        try {
            return $this->explicit->generate($handler, $params);
        } catch (AmbiguousRouteException $e) {
            // Ambiguity is a declaration mistake: never mask it behind a
            // conventional url.
            throw $e;
        } catch (RuntimeException) {
            return $this->convention->generate($handler, $params);
        }
    }

    /**
     * Proxy shared router settings to both inner routers.
     * @param string[] $allowedLocales
     */
    public function setAllowedLocales(array $allowedLocales): self
    {
        $this->explicit->setAllowedLocales($allowedLocales);
        return $this;
    }

    public function setForceTrailingSlash(bool $force): self
    {
        $this->explicit->setForceTrailingSlash($force);
        $this->convention->setForceTrailingSlash($force);
        return $this;
    }

    public function addAllowedNamespace(string $namespace, ?string $mapping = null): self
    {
        $this->convention->addAllowedNamespace($namespace, $mapping);
        return $this;
    }

    /**
     * @param array<string,string> $allowedNamespaces
     */
    public function setAllowedNamespaces(array $allowedNamespaces): self
    {
        $this->convention->setAllowedNamespaces($allowedNamespaces);
        return $this;
    }
}
