<?php

declare(strict_types=1);

namespace Tests\Feature\Ecs;

use Tests\TestCase;

/**
 * Proves the approved S3 backend configured 2026-08-03
 * (infrastructure/ecs/environments/staging/versions.tf), the required
 * Terraform CLI version, the ignore rules protecting against accidental
 * local-backend-artifact commits, and scripts/tf-guard.sh's updated
 * version-gate all match what was actually approved — read directly
 * against the real committed source, mirroring this repo's existing
 * SesConsumerTerraformIamTest/RedisTlsConfigurationTest philosophy
 * (real files, never a live `terraform plan`/`apply`, no AWS contact).
 *
 * The tf-guard.sh *behavioral* assertions below invoke the real,
 * committed script as a subprocess, but only ever with a small fake
 * "terraform" stub standing in for TF_GUARD_REAL_TERRAFORM (never the
 * real Terraform binary, never AWS credentials) — see makeFakeTerraform()
 * — so these tests never contact AWS and never initialize the real S3
 * backend.
 */
class StagingTerraformBackendConfigurationTest extends TestCase
{
    private const STAGING_DIR = 'infrastructure/ecs/environments/staging';

    private function versionsTf(): string
    {
        return $this->readFile(self::STAGING_DIR.'/versions.tf');
    }

    private function backendBlock(): string
    {
        preg_match('/backend\s+"s3"\s*\{.*?\n  \}/s', $this->versionsTf(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate the backend "s3" { ... } block in versions.tf.');

        return $matches[0];
    }

    private function terraformBlock(): string
    {
        preg_match('/^terraform\s*\{.*\n\}/ms', $this->versionsTf(), $matches);
        $this->assertNotEmpty($matches, 'Could not locate the top-level terraform { ... } block in versions.tf.');

        return $matches[0];
    }

    private function gitignore(): string
    {
        return $this->readFile('infrastructure/ecs/.gitignore');
    }

    private function tfGuardSource(): string
    {
        return $this->readFile(self::STAGING_DIR.'/scripts/tf-guard.sh');
    }

    private function readFile(string $relativePath): string
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, "Failed to read {$relativePath}");

        return $contents;
    }

    // ------------------------------------------------------------
    // required_version
    // ------------------------------------------------------------

    public function test_required_version_requires_at_least_1_15_0(): void
    {
        $block = $this->terraformBlock();

        $this->assertMatchesRegularExpression(
            '/required_version\s*=\s*">=\s*1\.15\.0,\s*<\s*2\.0\.0"/',
            $block,
            'required_version must require >= 1.15.0, < 2.0.0 (the S3 backend\'s use_lockfile locking needs Terraform 1.11+; 1.15+ is the specific approved version).'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/required_version\s*=\s*">=\s*1\.7"/',
            $block,
            'The stale ">= 1.7" required_version must not remain.'
        );
    }

    // ------------------------------------------------------------
    // Backend block — exact approved values
    // ------------------------------------------------------------

    public function test_backend_type_is_exactly_s3(): void
    {
        $this->assertMatchesRegularExpression('/backend\s+"s3"\s*\{/', $this->terraformBlock());
    }

    public function test_backend_bucket_is_exact(): void
    {
        $this->assertMatchesRegularExpression(
            '/bucket\s*=\s*"firmsbase-terraform-state-603013471426-us-east-1"/',
            $this->backendBlock()
        );
    }

    public function test_backend_key_is_exact(): void
    {
        $this->assertMatchesRegularExpression(
            '/key\s*=\s*"environments\/staging\/ecs\/terraform\.tfstate"/',
            $this->backendBlock()
        );
    }

    public function test_backend_region_is_exact(): void
    {
        $this->assertMatchesRegularExpression('/region\s*=\s*"us-east-1"/', $this->backendBlock());
    }

    public function test_backend_encrypt_is_true(): void
    {
        $this->assertMatchesRegularExpression('/encrypt\s*=\s*true/', $this->backendBlock());
    }

    public function test_backend_use_lockfile_is_true(): void
    {
        $this->assertMatchesRegularExpression('/use_lockfile\s*=\s*true/', $this->backendBlock());
    }

    // ------------------------------------------------------------
    // Backend block — must never contain a credential/DynamoDB/workspace
    // ------------------------------------------------------------

    public function test_backend_block_contains_no_credentials_or_profile(): void
    {
        $block = $this->backendBlock();

        foreach (['profile', 'access_key', 'secret_key', 'token', 'shared_credentials_file', 'role_arn'] as $forbidden) {
            $this->assertDoesNotMatchRegularExpression(
                '/\b'.preg_quote($forbidden, '/').'\s*=/',
                $block,
                "backend \"s3\" {} must never set {$forbidden} — credential resolution is left to the environment (AWS_PROFILE/instance role/OIDC)."
            );
        }
    }

    public function test_no_dynamodb_locking_is_configured(): void
    {
        $block = $this->backendBlock();

        $this->assertStringNotContainsString('dynamodb_table', $block, 'This backend uses native S3 lockfile locking (use_lockfile) — no DynamoDB table.');
        $this->assertStringNotContainsString('workspace_key_prefix', $block, 'No workspace prefix is used for this environment.');
        $this->assertStringNotContainsString('endpoint', $block, 'No custom/public S3 endpoint is used — the real AWS S3 endpoint is implicit.');
    }

    public function test_aws_provider_configuration_is_retained_unchanged(): void
    {
        $main = $this->versionsTf();

        $this->assertMatchesRegularExpression('/provider\s+"aws"\s*\{/', $main);
        $this->assertMatchesRegularExpression('/region\s*=\s*var\.aws_region/', $main);
        $this->assertMatchesRegularExpression('/required_providers\s*\{[^}]*aws\s*=\s*\{[^}]*source\s*=\s*"hashicorp\/aws"[^}]*version\s*=\s*"~>\s*5\.0"/s', $main);
    }

    // ------------------------------------------------------------
    // .gitignore
    // ------------------------------------------------------------

    public function test_tflock_files_are_ignored(): void
    {
        $this->assertMatchesRegularExpression('/^\*\.tflock$/m', $this->gitignore());
    }

    public function test_existing_ignore_rules_are_retained(): void
    {
        $gitignore = $this->gitignore();

        foreach (['*.tfstate', '*.tfstate.*', '*.tfplan', '.terraform/', 'terraform.tfvars', '*.auto.tfvars'] as $rule) {
            $this->assertStringContainsString($rule, $gitignore, "Pre-existing ignore rule '{$rule}' must be retained.");
        }
    }

    public function test_terraform_lock_hcl_is_not_ignored(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/^\.terraform\.lock\.hcl$/m',
            $this->gitignore(),
            '.terraform.lock.hcl must remain trackable — it pins provider versions for reproducibility.'
        );

        // And it must actually be a real, currently-tracked file — not merely
        // absent from .gitignore by coincidence.
        $tracked = trim((string) shell_exec(
            'git -C '.escapeshellarg(base_path()).' ls-files -- '
            .escapeshellarg(self::STAGING_DIR.'/.terraform.lock.hcl')
        ));
        $this->assertNotSame('', $tracked, '.terraform.lock.hcl must be a tracked file in git.');
    }

    public function test_gitignore_does_not_hide_backend_source_configuration(): void
    {
        $gitignore = $this->gitignore();

        $this->assertStringNotContainsString('versions.tf', $gitignore, '.gitignore must never hide the backend source configuration file itself.');
        $this->assertStringNotContainsString('*.tf'."\n", $gitignore, '.gitignore must never blanket-ignore .tf source files.');
    }

    // ------------------------------------------------------------
    // tf-guard.sh — static source assertions
    // ------------------------------------------------------------

    public function test_guard_no_longer_claims_the_backend_is_absent(): void
    {
        $source = $this->tfGuardSource();

        foreach (['today there is none', 'currently has no approved remote state backend'] as $stalePhrase) {
            $this->assertStringNotContainsString($stalePhrase, $source, "Stale phrase '{$stalePhrase}' must not remain now that the backend is approved and configured.");
        }

        $this->assertStringContainsString(
            'firmsbase-terraform-state-603013471426-us-east-1',
            $source,
            'The guard must reference the exact approved bucket name.'
        );
    }

    public function test_guard_documents_the_approved_terraform_version(): void
    {
        $source = $this->tfGuardSource();

        $this->assertStringContainsString('1.15.8', $source);
        $this->assertMatchesRegularExpression('/MIN_REQUIRED_MAJOR\s*=\s*1/', $source);
        $this->assertMatchesRegularExpression('/MIN_REQUIRED_MINOR\s*=\s*15/', $source);
    }

    public function test_guard_still_documents_the_bare_binary_bypass_limitation(): void
    {
        $this->assertStringContainsString('KNOWN BYPASS LIMITATION', $this->tfGuardSource());
        $this->assertStringContainsString('bypasses every check below entirely', $this->tfGuardSource());
    }

    public function test_guard_uses_numeric_not_string_version_comparison(): void
    {
        $source = $this->tfGuardSource();

        // The whole point of the numeric-comparison requirement: a naive
        // string compare would treat "1.9" as greater than "1.15". Assert
        // the guard extracts major/minor as separate integers and compares
        // with -gt/-ge, never a bare string [ "$a" > "$b" ] on the whole
        // version string.
        $this->assertStringContainsString('tf_major', $source);
        $this->assertStringContainsString('tf_minor', $source);
        $this->assertMatchesRegularExpression('/\[\s*"\$tf_major"\s*-gt\s*"\$MIN_REQUIRED_MAJOR"\s*\]/', $source);
        $this->assertMatchesRegularExpression('/\[\s*"\$tf_minor"\s*-ge\s*"\$MIN_REQUIRED_MINOR"\s*\]/', $source);
    }

    // ------------------------------------------------------------
    // tf-guard.sh — behavioral assertions (fake terraform stub only;
    // never the real binary, never AWS)
    // ------------------------------------------------------------

    /**
     * @param  array<string, string>  $responses  subcommand (e.g. "version -json") => stdout to echo
     */
    private function makeFakeTerraform(array $responses): string
    {
        $path = sys_get_temp_dir().'/fake-terraform-'.bin2hex(random_bytes(8)).'.sh';

        // Uses `case` (not `[[ == glob ]]`) for the substring match — a
        // glob pattern passed through escapeshellarg() ends up quoted in
        // the generated script, which disables glob expansion in a
        // `[[ ]]` test entirely; `case` patterns are never subject to
        // shell quoting removing their glob semantics the same way.
        $lines = ['#!/usr/bin/env bash', 'args="$*"', 'case "$args" in'];
        foreach ($responses as $match => $stdout) {
            $lines[] = '  *'.escapeshellarg($match).'*)';
            $lines[] = '    echo '.escapeshellarg($stdout);
            $lines[] = '    exit 0';
            $lines[] = '    ;;';
        }
        $lines[] = 'esac';
        $lines[] = 'echo "fake-terraform: unexpected invocation: $args" >&2';
        $lines[] = 'exit 1';

        file_put_contents($path, implode("\n", $lines)."\n");
        chmod($path, 0755);

        return $path;
    }

    private function runGuard(string $fakeTerraform, array $args, array $extraEnv = []): array
    {
        $guardPath = base_path(self::STAGING_DIR.'/scripts/tf-guard.sh');

        $env = array_merge([
            'TF_GUARD_REAL_TERRAFORM' => $fakeTerraform,
            'PATH' => getenv('PATH'),
        ], $extraEnv);

        $envPrefix = '';
        foreach ($env as $key => $value) {
            $envPrefix .= escapeshellarg("{$key}={$value}").' ';
        }

        $cmd = 'env -i '.$envPrefix.'bash '.escapeshellarg($guardPath).' '
            .implode(' ', array_map('escapeshellarg', $args)).' 2>&1';

        exec($cmd, $outputLines, $exitCode);

        return ['exit' => $exitCode, 'output' => implode("\n", $outputLines)];
    }

    public function test_guard_refuses_plan_with_an_old_terraform_version(): void
    {
        $fake = $this->makeFakeTerraform(['version -json' => '{"terraform_version":"1.9.8"}']);

        try {
            $result = $this->runGuard($fake, ['-chdir=/tmp', 'plan']);

            $this->assertSame(1, $result['exit'], 'Guard must refuse plan with Terraform 1.9.8.');
            $this->assertStringContainsString('1.9.8', $result['output']);
            $this->assertStringContainsString('requires >=', $result['output']);
        } finally {
            @unlink($fake);
        }
    }

    public function test_guard_permits_plan_version_check_with_an_approved_version(): void
    {
        // 1.15.8 passes the version check, then fails at the very next
        // check (account/region, since no real AWS credentials are given
        // here) — proving the version gate itself does not block an
        // approved version, without needing real AWS access.
        $fake = $this->makeFakeTerraform(['version -json' => '{"terraform_version":"1.15.8"}']);

        try {
            $result = $this->runGuard($fake, ['-chdir=/tmp', 'plan']);

            $this->assertSame(1, $result['exit']);
            $this->assertStringNotContainsString('requires >=', $result['output'], 'An approved version must not be refused by the version check.');
        } finally {
            @unlink($fake);
        }
    }

    public function test_guard_fails_closed_when_version_cannot_be_determined(): void
    {
        $fake = $this->makeFakeTerraform([]); // answers nothing, always errors

        try {
            $result = $this->runGuard($fake, ['-chdir=/tmp', 'plan']);

            $this->assertSame(1, $result['exit'], 'Guard must fail closed (refuse) when the version cannot be determined.');
            $this->assertStringContainsString('cannot determine the Terraform binary version', $result['output']);
        } finally {
            @unlink($fake);
        }
    }

    public function test_guard_still_permits_validate_fmt_and_init_backend_false(): void
    {
        // These subcommands never reach any check at all (the guard
        // exec's straight through before check 1) — a fake terraform that
        // only understands these three invocations is sufficient proof.
        $fake = $this->makeFakeTerraform([
            'validate' => 'Success! The configuration is valid.',
            'fmt' => '',
            'init -backend=false' => 'Terraform has been successfully initialized!',
        ]);

        try {
            foreach (
                [
                    ['-chdir=/tmp', 'validate'],
                    ['-chdir=/tmp', 'fmt', '-check'],
                    ['-chdir=/tmp', 'init', '-backend=false'],
                ] as $args
            ) {
                $result = $this->runGuard($fake, $args);
                $this->assertSame(0, $result['exit'], 'Guard must pass '.implode(' ', $args).' straight through.');
            }
        } finally {
            @unlink($fake);
        }
    }

    public function test_guard_still_refuses_empty_state_plan_with_an_approved_version_and_skipped_account_check(): void
    {
        $fake = $this->makeFakeTerraform(['version -json' => '{"terraform_version":"1.15.8"}']);

        try {
            $result = $this->runGuard(
                $fake,
                ['-chdir='.self::STAGING_DIR, 'plan'],
                ['TF_GUARD_SKIP_ACCOUNT_REGION_CHECK' => 'yes-i-am-sure']
            );

            $this->assertSame(1, $result['exit'], 'Guard must still refuse an empty-state plan even with an approved version and account/region check skipped.');
            $this->assertStringContainsString('local state currently shows 0 resources', $result['output']);
        } finally {
            @unlink($fake);
        }
    }
}
