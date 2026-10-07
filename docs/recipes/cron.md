---
layout: default
title: Cron recipe
nav_order: 5
---
# Cron recipe

Cron jobs are one-shot CLI scripts on the [shared console bootstrap](console.md):
boot once, run one use case, exit. Each run opens the resources it needs and
closes them before exiting:

```php
// bin/send-reminders.php
$app = require dirname(__DIR__) . '/bootstrap.php';
$app->boot();

$app->container()->get(SendReminders::class)();
```

```text
0 7 * * * php /srv/app/bin/send-reminders.php
```

No scheduler inside the framework, no simulated request: the schedule lives in
cron (or systemd timers), the work in an application use case.
