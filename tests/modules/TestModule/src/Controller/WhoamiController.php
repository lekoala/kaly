<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;
use Kaly\View\View;

/**
 * Renders the request identity for isolation tests (see RequestIsolationTest)
 */
class WhoamiController extends AbstractController
{
    public function index(): View
    {
        return new View('@TestModule/whoami');
    }
}
