<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * JobConstructorsCarryOnlyScalarSecretSafeTypesTest — Checkpoint 9
 * (frozen design §10 item 2). Reflection-based discovery of every
 * `ShouldQueue` class in the app; asserts every constructor
 * parameter's type is scalar/enum/`DateTimeInterface` — fail CLOSED
 * (test fails) on `Model`/untyped/`mixed` parameters. A job constructor
 * carrying a hydrated Eloquent Model (or anything untyped) is exactly
 * how a credential/secret/PII payload could accidentally leak into a
 * serialized queue payload; scalar IDs plus enums/DateTimeInterface are
 * the only shapes this codebase's job classes are allowed to carry
 * (matches the established convention every existing job in
 * `app/Jobs/`/`app/Integrations/Jobs/` already follows — see each
 * job's own constructor).
 *
 * Pure unit test: no framework boot required to reflect over class
 * declarations, BUT class discovery itself needs the classes to be
 * autoloadable, so this walks the filesystem for candidate files and
 * requires composer's autoloader (already loaded by the PHPUnit
 * bootstrap) to resolve them — no database, no factories.
 */
final class JobConstructorsCarryOnlyScalarSecretSafeTypesTest extends TestCase
{
    private const JOB_DIRECTORIES = [
        'app/Jobs',
        'app/Integrations/Jobs',
    ];

    private const ALLOWED_SCALAR_TYPES = ['int', 'string', 'bool', 'float'];

    /**
     * @return array<int, class-string>
     */
    private static function discoverShouldQueueClasses(): array
    {
        $root = dirname(__DIR__, 3);
        $classes = [];

        foreach (self::JOB_DIRECTORIES as $relativeDir) {
            $dir = $root.'/'.$relativeDir;

            if (! is_dir($dir)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                /** @var \SplFileInfo $fileInfo */
                if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
                    continue;
                }

                $source = file_get_contents($fileInfo->getPathname());
                if ($source === false) {
                    continue;
                }

                if (! preg_match('/namespace\s+([^;]+);/', $source, $nsMatch)) {
                    continue;
                }
                if (! preg_match('/class\s+([A-Za-z0-9_]+)/', $source, $classMatch)) {
                    continue;
                }

                $fqcn = trim($nsMatch[1]).'\\'.$classMatch[1];

                if (class_exists($fqcn) && is_subclass_of($fqcn, ShouldQueue::class)) {
                    $classes[] = $fqcn;
                } elseif (class_exists($fqcn) && (new ReflectionClass($fqcn))->implementsInterface(ShouldQueue::class)) {
                    $classes[] = $fqcn;
                }
            }
        }

        sort($classes);

        return array_values(array_unique($classes));
    }

    private function isAllowedParameterType(ReflectionParameter $param): bool
    {
        $type = $param->getType();

        if ($type === null) {
            // Untyped -> fail closed.
            return false;
        }

        if (! $type instanceof ReflectionNamedType) {
            // Union/intersection types are not part of this codebase's
            // scalar-only convention -> fail closed rather than
            // attempt to reason about each branch.
            return false;
        }

        $name = $type->getName();

        if ($name === 'mixed') {
            return false;
        }

        if (in_array($name, self::ALLOWED_SCALAR_TYPES, true)) {
            return true;
        }

        if ($name === \DateTimeInterface::class || is_a($name, \DateTimeInterface::class, true)) {
            return true;
        }

        if (enum_exists($name)) {
            return true;
        }

        // Everything else — including any Illuminate\Database\Eloquent\Model
        // subclass, any plain class/interface, arrays, etc. — fails
        // closed.
        return false;
    }

    public function test_at_least_ten_shouldqueue_classes_are_discovered(): void
    {
        $classes = self::discoverShouldQueueClasses();

        $this->assertGreaterThanOrEqual(10, count($classes), 'Expected at least the 10 known ShouldQueue job classes across app/Jobs/ and app/Integrations/Jobs/.');
    }

    public function test_every_shouldqueue_constructor_parameter_is_scalar_enum_or_datetimeinterface(): void
    {
        $classes = self::discoverShouldQueueClasses();
        $this->assertNotEmpty($classes);

        $violations = [];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                continue; // no constructor parameters to violate anything
            }

            foreach ($constructor->getParameters() as $param) {
                if (! $this->isAllowedParameterType($param)) {
                    $type = $param->getType();
                    $typeName = $type === null ? 'untyped/mixed' : (string) $type;
                    $violations[] = "{$class}::__construct(\${$param->getName()}: {$typeName})";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "The following ShouldQueue constructor parameters are NOT scalar/enum/DateTimeInterface-safe:\n".implode("\n", $violations)
        );
    }

    /**
     * Fail-closed self-check: a synthetic ShouldQueue-shaped class with
     * a Model-typed constructor parameter must be correctly detected
     * as a violation by isAllowedParameterType() — proves the
     * detection logic itself, not merely that today's real job classes
     * happen to be clean.
     */
    public function test_the_detector_itself_fails_closed_on_a_model_typed_parameter(): void
    {
        $reflection = new ReflectionClass(SyntheticJobWithModelParameterForTestingOnly::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertFalse($this->isAllowedParameterType($params[0]), 'A Model-typed constructor parameter must be detected as a violation.');
    }

    public function test_the_detector_fails_closed_on_an_untyped_parameter(): void
    {
        $reflection = new ReflectionClass(SyntheticJobWithUntypedParameterForTestingOnly::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();
        $this->assertCount(1, $params);
        $this->assertFalse($this->isAllowedParameterType($params[0]));
    }

    public function test_the_detector_accepts_int_string_bool_float_enum_and_datetimeinterface(): void
    {
        $reflection = new ReflectionClass(SyntheticJobWithSafeParametersForTestingOnly::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        foreach ($constructor->getParameters() as $param) {
            $this->assertTrue($this->isAllowedParameterType($param), "Parameter \${$param->getName()} should be allowed.");
        }
    }
}

/**
 * Test-only fixture — never a real job, never dispatched, exists
 * solely so test_the_detector_itself_fails_closed_on_a_model_typed_parameter()
 * can prove the detector actually catches this shape rather than
 * merely asserting today's real jobs are clean by coincidence.
 */
final class SyntheticJobWithModelParameterForTestingOnly
{
    public function __construct(private readonly Model $model) {}
}

final class SyntheticJobWithUntypedParameterForTestingOnly
{
    public function __construct(private $anything) {}
}

enum SyntheticSafeEnumForTestingOnly: string
{
    case A = 'a';
}

final class SyntheticJobWithSafeParametersForTestingOnly
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly bool $flag,
        private readonly float $amount,
        private readonly SyntheticSafeEnumForTestingOnly $kind,
        private readonly \DateTimeImmutable $occurredAt,
    ) {}
}
