<?php

// This file is included by the view engine
// It helps avoiding doing $this->h($value) and allows doing h($value) directly

use Kaly\View\Engine;

function h(mixed $v): string
{
    return Engine::getGlobalEscaper()->escape($v, 'h');
}

function a(mixed $v): string
{
    return Engine::getGlobalEscaper()->escape($v, 'a');
}

function u(mixed $v): string
{
    return Engine::getGlobalEscaper()->escape($v, 'u');
}

function c(mixed $v): string
{
    return Engine::getGlobalEscaper()->escape($v, 'c');
}

function j(mixed $v): string
{
    return Engine::getGlobalEscaper()->escape($v, 'j');
}
