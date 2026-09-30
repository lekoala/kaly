# Forms

A form is a `RequestInput`: a typed object mapped from the query string and the
body, validated on construction. See [Request input](input.md).

```php
final class CheckoutInput implements RequestInput
{
    public function __construct(
        public readonly string $email,
        public readonly int $quantity = 1,
    ) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('email must be a valid address');
        }
    }
}
```

Declare it as the trailing action parameter. The dispatcher builds it from the
request; a `ValidationException` becomes a 422 response:

```php
public function checkout(CheckoutInput $input): never
{
    $order = $this->orders->place($input->email, $input->quantity);
    $this->redirectToRoute('shop:confirmation', ['id' => $order->id]);
}
```

From a test, both encodings work through the same action:

```php
$client->post('/shop/checkout/', ['form' => ['email' => 'ada@example.test']])
    ->assertStatus(303);

$client->post('/shop/checkout/', ['json' => ['email' => 'ada@example.test']])
    ->assertStatus(303);
```

On validation failure, re-render the `View` with the input and its errors
(422) rather than redirecting: a redirect would lose what the user typed.
Generate the form action with `$url('shop:checkout')` so templates never
hardcode paths. See [Views](views.md) and [Testing](testing.md).
