<?php

namespace Tests\Feature\Ecs;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Proves the staging-deploy/ ECS deployment package (task-definition JSON +
 * the eight numbered deployment scripts) is internally consistent before any
 * human runs it against AWS. Pure static-file assertions — no AWS access, no
 * database, no HTTP calls. See staging-deploy/https-remediation-plan.md and
 * the script headers for the operational safety model these checks exist to
 * protect.
 */
class StagingDeploymentPackageTest extends TestCase
{
    private const APPROVED_DIGEST = 'sha256:92eecbeeef5225fcfe0f4256a0b375a773cd84c1f4974fc08b137811a27e46fd';

    private const OLD_DIGEST = 'sha256:8bfd74b0b56986f426d1695e2fef69e5f8b1f77be0c9712ea9015c4946de3a4f';

    private const APPROVED_IMAGE = '603013471426.dkr.ecr.us-east-1.amazonaws.com/firmsbase-staging@'.self::APPROVED_DIGEST;

    private const APPROVED_APP_URL = 'http://firmsbase-staging-alb-380035227.us-east-1.elb.amazonaws.com';

    private const RUNTIME_ROLES_WITH_APP_URL = ['web', 'worker', 'critical-worker', 'scheduler'];

    private const ONE_OFF_ROLES_WITHOUT_APP_URL = ['migrate', 'maintenance'];

    private const ALL_ROLES = [
        'web', 'worker', 'critical-worker', 'scheduler', 'migrate', 'maintenance',
    ];

    private const PLACEHOLDER_PATTERNS = [
        'PENDING_NEW_DIGEST',
        'REPLACE_WITH_NEW_DIGEST',
        'REPLACE_WITH_NEW_COMMIT_SHA',
        'OPERATOR_MUST_SET',
    ];

    private const SCRIPTS = [
        '00-http-exposure-preflight.sh',
        '01-register-runtime-task-definitions.sh',
        '02-launch-web-service.sh',
        '03-verify-web-health.sh',
        '04-launch-critical-worker.sh',
        '05-launch-worker.sh',
        '06-launch-scheduler.sh',
        '07-final-runtime-verification.sh',
    ];

    private const READ_ONLY_SCRIPTS = [
        '00-http-exposure-preflight.sh',
        '03-verify-web-health.sh',
        '07-final-runtime-verification.sh',
    ];

    private const MUTATING_ECS_COMMANDS = [
        'create-service',
        'register-task-definition',
        'run-task',
        'update-service',
        'delete-service',
        'modify-listener',
        'authorize-security-group-ingress',
    ];

    private const FORBIDDEN_MIGRATION_COMMANDS = [
        'migrate:fresh',
        'migrate:rollback',
        'db:wipe',
        'schema:drop',
    ];

    private function taskDefinitionPath(string $role): string
    {
        return base_path("staging-deploy/firmsbase-staging-{$role}.json");
    }

    private function scriptPath(string $script): string
    {
        return base_path("staging-deploy/{$script}");
    }

    private function decodeTaskDefinition(string $role): array
    {
        $path = $this->taskDefinitionPath($role);
        $this->assertFileExists($path, "Task-definition JSON for role '{$role}' is missing.");

        $decoded = json_decode(file_get_contents($path), associative: true);
        $this->assertIsArray($decoded, "staging-deploy/firmsbase-staging-{$role}.json is not valid JSON.");

        return $decoded;
    }

    public static function allRoles(): array
    {
        return array_map(static fn (string $role) => [$role], self::ALL_ROLES);
    }

    public static function runtimeRoles(): array
    {
        return array_map(static fn (string $role) => [$role], self::RUNTIME_ROLES_WITH_APP_URL);
    }

    public static function oneOffRoles(): array
    {
        return array_map(static fn (string $role) => [$role], self::ONE_OFF_ROLES_WITHOUT_APP_URL);
    }

    public static function scripts(): array
    {
        return array_map(static fn (string $script) => [$script], self::SCRIPTS);
    }

    public static function createServiceScripts(): array
    {
        return array_map(static fn (string $script) => [$script], [
            '02-launch-web-service.sh',
            '04-launch-critical-worker.sh',
            '05-launch-worker.sh',
            '06-launch-scheduler.sh',
        ]);
    }

    public static function readOnlyScripts(): array
    {
        return array_map(static fn (string $script) => [$script], self::READ_ONLY_SCRIPTS);
    }

    #[DataProvider('allRoles')]
    public function test_task_definition_json_is_valid_and_uses_the_exact_approved_digest(string $role): void
    {
        $definition = $this->decodeTaskDefinition($role);

        $image = $definition['containerDefinitions'][0]['image'] ?? null;
        $this->assertSame(self::APPROVED_IMAGE, $image, "Role '{$role}' does not use the exact approved immutable image URI.");
        $this->assertStringNotContainsString(self::OLD_DIGEST, (string) $image, "Role '{$role}' still references the old digest.");
    }

    #[DataProvider('allRoles')]
    public function test_task_definition_json_contains_no_placeholder_or_fake_values(string $role): void
    {
        $raw = file_get_contents($this->taskDefinitionPath($role));

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            $this->assertStringNotContainsString($pattern, $raw, "Role '{$role}' JSON still contains the placeholder pattern '{$pattern}'.");
        }
    }

    #[DataProvider('runtimeRoles')]
    public function test_runtime_roles_have_the_exact_approved_temporary_app_url(string $role): void
    {
        $definition = $this->decodeTaskDefinition($role);
        $env = collect($definition['containerDefinitions'][0]['environment'] ?? []);

        $matches = $env->where('name', 'APP_URL');
        $this->assertCount(1, $matches, "Role '{$role}' must define exactly one APP_URL environment entry.");
        $this->assertSame(self::APPROVED_APP_URL, $matches->first()['value'], "Role '{$role}' APP_URL does not match the exact approved temporary synthetic staging address.");
        $this->assertStringStartsWith('http://', $matches->first()['value'], 'APP_URL must not silently become https:// before HTTPS is actually configured.');
    }

    #[DataProvider('oneOffRoles')]
    public function test_one_off_roles_do_not_define_app_url(string $role): void
    {
        $definition = $this->decodeTaskDefinition($role);
        $env = collect($definition['containerDefinitions'][0]['environment'] ?? []);

        $this->assertCount(0, $env->where('name', 'APP_URL'), "Role '{$role}' is a one-off migrate/maintenance role and must not define APP_URL.");
    }

    #[DataProvider('allRoles')]
    public function test_runtime_and_maintenance_roles_use_the_app_database_secret_not_the_migrator(string $role): void
    {
        $definition = $this->decodeTaskDefinition($role);
        $secrets = collect($definition['containerDefinitions'][0]['secrets'] ?? []);

        $expectedPrefix = $role === 'migrate'
            ? 'arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/database-migrator-TpsE6P'
            : 'arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/database-app-8NUj2a';

        $forbiddenSubstring = $role === 'migrate' ? 'database-app-' : 'database-migrator';

        foreach (['DB_HOST' => 'host', 'DB_PORT' => 'port', 'DB_DATABASE' => 'dbname', 'DB_USERNAME' => 'username', 'DB_PASSWORD' => 'password'] as $name => $field) {
            $matches = $secrets->where('name', $name);
            $this->assertCount(1, $matches, "Role '{$role}' must define exactly one {$name} selector.");
            $this->assertSame("{$expectedPrefix}:{$field}::", $matches->first()['valueFrom'], "Role '{$role}' {$name} selector does not match the expected secret field exactly.");
        }

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($forbiddenSubstring, $secret['valueFrom'], "Role '{$role}' unexpectedly references '{$forbiddenSubstring}'.");
        }
    }

    #[DataProvider('allRoles')]
    public function test_all_roles_have_exactly_one_correct_redis_password_selector_and_tls_config(string $role): void
    {
        $definition = $this->decodeTaskDefinition($role);
        $secrets = collect($definition['containerDefinitions'][0]['secrets'] ?? []);
        $env = collect($definition['containerDefinitions'][0]['environment'] ?? []);

        $redisSecrets = $secrets->where('name', 'REDIS_PASSWORD');
        $this->assertCount(1, $redisSecrets, "Role '{$role}' must define exactly one REDIS_PASSWORD selector.");
        $this->assertSame(
            'arn:aws:secretsmanager:us-east-1:603013471426:secret:firmsbase/staging/redis-auth-token-p6rVKN:REDIS_PASSWORD::',
            $redisSecrets->first()['valueFrom'],
            "Role '{$role}' REDIS_PASSWORD selector does not match exactly."
        );

        $redisHost = $env->firstWhere('name', 'REDIS_HOST')['value'] ?? '';
        $redisPort = $env->firstWhere('name', 'REDIS_PORT')['value'] ?? '';
        $this->assertStringStartsWith('tls://', $redisHost, "Role '{$role}' REDIS_HOST must use the tls:// scheme.");
        $this->assertSame('6379', $redisPort, "Role '{$role}' REDIS_PORT must be exactly 6379.");
    }

    public function test_static_environment_values_are_unchanged_across_all_roles(): void
    {
        foreach (self::ALL_ROLES as $role) {
            $definition = $this->decodeTaskDefinition($role);
            $env = collect($definition['containerDefinitions'][0]['environment'] ?? []);

            $this->assertSame('staging', $env->firstWhere('name', 'APP_ENV')['value'] ?? null, "Role '{$role}' APP_ENV must remain 'staging'.");
            $this->assertSame('false', $env->firstWhere('name', 'APP_DEBUG')['value'] ?? null, "Role '{$role}' APP_DEBUG must remain 'false'.");
            $this->assertSame('stderr', $env->firstWhere('name', 'LOG_CHANNEL')['value'] ?? null, "Role '{$role}' LOG_CHANNEL must remain 'stderr'.");
            $this->assertSame('true', $env->firstWhere('name', 'SESSION_SECURE_COOKIE')['value'] ?? null, "Role '{$role}' SESSION_SECURE_COOKIE must remain 'true'.");

            $this->assertSame(
                'arn:aws:iam::603013471426:role/firmsbase-staging-ecs-execution-role',
                $definition['executionRoleArn'],
                "Role '{$role}' executionRoleArn must remain the approved execution role."
            );
            $this->assertSame(
                'arn:aws:iam::603013471426:role/firmsbase-staging-ecs-task-role',
                $definition['taskRoleArn'],
                "Role '{$role}' taskRoleArn must remain the approved task role."
            );
        }
    }

    public function test_migrate_task_definition_was_not_modified_to_be_rerunnable(): void
    {
        // Historical evidence, not a live gate: this only proves the migrate
        // JSON still declares the same one-shot "migrate" command it always
        // has — it does not (and cannot) prove the already-completed staging
        // migration wasn't rerun, since that is a live AWS/DB fact outside
        // the scope of a static file assertion.
        $definition = $this->decodeTaskDefinition('migrate');
        $this->assertSame(['migrate'], $definition['containerDefinitions'][0]['command'] ?? null);
    }

    #[DataProvider('scripts')]
    public function test_script_exists_is_executable_and_has_a_bash_shebang(string $script): void
    {
        $path = $this->scriptPath($script);
        $this->assertFileExists($path, "Deployment script {$script} is missing.");
        $this->assertTrue(is_executable($path), "Deployment script {$script} must be executable.");

        $firstLine = strtok(file_get_contents($path), "\n");
        $this->assertSame('#!/usr/bin/env bash', $firstLine, "{$script} must start with the #!/usr/bin/env bash shebang.");
    }

    #[DataProvider('scripts')]
    public function test_script_contains_no_old_digest_or_placeholder(string $script): void
    {
        $contents = file_get_contents($this->scriptPath($script));

        $this->assertStringNotContainsString(self::OLD_DIGEST, $contents, "{$script} still references the old, superseded digest.");

        foreach (self::PLACEHOLDER_PATTERNS as $pattern) {
            $this->assertStringNotContainsString($pattern, $contents, "{$script} still contains the placeholder pattern '{$pattern}'.");
        }
    }

    #[DataProvider('createServiceScripts')]
    public function test_create_service_scripts_use_disable_execute_command_and_never_the_boolean_false_form(string $script): void
    {
        $contents = file_get_contents($this->scriptPath($script));

        $this->assertStringContainsString('--disable-execute-command', $contents, "{$script} must pass --disable-execute-command to create-service.");
        $this->assertStringNotContainsString('--enable-execute-command false', $contents, "{$script} must never use the --enable-execute-command false form.");
    }

    #[DataProvider('createServiceScripts')]
    public function test_create_service_scripts_never_reference_a_family_only_task_definition(string $script): void
    {
        $contents = file_get_contents($this->scriptPath($script));

        // A family-only reference would appear as a literal
        // `--task-definition firmsbase-staging-<role>` string; the approved
        // scripts always pass a shell variable holding the exact ARN instead.
        $this->assertDoesNotMatchRegularExpression(
            '/--task-definition\s+firmsbase-staging-[a-z-]+["\'\s]/',
            $contents,
            "{$script} must never launch a service against a family-only task-definition reference."
        );
    }

    #[DataProvider('scripts')]
    public function test_no_script_runs_a_destructive_migration_or_reset_command(string $script): void
    {
        $contents = file_get_contents($this->scriptPath($script));

        foreach (self::FORBIDDEN_MIGRATION_COMMANDS as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $contents, "{$script} must never invoke '{$forbidden}'.");
        }

        // 01 registers the migrate task definition's *sibling* runtime
        // definitions but must never itself execute the migrate command.
        $this->assertDoesNotMatchRegularExpression('/aws\s+ecs\s+run-task\b/', $contents, "{$script} must never run an ECS task directly.");
    }

    #[DataProvider('readOnlyScripts')]
    public function test_read_only_scripts_never_call_a_mutating_ecs_or_elb_command(string $script): void
    {
        $contents = file_get_contents($this->scriptPath($script));

        foreach (self::MUTATING_ECS_COMMANDS as $mutating) {
            $this->assertStringNotContainsString($mutating, $contents, "{$script} is documented as read-only and must never call '{$mutating}'.");
        }
    }

    public function test_registration_script_registers_only_the_four_runtime_roles_in_the_documented_order(): void
    {
        $contents = file_get_contents($this->scriptPath('01-register-runtime-task-definitions.sh'));

        $this->assertMatchesRegularExpression(
            '/ORDER=\(web critical-worker worker scheduler\)/',
            $contents,
            'Registration order must be exactly web, critical-worker, worker, scheduler.'
        );
        $this->assertStringNotContainsString('firmsbase-staging-migrate.json', $contents, '01 must never register the migrate task definition.');
        $this->assertStringNotContainsString('firmsbase-staging-maintenance.json', $contents, '01 must never register the maintenance task definition.');
    }

    public function test_web_launch_script_requires_the_public_http_synthetic_testing_acknowledgement(): void
    {
        $contents = file_get_contents($this->scriptPath('02-launch-web-service.sh'));

        $this->assertStringContainsString('CONFIRMED_PUBLIC_HTTP_SYNTHETIC_TESTING', $contents);
        $this->assertStringContainsString('CONFIRMED_ECS_STOPTASK', $contents);
        $this->assertStringContainsString('CONFIRMED_WEB_SERVICE_LAUNCH', $contents);
        $this->assertStringContainsString('00-http-exposure-preflight.sh', $contents, '02 must source 00 to re-run the live HTTP-exposure preflight before creating the web service.');
    }

    public function test_scheduler_launch_script_never_scales_beyond_desired_count_one(): void
    {
        $contents = file_get_contents($this->scriptPath('06-launch-scheduler.sh'));

        $this->assertMatchesRegularExpression('/--desired-count 1\b/', $contents);
        // A "--desired-count 0" is expected and safe here: it only appears in
        // the printed rollback/containment guidance (scaling DOWN to tear
        // down a failed launch), never as a scale-up. Only 2+ would mean the
        // script itself tries to run more than one scheduler task.
        $this->assertDoesNotMatchRegularExpression('/--desired-count [2-9]/', $contents);
    }

    public function test_final_verification_script_asserts_no_migrate_task_is_running_and_no_service_uses_the_migrator(): void
    {
        $contents = file_get_contents($this->scriptPath('07-final-runtime-verification.sh'));

        $this->assertStringContainsString('firmsbase-staging-migrate', $contents);
        $this->assertStringContainsString('database-migrator', $contents);
        $this->assertStringContainsString('RUNTIME_SERVICES_VERIFIED', $contents);
        $this->assertStringContainsString('HTTP_SYNTHETIC_ONLY_HTTPS_STILL_REQUIRED', $contents);
    }
}
