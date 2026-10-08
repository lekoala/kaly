---
layout: default
title: Forms
nav_order: 11
---
# Forms

A form is a `RequestInput` with its rules in `ValidatableInput`: a typed object
mapped from the query string and the body. See [Request input](input.md).

```php
use Kaly\Http\Input\ValidatableInput;
use Kaly\Validation\Validator;

final readonly class CheckoutInput implements ValidatableInput
{
    public function __construct(
        public string $email,
        public int $quantity = 1,
    ) {}

    public function validate(Validator $v): void
    {
        $v->email('email', $this->email);
        $v->between('quantity', $this->quantity, 1, 10);
    }
}
```

A JSON action declares the input and lets the dispatcher throw: a
`ValidationException` becomes a 422 `problem+json` response with an `errors`
list.

```php
public function checkout(CheckoutInput $input): never
{
    $order = $this->orders->place($input->email, $input->quantity);
    $this->redirectToRoute('shop:confirmation', ['id' => $order->id]);
}
```

An HTML action re-renders instead, keeping exactly what was sent plus the
violations (422) rather than redirecting: a redirect would lose what the user
typed.

```php
use Kaly\Http\Input\InputMapperInterface;

final class ShopController extends AbstractController
{
    public function __construct(
        ServerRequestInterface $request,
        private InputMapperInterface $inputs,
    ) {
        parent::__construct($request);
    }

    public function checkoutForm(): View
    {
        $result = $this->inputs->mapResult($this->request, CheckoutInput::class);

        if (!$result->isValid()) {
            return new View('shop/checkout', [
                'values' => $result->values(),
                'errors' => $result->validation(),
            ], status: $result->status());
        }

        // require() returns CheckoutInput: the result carries the DTO type
        $input = $result->require();

        $order = $this->orders->place($input->email, $input->quantity);
        $this->redirectToRoute('shop:confirmation', ['id' => $order->id]);
    }
}
```

From a test, both encodings work through the same action:

```php
$client->post('/shop/checkout/', ['form' => ['email' => 'ada@example.test']])
    ->assertStatus(303);

$client->post('/shop/checkout/', ['json' => ['email' => 'ada@example.test']])
    ->assertStatus(303);
```

Generate the form action with `$url('shop:checkout')` so templates never
hardcode paths. See [Views](views.md) and [Testing](testing.md).

For rich server-rendered form presentation and progressive enhancement, the
companion [`kaly-forms`](recipes/kaly-forms.md) library builds on these values
and violations. Kaly's native forms cover request input and validation;
`kaly-forms` adds the rendering and interaction layer.
