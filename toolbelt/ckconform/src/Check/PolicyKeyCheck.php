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
 * The asymmetry this closes: an inline `// ckconform-ignore FooChekc` has been
 * reported as "a dead ignore never matches" for as long as the tool has
 * existed, while `min_covrage=70` in the policy file disabled a coverage floor
 * in complete silence. Same typo, same consequence — an intended rule that
 * quietly does not apply — and only one of them was caught. Nothing read the
 * key list as a list, because there was no list: ckconform knew nine keys, the
 * ck* tools brought six more of their own, ckinit one.
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
        $raw = $context->read('.ckconform');
        if ($raw === null) {
            return;
        }

        foreach (Policy::parse($raw) as $key => $values) {
            if (!isset(Policy::KEYS[$key])) {
                $reporter->fail(sprintf(
                    ".ckconform: unknown key '%s' — nothing reads it, so the policy it declares does not apply%s",
                    $key,
                    $this->didYouMean($key),
                ));

                continue;
            }

            if (count($values) > 1 && !in_array($key, Policy::REPEATABLE, true)) {
                $reporter->fail(sprintf(
                    ".ckconform: %s is declared %d times but only the first is read — %s takes a comma-separated value on one line",
                    $key,
                    count($values),
                    $key,
                ));
            }

            if (!in_array($key, Policy::PERCENT, true)) {
                continue;
            }

            foreach ($values as $value) {
                $number = Policy::stripReason($value);
                if (preg_match('/^\d{1,3}$/', $number) !== 1 || (int) $number > 100) {
                    $reporter->fail(sprintf(
                        ".ckconform: %s must be a whole percentage 0-100, got '%s'",
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
