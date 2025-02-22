<?php declare(strict_types = 1);

namespace PHPStan\Rules;

use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\Generic\TemplateTypeHelper;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;

class MissingTypehintCheck
{

	public const TURN_OFF_NON_GENERIC_CHECK_TIP = 'You can turn this off by setting <fg=cyan>checkGenericClassInNonGenericObjectType: false</> in your <fg=cyan>%configurationFile%</>.';

	/** @var bool */
	private $checkGenericClassInNonGenericObjectType;

	public function __construct(bool $checkGenericClassInNonGenericObjectType)
	{
		$this->checkGenericClassInNonGenericObjectType = $checkGenericClassInNonGenericObjectType;
	}

	/**
	 * @param \PHPStan\Type\Type $type
	 * @return \PHPStan\Type\Type[]
	 */
	public function getIterableTypesWithMissingValueTypehint(Type $type): array
	{
		$iterablesWithMissingValueTypehint = [];
		TypeTraverser::map($type, static function (Type $type, callable $traverse) use (&$iterablesWithMissingValueTypehint): Type {
			if ($type->isIterable()->yes()) {
				$iterableValue = $type->getIterableValueType();
				if ($iterableValue instanceof MixedType && !$iterableValue->isExplicitMixed()) {
					$iterablesWithMissingValueTypehint[] = $type;
				}
				return $type;
			}
			return $traverse($type);
		});

		return $iterablesWithMissingValueTypehint;
	}

	/**
	 * @param \PHPStan\Type\Type $type
	 * @return array<int, array{string, string[]}>
	 */
	public function getNonGenericObjectTypesWithGenericClass(Type $type): array
	{
		if (!$this->checkGenericClassInNonGenericObjectType) {
			return [];
		}

		$objectTypes = [];
		TypeTraverser::map($type, static function (Type $type, callable $traverse) use (&$objectTypes): Type {
			if ($type instanceof GenericObjectType) {
				return $type;
			}
			if ($type instanceof ObjectType) {
				$classReflection = $type->getClassReflection();
				if ($classReflection === null) {
					return $type;
				}
				if (in_array($classReflection->getName(), [
					\Traversable::class,
					\Iterator::class,
					\IteratorAggregate::class,
					\Generator::class,
				], true)) {
					// checked by getIterableTypesWithMissingValueTypehint() already
					return $type;
				}
				if ($classReflection->isTrait()) {
					return $type;
				}
				if (!$classReflection->isGeneric()) {
					return $type;
				}

				$resolvedType = TemplateTypeHelper::resolveToBounds($type);
				if (!$resolvedType instanceof ObjectType) {
					throw new \PHPStan\ShouldNotHappenException();
				}
				$objectTypes[] = [
					sprintf('%s %s', $classReflection->isInterface() ? 'interface' : 'class', $classReflection->getDisplayName(false)),
					array_keys($classReflection->getTemplateTypeMap()->getTypes()),
				];
				return $type;
			}
			$traverse($type);
			return $type;
		});

		return $objectTypes;
	}

}
