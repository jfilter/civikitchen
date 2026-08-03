<?php

declare(strict_types = 1);

namespace CiviKitchen\Rector\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\ConstantScalarType;
use PHPStan\Type\NullType;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\PhpParser\Node\Value\ValueResolver;
use Rector\Rector\AbstractRector;
use Rector\Reflection\ReflectionResolver;
use Rector\ValueObject\PhpVersionFeature;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Drop positional arguments that only repeat a parameter's default, and name
 * whatever has to survive after them:
 *
 *   CRM_Utils_Request::retrieve('delete', 'String', NULL, FALSE, NULL, 'POST')
 *   CRM_Utils_Request::retrieve('delete', 'String', method: 'POST')
 *
 * Signatures come from reflection where the callee is autoloadable (the
 * extension's own code) and otherwise from the SIGNATURES map below — an
 * extension's rector run has no CiviCRM core on its autoloader, so the core
 * offenders have to be declared. Extend it per project via configure().
 */
final class PositionalDefaultsToNamedArgsRector extends AbstractRector implements ConfigurableRectorInterface, MinPhpVersionInterface {

  /**
   * Callee => parameters in declaration order. A list entry is a required
   * parameter (its name); a keyed entry is "name => default value".
   *
   * @var array<string, array<int|string, mixed>>
   */
  private const SIGNATURES = [
    'CRM_Utils_Request::retrieve' => [
      'name', 'type',
      'store' => NULL, 'abort' => FALSE, 'default' => NULL, 'method' => 'REQUEST',
    ],
    'CRM_Utils_System::url' => [
      'path' => '', 'query' => '', 'absolute' => FALSE, 'fragment' => NULL,
      'htmlize' => TRUE, 'frontend' => FALSE, 'forceBackend' => FALSE,
    ],
  ];

  /**
   * @var array<string, array<int|string, mixed>>
   */
  private array $signatures = self::SIGNATURES;

  public function __construct(
    private readonly ValueResolver $valueResolver,
    private readonly ReflectionResolver $reflectionResolver,
  ) {}

  /**
   * @param array<string, array<int|string, mixed>> $configuration
   */
  public function configure(array $configuration): void {
    $this->signatures = $configuration + self::SIGNATURES;
  }

  public function provideMinPhpVersion(): int {
    return PhpVersionFeature::NAMED_ARGUMENTS;
  }

  public function getNodeTypes(): array {
    return [StaticCall::class, MethodCall::class, FuncCall::class];
  }

  public function refactor(Node $node): ?Node {
    if (!$node instanceof StaticCall && !$node instanceof MethodCall && !$node instanceof FuncCall) {
      return NULL;
    }
    $args = $node->args;
    foreach ($args as $arg) {
      // Already named, spread, or a placeholder from a first-class callable.
      if (!$arg instanceof Arg || $arg->name !== NULL || $arg->unpack) {
        return NULL;
      }
    }
    $parameters = $this->resolveParameters($node);
    if ($parameters === NULL || count($args) > count($parameters)) {
      return NULL;
    }

    $newArgs = [];
    $dropped = 0;
    foreach ($args as $position => $arg) {
      [$name, $hasDefault, $default] = $parameters[$position];
      if ($hasDefault && $this->isLiteralDefault($arg->value, $default)) {
        $dropped++;
        continue;
      }
      $newArgs[] = $dropped === 0 ? $arg : new Arg($arg->value, $arg->byRef, FALSE, $arg->getAttributes(), new Identifier($name));
    }
    if ($dropped === 0) {
      return NULL;
    }

    $node->args = $newArgs;
    return $node;
  }

  /**
   * Parameters as [name, hasDefault, default], or NULL when unresolvable.
   *
   * @return array<int, array{string, bool, mixed}>|NULL
   */
  private function resolveParameters(StaticCall|MethodCall|FuncCall $node): ?array {
    $declared = $this->signatures[$this->resolveCalleeName($node)] ?? NULL;
    if ($declared !== NULL) {
      $parameters = [];
      foreach ($declared as $key => $value) {
        $parameters[] = is_int($key) ? [(string) $value, FALSE, NULL] : [$key, TRUE, $value];
      }
      return $parameters;
    }
    return $this->reflectParameters($node);
  }

  private function resolveCalleeName(StaticCall|MethodCall|FuncCall $node): string {
    if ($node instanceof StaticCall) {
      $class = $this->getName($node->class);
      $method = $this->getName($node->name);
      return $class !== NULL && $method !== NULL ? ltrim($class, '\\') . '::' . $method : '';
    }
    return $node instanceof FuncCall ? (string) $this->getName($node) : '';
  }

  /**
   * @return array<int, array{string, bool, mixed}>|NULL
   */
  private function reflectParameters(StaticCall|MethodCall|FuncCall $node): ?array {
    $reflection = $this->reflectionResolver->resolveFunctionLikeReflectionFromCall($node);
    // Parameter names of internal functions are not ours to bet on.
    if ($reflection === NULL || ($reflection instanceof FunctionReflection && $reflection->isBuiltin())) {
      return NULL;
    }
    $variants = $reflection->getVariants();
    if (count($variants) !== 1) {
      return NULL;
    }
    $parameters = [];
    foreach ($variants[0]->getParameters() as $parameter) {
      if ($parameter->isVariadic()) {
        return NULL;
      }
      $defaultType = $parameter->getDefaultValue();
      if ($defaultType instanceof NullType) {
        $parameters[] = [$parameter->getName(), TRUE, NULL];
      }
      elseif ($defaultType instanceof ConstantScalarType) {
        $parameters[] = [$parameter->getName(), TRUE, $defaultType->getValue()];
      }
      else {
        $parameters[] = [$parameter->getName(), FALSE, NULL];
      }
    }
    return $parameters;
  }

  /**
   * Only literals may be dropped — a call or variable could have side effects
   * or resolve to the default only by coincidence.
   */
  private function isLiteralDefault(Node\Expr $expr, mixed $default): bool {
    if (!$expr instanceof Scalar && !$expr instanceof ConstFetch) {
      return FALSE;
    }
    return $default === NULL ? $this->valueResolver->isNull($expr) : $this->valueResolver->isValue($expr, $default);
  }

  public function getRuleDefinition(): RuleDefinition {
    return new RuleDefinition(
      'Drop positional arguments that repeat the parameter default and name the ones behind them',
      [
        new CodeSample(
          "CRM_Utils_Request::retrieve('delete', 'String', NULL, FALSE, NULL, 'POST');",
          "CRM_Utils_Request::retrieve('delete', 'String', method: 'POST');"
        ),
      ]
    );
  }

}
