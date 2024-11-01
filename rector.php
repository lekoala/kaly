<?php

use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictNativeCallRector;
use Rector\TypeDeclaration\Rector\ClassMethod\NumericReturnTypeFromStrictScalarReturnsRector;
use Rector\TypeDeclaration\Rector\ClassMethod\StringReturnTypeFromStrictScalarReturnsRector;
use Rector\TypeDeclaration\Rector\ClassMethod\BoolReturnTypeFromBooleanConstReturnsRector;

return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withSkip([
        // Don't use constructor promotion
        ClassPropertyAssignToConstructorPromotionRector::class
    ])
    ->withRules([
        ReturnTypeFromStrictNativeCallRector::class,
        BoolReturnTypeFromBooleanConstReturnsRector::class,
        NumericReturnTypeFromStrictScalarReturnsRector::class,
        StringReturnTypeFromStrictScalarReturnsRector::class,
    ])
    ->withPreparedSets(typeDeclarations: true)
    ->withPhpSets(php82: true);
