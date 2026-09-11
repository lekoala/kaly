<?php

declare(strict_types=1);

namespace Kaly\View;

/**
 * Interface for template rendering engines.
 *
 * This is the full-featured contract of the built-in engine. Renderer
 * implementations that only need the minimal contract should implement
 * RendererInterface (optionally plus the capability interfaces).
 */
interface EngineInterface extends RendererInterface, TemplateLocatorInterface, TemplatePathRegistryInterface {}
