<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use CiviKitchen\Mago\Options;
use InvalidArgumentException;
use Mago\Sdk\Analyzer\Codebase;
use Mago\Sdk\Analyzer\IssueFilterContext;
use Mago\Sdk\Analyzer\IssueFilterDecision;
use Mago\Sdk\Analyzer\IssueFilterHook;
use Mago\Sdk\Analyzer\Metadata\AttributeMetadata;
use Mago\Sdk\Analyzer\Metadata\MetadataFlags;

/**
 * With --compatibility=<newest PHP> (ckcompat), keeps only what PHP itself
 * deprecates by that version: drops the project's own deprecations and those
 * PHP's `#[Deprecated(since: …)]` dates after the newest version checked.
 */
final class BuiltinDeprecationsFilterHook implements IssueFilterHook
{
    private const CODES = ['deprecated-function', 'deprecated-method', 'deprecated-class', 'deprecated-trait', 'deprecated-constant'];

    private readonly ?string $newest;

    public function __construct(Options $options)
    {
        $this->newest = $options->string('compatibility');
        if ($this->newest !== null && preg_match('/^\d+\.\d+$/', $this->newest) !== 1) {
            throw new InvalidArgumentException("civikitchen mago extension: --compatibility needs a PHP MAJOR.MINOR, got {$this->newest}");
        }
    }

    public function getCodes(): array
    {
        return self::CODES;
    }

    public function filterIssue(IssueFilterContext $context): IssueFilterDecision
    {
        if ($this->newest === null || preg_match('/`([^`]+)`/', $context->issue->message, $symbol) !== 1) {
            return IssueFilterDecision::Keep;
        }
        $metadata = self::metadata($context->codebase, $context->issue->code ?? '', ltrim($symbol[1], '\\'));
        if ($metadata === null) {
            return IssueFilterDecision::Keep;
        }
        $since = self::since($metadata['attributes']);

        return $metadata['flags']->contains(MetadataFlags::USER_DEFINED) || ($since !== null && version_compare($since, $this->newest, '>'))
            ? IssueFilterDecision::Remove
            : IssueFilterDecision::Keep;
    }

    /** @param list<AttributeMetadata> $attributes */
    private static function since(array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            if (strcasecmp(substr((string) strrchr('\\' . $attribute->name, '\\'), 1), 'Deprecated') !== 0) {
                continue;
            }
            foreach ($attribute->arguments as $argument) {
                if ($argument->name === 'since') {
                    return $argument->valueType?->getLiteralString();
                }
            }
        }

        return null;
    }

    /** @return array{flags: MetadataFlags, attributes: list<AttributeMetadata>}|null */
    private static function metadata(Codebase $codebase, string $code, string $symbol): ?array
    {
        $found = match ($code) {
            'deprecated-function' => $codebase->getFunction($symbol),
            'deprecated-method' => str_contains($symbol, '::') ? $codebase->getMethod(...explode('::', $symbol, 2)) : null,
            'deprecated-constant' => $codebase->getConstant($symbol),
            default => $codebase->getClassLike($symbol),
        };

        return $found === null ? null : ['flags' => $found->flags, 'attributes' => $found->attributes ?? []];
    }
}
