<?php

declare(strict_types=1);

namespace CiviKitchen\Mago\Analyzer;

use Mago\Sdk\Analyzer\MethodReturnTypeProvider;
use Mago\Sdk\Analyzer\MethodTarget;
use Mago\Sdk\Analyzer\ReturnTypeProviderContext;
use Mago\Sdk\Analyzer\Type;
use Mago\Sdk\Analyzer\Type\NamedObjectType;

/**
 * Types the rows core's AbstractAction::execute() returns as field maps. core fills
 * the Result class an action's own execute() declares, also for parent::execute().
 */
final class Api4ExecuteRowsHook implements MethodReturnTypeProvider
{
    private const ACTION = 'Civi\\Api4\\Generic\\AbstractAction';

    public function getTargets(): array
    {
        return [MethodTarget::exact(self::ACTION, 'execute')];
    }

    public function getReturnType(ReturnTypeProviderContext $context): ?Type
    {
        foreach ($context->invocation->receiverType->atomicTypes ?? [] as $receiver) {
            $declaring = $receiver instanceof NamedObjectType
                ? $context->codebase->getDeclaringMethod($receiver->name, 'execute')?->identifier->class
                : null;
            if (strcasecmp((string) $declaring, self::ACTION) !== 0) {
                return null;
            }
        }

        return Type::namedObject(
            'Civi\\Api4\\Generic\\Result',
            Type::array(Type::string(), Type::mixed()),
        );
    }
}
