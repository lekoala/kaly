---
layout: default
title: Cycle recipe
nav_order: 2
---
# Cycle recipe

Like Doctrine, Cycle stays outside Kaly: depend on your application ports and
wire Cycle in infrastructure. Distinguish what is shared from what is
disposable, following the [Persistence lifecycle](../database.md#lifecycle-rule):

- the `ORM` object itself (schema, mappers) is stateless configuration: it can
  be a shared service;
- the unit of work / entity heap is stateful: open a fresh one per operation
  through a shared factory, and discard it afterwards — including after a
  failure, even inside the same request.

```php
$ormFactory->with(static function (UnitOfWork $uow) use ($order): void {
    // ...
});
```

The owner opens, the owner closes. See
[DI](../di.md#stateful-services-and-request-lifetime).
