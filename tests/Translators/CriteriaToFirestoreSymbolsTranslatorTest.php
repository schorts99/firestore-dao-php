<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests\Translators;

use DateTime;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FieldPath;
use PHPUnit\Framework\TestCase;
use Schorts\FirestoreDAO\Translators\CriteriaToFirestoreSymbolsTranslator;
use Schorts\SharedKernel\Criteria\Direction;
use Schorts\SharedKernel\Criteria\Exceptions\OperatorNotValid;
use Schorts\SharedKernel\Criteria\Operator;

final class CriteriaToFirestoreSymbolsTranslatorTest extends TestCase
{
  public function testTranslateOperatorAllValid(): void
  {
    $this->assertSame('==', CriteriaToFirestoreSymbolsTranslator::translateOperator(Operator::EQUAL));
    $this->assertSame('!=', CriteriaToFirestoreSymbolsTranslator::translateOperator(Operator::NOT_EQUAL));
    $this->assertSame('>', CriteriaToFirestoreSymbolsTranslator::translateOperator(Operator::GREATER_THAN));
    $this->assertSame('>=', CriteriaToFirestoreSymbolsTranslator::translateOperator(Operator::GREATER_THAN_OR_EQUAL));
    $this->assertSame('<', CriteriaToFirestoreSymbolsTranslator::translateOperator(Operator::LESS_THAN));
    $this->assertSame('<=', CriteriaToFirestoreSymbolsTranslator::translateOperator(Operator::LESS_THAN_OR_EQUAL));
    $this->assertSame('in', CriteriaToFirestoreSymbolsTranslator::translateOperator(Operator::IN));
    $this->assertSame('not-in', CriteriaToFirestoreSymbolsTranslator::translateOperator(Operator::NOT_IN));
    $this->assertSame('array-contains', CriteriaToFirestoreSymbolsTranslator::translateOperator(Operator::ARRAY_CONTAINS));
    $this->assertSame('array-contains-any', CriteriaToFirestoreSymbolsTranslator::translateOperator(Operator::ARRAY_CONTAINS_ANY));
  }

  public function testTranslateOperatorUnsupportedThrowsException(): void
  {
    $this->expectException(OperatorNotValid::class);
    CriteriaToFirestoreSymbolsTranslator::translateOperator(Operator::LIKE);
  }

  public function testTranslateOrderDirection(): void
  {
    $this->assertSame('asc', CriteriaToFirestoreSymbolsTranslator::translateOrderDirection(Direction::ASC));
    $this->assertSame('desc', CriteriaToFirestoreSymbolsTranslator::translateOrderDirection(Direction::DESC));
    $this->assertNull(CriteriaToFirestoreSymbolsTranslator::translateOrderDirection(Direction::NONE));
  }

  public function testTranslateFieldIdReturnsDocumentIdFieldPath(): void
  {
    $translated = CriteriaToFirestoreSymbolsTranslator::translateField('id');
    $this->assertInstanceOf(FieldPath::class, $translated);
    $this->assertEquals(FieldPath::documentId(), $translated);
  }

  public function testTranslateFieldNonIdReturnsString(): void
  {
    $translated = CriteriaToFirestoreSymbolsTranslator::translateField('email');
    $this->assertSame('email', $translated);
  }

  public function testTranslateValueDateTimeReturnsTimestamp(): void
  {
    $dt = new DateTime('2026-10-30T12:00:00Z');
    $translated = CriteriaToFirestoreSymbolsTranslator::translateValue($dt);
    $this->assertInstanceOf(Timestamp::class, $translated);
    $this->assertEquals($dt, $translated->get());

    $dti = new DateTimeImmutable('2026-10-30T15:30:00Z');
    $translatedImmutable = CriteriaToFirestoreSymbolsTranslator::translateValue($dti);
    $this->assertInstanceOf(Timestamp::class, $translatedImmutable);
    $this->assertEquals($dti, $translatedImmutable->get());
  }

  public function testTranslateValuePrimitivesUnchanged(): void
  {
    $this->assertSame('foo', CriteriaToFirestoreSymbolsTranslator::translateValue('foo'));
    $this->assertSame(42, CriteriaToFirestoreSymbolsTranslator::translateValue(42));
    $this->assertSame(3.14, CriteriaToFirestoreSymbolsTranslator::translateValue(3.14));
    $this->assertTrue(CriteriaToFirestoreSymbolsTranslator::translateValue(true));
    $this->assertNull(CriteriaToFirestoreSymbolsTranslator::translateValue(null));
    $this->assertSame(['a', 'b'], CriteriaToFirestoreSymbolsTranslator::translateValue(['a', 'b']));
  }
}
