<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests\Migrators;

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use PHPUnit\Framework\TestCase;
use Schorts\FirestoreDAO\Migrators\DataMigrator;
use Schorts\SharedKernel\Logger\InMemoryLogger;

final class DataMigratorTest extends TestCase
{
  public function testMigrateFromHardToSoftDeleteUpdatesDocumentsMissingFields(): void
  {
    // Doc 1: missing both fields
    $ref1 = $this->createMock(DocumentReference::class);
    $ref1->expects($this->once())
      ->method('update')
      ->with([
        'is_deleted' => false,
        'deleted_at' => null,
      ]);
    $doc1 = $this->createStub(DocumentSnapshot::class);
    $doc1->method('data')->willReturn(['name' => 'Doc 1']);
    $doc1->method('reference')->willReturn($ref1);

    // Doc 2: already has both fields
    $ref2 = $this->createMock(DocumentReference::class);
    $ref2->expects($this->never())->method('update');
    $doc2 = $this->createStub(DocumentSnapshot::class);
    $doc2->method('data')->willReturn([
      'name' => 'Doc 2',
      'is_deleted' => false,
      'deleted_at' => null,
    ]);
    $doc2->method('reference')->willReturn($ref2);

    // Doc 3: has is_deleted but missing deleted_at
    $ref3 = $this->createMock(DocumentReference::class);
    $ref3->expects($this->once())
      ->method('update')
      ->with([
        'deleted_at' => null,
      ]);
    $doc3 = $this->createStub(DocumentSnapshot::class);
    $doc3->method('data')->willReturn([
      'name' => 'Doc 3',
      'is_deleted' => true,
    ]);
    $doc3->method('reference')->willReturn($ref3);

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('name')->willReturn('items');
    $collection->expects($this->once())
      ->method('documents')
      ->willReturn(new \ArrayIterator([$doc1, $doc2, $doc3]));

    $logger = new InMemoryLogger();
    $migrator = new DataMigrator($collection, $logger);
    $migrator->migrateFromHardToSoftDelete();

    $this->assertNotEmpty($logger->getLogs());
  }
}
