---
layout: default
title: Mailer
nav_order: 21
---
# Mailer

Kaly does not provide a `MailerInterface` and has no `Kaly\Mail` namespace. This is deliberate.

> Kaly defines an abstraction when the framework needs a capability. Email delivery is an application service: Symfony Mailer already provides a complete standalone abstraction and transport ecosystem, so Kaly only documents how to wire it.

Unlike `RendererInterface` (Kaly needs the concept "render this View"), there is no Kaly-specific concept for email. Re-creating an `Email` model would mean re-building `symfony/mime` (multiple addresses, CC/BCC, envelope sender, HTML/text multipart, attachments, inline CID, custom headers) in a worse version. So: use Symfony Mailer directly. It works standalone with `Transport::fromDsn()`, `Mailer` and `Mime\Email`; no FrameworkBundle needed.

## Install

```bash
composer require symfony/mailer
```

## Wiring

Bind Symfony's `MailerInterface` in the container. `App` loads the `.env` file, so `$_ENV['MAILER_DSN']` is available:

```php
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;

$definitions->set(
    MailerInterface::class,
    static fn() => new Mailer(
        Transport::fromDsn($_ENV['MAILER_DSN'])
    ),
);
```

A factory only becomes justified once it carries a Kaly policy (PSR-3 logger, extra transport factories, worker reset, shared defaults). One line of `Transport::fromDsn()` does not need a name.

## DSN

```env
# Local development with Buggregator (SMTP catcher)
MAILER_DSN=smtp://buggregator:1025

# Production SMTP
MAILER_DSN=smtp://user:password@mail.example.com:587

# Local sendmail binary, when the machine is configured for it
MAILER_DSN=sendmail://default

# Available, but not recommended: you don't control the underlying
# configuration (sendmail_path, Windows behavior differs)
# MAILER_DSN=native://default
```

Prefer `smtp://`, including in development with Buggregator. Use `sendmail://default` when the host is configured that way. `native://default` exists but Symfony itself recommends avoiding it when possible.

## Sending

`Symfony\Component\Mime\Email` accepts `text()` and `html()` directly:

```php
use Symfony\Component\Mime\Email;

$email = (new Email())
    ->from('no-reply@example.com')
    ->to('user@example.com')
    ->subject('Welcome')
    ->text('Welcome aboard!')
    ->html('<strong>Welcome aboard!</strong>');

$mailer->send($email);
```

An application may naturally produce that HTML with its `RendererInterface` if that suits it, but there is no conceptual dependency between the mailer and the renderer: the mailer transports MIME, how the application produces its HTML is the application's business.

## Providers

Third-party providers are covered by Symfony Mailer bridges: install the corresponding bridge and use its DSN. See the [Symfony Mailer documentation](https://symfony.com/doc/current/mailer.html) for the full list of officially supported bridges. Example with one of them:

```bash
composer require symfony/resend-mailer
```

```env
MAILER_DSN=resend+api://KEY@default
```

## Testing

To disable delivery, use the null transport:

```env
MAILER_DSN=null://default
```

Test the business logic before the Mailer layer: delivery intents, provider fakes or an outbox in automated tests, with only a few integration tests hitting the real adapter.

