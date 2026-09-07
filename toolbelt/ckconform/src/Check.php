<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform;

/**
 * One conformance rule.
 *
 * Every check must be able to run against an arbitrary directory, because that
 * is how it gets tested: a fixture repo that should pass and one that should
 * fail. A rule with no failing fixture has never been shown to fire.
 */
interface Check
{
    /** Stable identifier, used by tests and by --only. */
    public function name(): string;

    public function run(Context $context, Reporter $reporter): void;
}
