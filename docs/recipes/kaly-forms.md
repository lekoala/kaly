---
layout: default
title: kaly-forms recipe
nav_order: 6
---
# kaly-forms recipe

[`lekoala/kaly-forms`](https://github.com/lekoala/kaly-forms) is the companion
library for server-rendered HTML form presentation and interaction metadata.

Kaly stays the source of truth for the request: input mapping, validation, CSRF,
routing and rendering. `kaly-forms` only turns the values and errors Kaly
produced into a self-rendering `Form`. The package core has no Kaly dependency,
so the same `Form` / `FormState` / renderer trio also runs on Slim, Mezzio or
plain PSR-7.

```bash
composer require lekoala/kaly-forms
```

## The split

| Concern                                   | Owner         |
|-------------------------------------------|---------------|
| query/body mapping into a typed DTO       | Kaly          |
| authoritative validation and `Violation`  | Kaly          |
| CSRF token, route-generated action URL    | Kaly          |
| form definition and field semantics       | `kaly-forms`  |
| submitted values + display errors         | `kaly-forms`  |
| HTML structure, theme, progressive enhancement | `kaly-forms` |
| template rendering                         | Kaly views    |

Kaly's native `RequestInput` / `ValidatableInput` already cover the left column
(see [Forms](../forms.md) and [Request input](../input.md)); `kaly-forms` adds
the presentation layer on the right.

## Wiring one render

The integration is a mapping between Kaly's `InputMapperInterface` result and
`kaly-forms`:

```php
use Kaly\Core\HttpContext;
use Kaly\Core\ViolationMessageResolver;
use Kaly\Forms\Fields;
use Kaly\Forms\FormFactory;
use Kaly\Forms\FormState;
use Kaly\Http\Input\InputMapperInterface;
use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\TranslatorInterface;
use Kaly\View\View;
use Psr\Http\Message\ServerRequestInterface;

final class RegistrationController extends AbstractController
{
    public function __construct(
        ServerRequestInterface $request,
        private InputMapperInterface $inputs,
        private TranslatorInterface $translator,
        private FormFactory $forms,
        private Fields $fields,
    ) {
        parent::__construct($request);
    }

    public function edit(): View
    {
        $i18n = new LocalizedTranslator($this->translator, HttpContext::from($this->request)->locale());
        $violations = new ViolationMessageResolver($i18n);

        $result = $this->inputs->mapResult($this->request, RegistrationInput::class);

        $form = $this->forms
            ->create(name: 'registration', action: '/register/', children: [
                $this->fields->email(name: 'email', label: 'Email', required: true),
                $this->fields->password(name: 'password', label: 'Password', required: true),
            ])
            ->withState(FormState::from(
                values: $result->values(),
                errors: formErrors($result->validation(), $violations),
            ));

        return new View('registration/edit', ['form' => $form]);
    }
}
```

The form definition is created once and reused: `withState()` carries the values
and errors of this render only, so a shared controller or worker never leaks one
visitor's submission into the next. `Fields` is the authoring facade backed by
`FieldTypes`; both are wired as shared services at the composition root.

## Mapping violations to form errors

`kaly-forms` deliberately knows nothing about Kaly's `Violation`. `FormError`
holds an already resolved message, and translating `Violation` into it is the
application adapter's job. `Kaly\Core\ViolationMessageResolver` composes the
violation and the translator, so the adapter names neither `messageId`,
`domain`, `parameters` nor the fallback rule:

```php
use Kaly\Core\ViolationMessageResolver;
use Kaly\Forms\FormError;
use Kaly\Validation\ValidationResult;
use Kaly\Validation\Violation;

/**
 * @return list<FormError>
 */
function formErrors(ValidationResult $result, ViolationMessageResolver $violations): array
{
    $errors = [];
    foreach ($result->violations() as $violation) {
        $errors[] = new FormError(
            message: $violations->resolve($violation),
            field: $violation->field,
            code: $violation->code,
        );
    }

    return $errors;
}
```

`LocalizedExceptionHandler` uses the same resolver for HTTP error bodies. The
resolver takes the `LocalizedTranslator` of the current request (see
[i18n](../i18n.md#resolving-a-violation)).

`$i18n` is a `LocalizedTranslator`: in a view it is the reserved `i18n`
variable, elsewhere build it from the injected `TranslatorInterface` and
`$ctx->locale()` (see [i18n](../i18n.md#no-implicit-translation-context)).

## Rendering

A `Form` is `HtmlRenderable`, so it prints itself through the renderer it was
built with. kaly-tpl and plain PHP do not auto-escape, so the usual echo is
already correct:

```php
<?= $form ?>
```

Twig and Latte auto-escape variable output, so they need the optional bridge
that converts `HtmlRenderable` to the engine's native safe type:

```twig
{{ form }}
```

Register `Kaly\Forms\Bridge\Twig\FormExtension` or
`Kaly\Forms\Bridge\Latte\FormExtension` once. No `form_start()` /
`form_row()` DSL is required in the template.

## Substituting fields at the composition root

Which concrete `Field` represents a concept is a composition decision, not a
`Form` concern. Configure `FieldTypes` in the Kaly composition root; `Form`
never sees a container:

```php
use Kaly\Di\Definitions;
use Kaly\Forms\Field\DateField;
use Kaly\Forms\FieldTypes;

$app->configure(static function (Definitions $di): void {
    $di->callback(
        FieldTypes::class,
        static function (FieldTypes $types): void {
            $types->register(DateField::class, static fn (...$args): DateField => new CalendarDateField(...$args));
        },
    );
});
```

Change the renderer when only the markup changes; substitute the field through
`FieldTypes` when the model itself changes; change the `FormTheme` when only
decoration changes.

## Notes for Kaly

This integration surfaced two Kaly-side observations, recorded in the core docs:

- **HTTP error rendering.** An application wants to render an HTTP error with
  its normal renderer (`404 → error/404`, `429 → error/429`, `500 → error/500`).
  Kaly exposes that seam through `ExceptionHandlerInterface` and
  `ErrorViewInterface`; no parallel `ErrorResponderInterface` is added. An
  exception opts in by leaving its public body empty, which
  `Kaly\Http\Exception\TooManyRequestsException` does. See
  [Custom HTML error pages](../application-structure.md#custom-html-error-pages).
- **Violation i18n.** `Kaly\Core\ViolationMessageResolver` composes
  `Validation` and `I18n` so an adapter resolves a `Violation` with one call.
  See [Resolving a violation](../i18n.md#resolving-a-violation).

## See also

- [`kaly-forms` documentation](https://github.com/lekoala/kaly-forms)
- [Forms](../forms.md) — Kaly's native request input and validation
- [Request input](../input.md)
- [i18n](../i18n.md)
