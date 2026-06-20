<?php

namespace Schorts\FirestoreDAO\Translators;

use Google\Cloud\Firestore\FieldPath;
use Google\Cloud\Core\Timestamp;
use Schorts\SharedKernel\Criteria\Exceptions\OperatorNotValid;
use Schorts\SharedKernel\Criteria\Exceptions\OrderNotValid;
use Schorts\SharedKernel\Criteria\Operator;
use Schorts\SharedKernel\Criteria\Direction;

final class CriteriaToFirestoreSymbolsTranslator
{
  public static function translateOperator(Operator $operator): string
	{
		switch ($operator) {
			case 'EQUAL':
				return '==';
			case 'NOT_EQUAL':
				return '!=';
			case 'GREATER_THAN':
				return '>';
			case 'GREATER_THAN_OR_EQUAL':
				return '>=';
			case 'LESS_THAN':
				return '<';
			case 'LESS_THAN_OR_EQUAL':
				return '<=';
			case 'IN':
				return 'in';
			case 'NOT_IN':
				return 'not-in';
			case 'ARRAY_CONTAINS':
				return 'array-contains';
			case 'ARRAY_CONTAINS_ANY':
				return 'array-contains-any';
			default:
				throw new OperatorNotValid($operator);
		}
	}

	public static function translateOrderDirection(Direction $order): ?string
	{
		switch ($order) {
			case 'ASC':
				return 'asc';
			case 'DESC':
				return 'desc';
			case 'NONE':
				return null;
			default:
				throw new OrderNotValid($order);
		}
	}

	public static function translateField(string $field): string|FieldPath
	{
		return $field === 'id'
			? FieldPath::documentId()
			: $field;
	}

	public static function translateValue(mixed $value): mixed
	{
		if ($value instanceof \DateTimeInterface) {
			return new Timestamp($value);
		}

		return $value;
	}
}
