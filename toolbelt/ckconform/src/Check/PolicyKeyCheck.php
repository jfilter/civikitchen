<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Check;

use CiviKitchen\Ckconform\Check;
use CiviKitchen\Ckconform\Context;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Reporter;

/**
 * Keeps `.ckconform` itself honest — the policy-file counterpart to
 * SuppressionHygieneCheck.
 *
 * The asymmetry it closes: a typo'd check name in an inline `ckconform-ignore`
 * has always been reported as "a dead ignore never matches", while
 * `min_covrage=70` disabled a coverage floor in silence. Same typo, same
 * consequence, and only one of them was caught.
 *
 * Three failure modes, all silent by nature:
 *
 *   unknown key   nothing will ever read it, so the policy it expresses does
 *                 not exist. Reported against Policy::KEYS, which is now the
 *                 single inventory.
 *   bad number    a percentage that is not one. `min_coverage=seventy` reached
 *                 a numeric comparison as a string, and `min_coverage=70%`
 *                 likewise — both compare as 0, which passes every floor.
 *   repeated key  every key but lifecycle_log_ignore is first-wins, so a
 *                 second line is not an addition, it is a line that does
 *                 nothing. A repo declaring two `template_custom=` lines gets
 *                 one honoured and one ignored without being told.
 *   malformed     a non-comment line without `KEY=` never becomes a key, so
 *                 the three checks above cannot see it — `min_coverage 70`
 *                 disables a floor as silently as the typo'd name does.
 *   bad source    an `extension_source` that is not `<key>@<http URL>` never
 *                 matches the dependency it was written for, so the
 *                 entrypoint falls back to the registry — the exact download
 *                 the pin was declared to avoid.
 *
 * Both layers are checked — the repo's `.ckconform` and the CK_DEFAULT_POLICY
 * file — each finding named after the file it came from.
 *
 * What is deliberately NOT here: whether a value means the right thing. The
 * key's owner judges that, one tool per question — TestSuiteRequiredCheck is
 * the only thing that decides what `tests=` may say, and this check duplicating
 * it would put two verdicts on one line.
 */
final class PolicyKeyCheck implements Check
{
    public function name(): string
    {
        return 'policy-key';
    }

    public function run(Context $context, Reporter $reporter): void
    {
        // The defaults file is judged by the same rules, under its own name:
        // a typo there disables a policy for the whole fleet at once.
        try {
            $defaults = Policy::defaultsRaw();
        } catch (\RuntimeException $e) {
            $reporter->fail($e->getMessage());
            $defaults = null;
        }
        if ($defaults !== null) {
            $source = Policy::DEFAULTS_ENV . ' (' . getenv(Policy::DEFAULTS_ENV) . ')';
            $this->validate($source, $defaults, $reporter);
            foreach (array_keys(Policy::parse($defaults)) as $key) {
                if (isset(Policy::KEYS[$key]) && !in_array($key, Policy::SHARED, true)) {
                    $reporter->fail(sprintf(
                        '%s: %s is read by a gate the defaults do not reach — set it in the repo .ckconform (shareable: %s)',
                        $source,
                        $key,
                        implode(', ', Policy::SHARED),
                    ));
                }
            }
        }

        $raw = $context->read('.ckconform');
        if ($raw !== null) {
            $this->validate('.ckconform', $raw, $reporter);
        }
    }

    private function validate(string $source, string $raw, Reporter $reporter): void
    {
        foreach (Policy::malformed($raw) as $lineNo => $line) {
            $reporter->fail(sprintf(
                "%s:%d: no KEY=VALUE in '%s' — the line declares nothing",
                $source,
                $lineNo,
                $line,
            ));
        }

        foreach (Policy::parse($raw) as $key => $values) {
            if (!isset(Policy::KEYS[$key])) {
                $reporter->fail(sprintf(
                    "%s: unknown key '%s' — nothing reads it, so the policy it declares does not apply%s",
                    $source,
                    $key,
                    $this->didYouMean($key),
                ));

                continue;
            }

            if (count($values) > 1 && !in_array($key, Policy::REPEATABLE, true)) {
                $reporter->fail(sprintf(
                    "%s: %s is declared %d times but only the first is read — %s takes a comma-separated value on one line",
                    $source,
                    $key,
                    count($values),
                    $key,
                ));
            }

            if ($key === 'extension_source') {
                foreach ($values as $value) {
                    $spec = Policy::stripReason($value);
                    if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._-]*@https?://\S+$#', $spec) !== 1) {
                        $reporter->fail(sprintf(
                            "%s: extension_source must be <key>@<http URL> (what cv ext:download takes), got '%s'",
                            $source,
                            $spec,
                        ));
                    }
                }
            }

            if (!in_array($key, Policy::PERCENT, true)) {
                continue;
            }

            foreach ($values as $value) {
                $number = Policy::stripReason($value);
                if (preg_match('/^\d{1,3}$/', $number) !== 1 || (int) $number > 100) {
                    $reporter->fail(sprintf(
                        "%s: %s must be a whole percentage 0-100, got '%s'",
                        $source,
                        $key,
                        $number,
                    ));
                }
            }
        }
    }

    /**
     * A typo is the overwhelmingly likely cause, so name the nearest key when
     * there is an obvious one. Distance 3 over key lengths of 5-24 characters
     * catches a transposition or a dropped letter without pairing unrelated
     * keys ('license' and 'vendor' stay 6 apart).
     */
    private function didYouMean(string $key): string
    {
        $best = null;
        $bestDistance = 4;
        foreach (array_keys(Policy::KEYS) as $known) {
            $distance = levenshtein($key, $known);
            if ($distance < $bestDistance) {
                $best = $known;
                $bestDistance = $distance;
            }
        }

        return $best === null ? '' : " (did you mean '{$best}'?)";
    }
}
