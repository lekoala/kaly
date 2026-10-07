---
layout: default
title: Doctrine recipe
nav_order: 1
---
# Doctrine recipe

Kaly owns no persistence concept: use Doctrine directly. The one rule is the
[Persistence lifecycle](../database.md#lifecycle-rule): never share an
`EntityManager`. Inject a shared *factory* and open a fresh manager per
operation:

```php
final class DoctrineEntityManagerFactory
{
    public function __construct(private EntityManagerFactoryConfig $config) {}

    /**
     * @template T
     * @param callable(EntityManager): T $operation
     * @return T
     */
    public function with(callable $operation): mixed
    {
        $em = $this->create();
        try {
            return $operation($em);
        } finally {
            $em->close();
        }
    }
}
```

Transactions stay explicit, inside the operation — never automatic at the end
of a response:

```php
$factory->with(static function (EntityManager $em) use ($order): void {
    $em->transactional(static function () use ($em, $order): void {
        // ...
    });
});
```

After a failed transaction, discard the manager and open a clean one, even
inside the same request. The owner opens, the owner closes. See
[DI](../di.md#stateful-services-and-request-lifetime).
