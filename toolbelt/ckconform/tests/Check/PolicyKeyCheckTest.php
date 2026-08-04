<?php

declare(strict_types=1);

namespace CiviKitchen\Ckconform\Tests\Check;

use CiviKitchen\Ckconform\Check\PolicyKeyCheck;
use CiviKitchen\Ckconform\Policy;
use CiviKitchen\Ckconform\Tests\CheckTestCase;

final class PolicyKeyCheckTest extends CheckTestCase
{
    public function testNoPolicyFileIsSilent(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([]));
        $this->assertSilent($reporter);
    }

    public function testKnownKeysPass(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '.ckconform' => "# policy\nmin_coverage=70\ntests=optional -- config only\ndist_exclude=build\n",
        ]));
        $this->assertPasses($reporter);
    }

    /** The bug this check exists for: a typo disables a floor in silence. */
    public function testTypoedKeyFails(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '.ckconform' => "min_covrage=70\n",
        ]));
        $this->assertFails($reporter, "unknown key 'min_covrage'");
        $this->assertFails($reporter, "did you mean 'min_coverage'");
    }

    public function testUnrelatedUnknownKeyFailsWithoutASuggestion(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '.ckconform' => "enable_everything=yes\n",
        ]));
        $this->assertFails($reporter, "unknown key 'enable_everything'");
        self::assertStringNotContainsString('did you mean', $reporter->render());
    }

    /**
     * @dataProvider badPercentages
     */
    public function testNonNumericPercentageFails(string $value): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '.ckconform' => "min_coverage={$value}\n",
        ]));
        $this->assertFails($reporter, 'must be a whole percentage');
    }

    /** @return array<string, list<string>> */
    public static function badPercentages(): array
    {
        return [
            'a word' => ['seventy'],
            'a percent sign' => ['70%'],
            'over 100' => ['101'],
            'a fraction' => ['70.5'],
        ];
    }

    /** A reason on a numeric key is legal, and must not be compared as part of the number. */
    public function testPercentageWithAReasonPasses(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '.ckconform' => "min_coverage=70 -- measured 2026-01, ratchet from here\n",
        ]));
        $this->assertPasses($reporter);
    }

    /** A second line of a first-wins key does nothing, and said so to nobody. */
    public function testRepeatedScalarKeyFails(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '.ckconform' => "template_custom=a.yml -- one\ntemplate_custom=b.yml -- two\n",
        ]));
        $this->assertFails($reporter, 'declared 2 times but only the first is read');
    }

    public function testRepeatedRepeatableKeyPasses(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '.ckconform' => "lifecycle_log_ignore=one -- a\nlifecycle_log_ignore=two -- b\n",
        ]));
        $this->assertPasses($reporter);
    }

    public function testEveryMutationFloorIsValidated(): void
    {
        $reporter = $this->run_(new PolicyKeyCheck(), $this->repo([
            '.ckconform' => "mutation_min_msi=high\nmutation_min_covered_msi=also_high\n",
        ]));
        self::assertCount(2, $reporter->messages('FAIL'));
    }

    /**
     * The inventory is only useful if it is complete: a key a tool reads but
     * KEYS does not list would be reported as unknown in every repo using it.
     * This pins the two directions that drift — the checks' own policyValue()
     * calls, and the keys the ck* tools read through --policy/--policy-env.
     */
    public function testInventoryCoversEveryKeyTheToolbeltReads(): void
    {
        $root = dirname(__DIR__, 3);
        $sources = [];
        foreach ([$root . '/ckconform/src', $root . '/bin', $root . '/../tools'] as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $sources[] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        $haystack = implode("\n", $sources);

        $read = [];
        // ckconform's own checks: $context->policyValue('<key>')
        preg_match_all("/policyValue\('([a-z_]+)'\)/", $haystack, $m);
        $read = array_merge($read, $m[1]);
        // the ck* tools: ckconform --policy <key> / $CK_POLICY_<KEY>
        preg_match_all('/--policy ([a-z_]+)/', $haystack, $m);
        $read = array_merge($read, $m[1]);
        preg_match_all('/CK_POLICY_([A-Z_]+)/', $haystack, $m);
        $read = array_merge($read, array_map('strtolower', $m[1]));

        $missing = array_diff(array_unique($read), array_keys(Policy::KEYS));
        self::assertSame(
            [],
            array_values($missing),
            'read by the toolbelt but missing from Policy::KEYS: ' . implode(', ', $missing),
        );
    }
}
