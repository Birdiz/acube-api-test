<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\FuncCall\SortCallLikeNamedArgsRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;

/**
 * There is no legacy here to modernise, so the upgrade sets are not the point:
 * these are the rules that keep hand-written code honest — dead code, missing
 * type declarations, and expressions that say more than they mean.
 *
 * The skips are rules that would rewrite deliberate choices. Each one is a
 * decision this codebase already made, not a rule left unconsidered.
 */
return RectorConfig::configure()
    ->withPaths([__DIR__ . '/src', __DIR__ . '/tests'])
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withSkip([
        // Entity properties carry their Doctrine mapping. Promoting them folds
        // #[ORM\Column] attributes into the constructor signature, where the
        // mapping stops being readable next to the field it maps.
        ClassPropertyAssignToConstructorPromotionRector::class,

        // The API Platform operations are ordered on purpose — "Status first: an
        // IRI resolves to the first item GET declared" — and their arguments are
        // grouped by what they turn off. Alphabetising both loses the reason.
        SortCallLikeNamedArgsRector::class,

        // `(new Foo())->bar()` over 8.4's `new Foo()->bar()`: the parentheses are
        // two characters that every PHP reader already parses.
        NewMethodCallWithoutParenthesesRector::class,
    ]);
