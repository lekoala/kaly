# JSON APIs

Return data, not responses. A plain `array` is rendered as a 200 JSON response;
`JsonResponse::of()` sets the status code and headers:

```php
public function show(int $id): array
{
    return ['id' => $id, 'name' => $this->products->name($id)];
}

public function create(CreateInput $input): JsonResponse
{
    $product = $this->products->create($input->name);
    return JsonResponse::of(['id' => $product->id], 201);
}
```

Errors follow the client: a JSON client gets `application/problem+json`
(RFC 9457), an HTML client gets a page. HTTP exceptions (`NotFoundException`,
`ValidationException` → 422, `MethodNotAllowedException` → 405 with `Allow`)
are expected outcomes with a public body; anything else is logged and becomes
a 500 that leaks nothing.

```php
$client->get('/api/products/7/')
    ->assertStatus(200)
    ->assertHeader('Content-Type', 'application/json')
    ->assertJson(['id' => 7, 'name' => 'Bike']);

$client->post('/api/products/', ['json' => ['name' => 'Bike']])
    ->assertStatus(201);
```

Version by module, not by prefix hack: a `v2` module mounts its own segment
with its own tables, and both versions coexist. See [Routing](routing.md) and
[Testing](testing.md).
