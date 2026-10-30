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
    return match ($operator) {
      Operator::EQUAL => '==',
      Operator::NOT_EQUAL => '!=',
      Operator::GREATER_THAN => '>',
      Operator::GREATER_THAN_OR_EQUAL => '>=',
      Operator::LESS_THAN => '<',
      Operator::LESS_THAN_OR_EQUAL => '<=',
      Operator::IN => 'in',
      Operator::NOT_IN => 'not-in',
      Operator::ARRAY_CONTAINS => 'array-contains',
      Operator::ARRAY_CONTAINS_ANY => 'array-contains-any',
      default => throw new OperatorNotValid($operator),
    };
  }

  public static function translateOrderDirection(Direction $order): ?string
  {
    return match ($order) {
      Direction::ASC => 'asc',
      Direction::DESC => 'desc',
      Direction::NONE => null,
      default => throw new OrderNotValid($order),
    };
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
