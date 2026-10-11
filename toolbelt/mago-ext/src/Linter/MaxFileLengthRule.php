<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Linter;

use CiviKitchen\Mago\Options;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Span;
use Mago\Sdk\Syntax\NodeKind;

/** Caps a file's physical line count (`max-lines`, default 1000), reported on line 1. */
final class MaxFileLengthRule implements Rule
{
    private readonly int $maxLines;

    public function __construct(Options $options)
    {
        $this->maxLines = $options->int('max-lines', 1000);
    }

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: 'civikitchen/max-file-length',
            name: 'Max file length',
            description: 'A file stays under the configured line count.',
            defaultLevel: Level::Error,
            defaultEnabled: true,
            targets: [NodeKind::Program],
        );
    }

    public function lint(LintContext $context): void
    {
        $contents = $context->file->contents;
        if ($contents === '') {
            return;
        }
        // The line the last character sits on: a trailing newline opens no new line.
        $lines = substr_count($contents, "\n", 0, strlen($contents) - 1) + 1;
        if ($lines > $this->maxLines) {
            $context->report(Issue::new(
                "File is {$lines} lines long; the maximum is {$this->maxLines}. Split it into focused classes.",
                new Span(0, strcspn($contents, "\n")),
            ));
        }
    }
}
