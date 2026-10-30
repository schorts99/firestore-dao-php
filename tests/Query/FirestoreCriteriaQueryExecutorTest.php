<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests\Query;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\Query;
use PHPUnit\Framework\TestCase;
use Schorts\FirestoreDAO\Query\FirestoreCriteriaQueryExecutor;
use Schorts\FirestoreDAO\Query\QueryResult;
use Schorts\FirestoreDAO\UnitOfWork\FirestoreTransactionUnitOfWork;
use Schorts\SharedKernel\Criteria\Criteria;
use Schorts\SharedKernel\Criteria\Direction;
use Schorts\SharedKernel\Criteria\Filter;
use Schorts\SharedKernel\Criteria\Operator;
use Schorts\SharedKernel\Criteria\Order;
use Schorts\SharedKernel\Logger\InMemoryLogger;

final class FirestoreCriteriaQueryExecutorTest extends TestCase
{
  public function testExecuteStandardCriteria(): void
  {
    $doc1 = $this->createStub(DocumentSnapshot::class);
    $doc2 = $this->createStub(DocumentSnapshot::class);

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('name')->willReturn('users');

    $query1 = $this->createMock(Query::class);
    $query2 = $this->createMock(Query::class);
    $query3 = $this->createMock(Query::class);
    $query4 = $this->createMock(Query::class);

    // Filter: age >= 18
    $collection->expects($this->once())
      ->method('where')
      ->with('age', '>=', 18)
      ->willReturn($query1);

    // Order: name ASC
    $query1->expects($this->once())
      ->method('orderBy')
      ->with('name', 'asc')
      ->willReturn($query2);

    // Limit: 10
    $query2->expects($this->once())
      ->method('limit')
      ->with(10)
      ->willReturn($query3);

    // Offset: 5
    $query3->expects($this->once())
      ->method('offset')
      ->with(5)
      ->willReturn($query4);

    $query4->expects($this->once())
      ->method('documents')
      ->willReturn(new \ArrayIterator([$doc1, $doc2]));

    $criteria = new Criteria(
      filters: [new Filter('age', Operator::GREATER_THAN_OR_EQUAL, 18)],
      orders: [new Order('name', Direction::ASC)],
      limit: 10,
      offset: 5
    );

    $logger = new InMemoryLogger();
    $result = FirestoreCriteriaQueryExecutor::execute($collection, $criteria, null, $logger);

    $this->assertInstanceOf(QueryResult::class, $result);
    $this->assertSame(2, $result->size());
    $this->assertSame([$doc1, $doc2], $result->docs());
    $this->assertNotEmpty($logger->getLogs());
  }

  public function testExecuteWithTransactionUnitOfWork(): void
  {
    $doc1 = $this->createStub(DocumentSnapshot::class);

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('name')->willReturn('items');

    $uow = $this->createMock(FirestoreTransactionUnitOfWork::class);
    $uow->expects($this->once())
      ->method('getQuery')
      ->with($collection)
      ->willReturn([$doc1]);

    $criteria = Criteria::none();
    $result = FirestoreCriteriaQueryExecutor::execute($collection, $criteria, $uow);

    $this->assertSame(1, $result->size());
    $this->assertSame([$doc1], $result->docs());
  }

  public function testExecuteGeoRadiusFilter(): void
  {
    // San Francisco coords: 37.7749, -122.4194
    // Point inside SF (~1.4km away): 37.7800, -122.4100
    // Point far away (Los Angeles: ~550km): 34.0522, -118.2437

    $docInside = $this->createStub(DocumentSnapshot::class);
    $docInside->method('data')->willReturn([
      'name' => 'Market Street Cafe',
      'location' => [
        'latitude' => 37.7800,
        'longitude' => -122.4100,
      ],
    ]);

    $docFar = $this->createStub(DocumentSnapshot::class);
    $docFar->method('data')->willReturn([
      'name' => 'LA Restaurant',
      'location' => [
        'latitude' => 34.0522,
        'longitude' => -118.2437,
      ],
    ]);

    $docNoLocation = $this->createStub(DocumentSnapshot::class);
    $docNoLocation->method('data')->willReturn([
      'name' => 'Unknown Location',
    ]);

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('name')->willReturn('places');

    $query = $this->createMock(Query::class);
    $collection->expects($this->once())
      ->method('where')
      ->with('status', '==', 'open')
      ->willReturn($query);

    $query->expects($this->once())
      ->method('limit')
      ->with(20)
      ->willReturnSelf();

    $query->expects($this->once())
      ->method('documents')
      ->willReturn(new \ArrayIterator([$docInside, $docFar, $docNoLocation]));

    $criteria = new Criteria(
      filters: [
        new Filter('status', Operator::EQUAL, 'open'),
        new Filter('location', Operator::GEO_RADIUS, [
          'center' => [37.7749, -122.4194],
          'radiusInM' => 5000, // 5km
        ]),
      ],
      limit: 20
    );

    $result = FirestoreCriteriaQueryExecutor::execute($collection, $criteria);

    $this->assertSame(1, $result->size());
    $this->assertSame([$docInside], $result->docs());
  }
}
