<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests;

use DateTime;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FieldPath;
use Google\Cloud\Firestore\Query;
use Google\Cloud\Firestore\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Schorts\FirestoreDAO\FirestoreDAO;
use Schorts\FirestoreDAO\UnitOfWork\FirestoreTransactionUnitOfWork;
use Schorts\SharedKernel\Criteria\Criteria;
use Schorts\SharedKernel\DAO\DeleteMode;
use Schorts\SharedKernel\DAO\Exceptions\EntityNotRecoverable;
use Schorts\SharedKernel\Entity\Entity;
use Schorts\SharedKernel\Entity\EntityRegistry;
use Schorts\SharedKernel\Logger\InMemoryLogger;
use Schorts\SharedKernel\ValueObjects\StringValue;

class UserIdVo extends StringValue
{
  public function getAttributeName(): string
  {
    return 'id';
  }
}

class UserTestEntity extends Entity
{
  public function __construct(
    UserIdVo $id,
    public string $name,
    public string $email,
    public bool $isDeleted = false
  ) {
    parent::__construct($id);
  }

  public function toPrimitives(): array
  {
    return [
      'id' => (string) $this->id,
      'name' => $this->name,
      'email' => $this->email,
      'is_deleted' => $this->isDeleted,
    ];
  }

  public static function fromPrimitives(array $primitives): self
  {
    return new self(
      new UserIdVo($primitives['id']),
      $primitives['name'],
      $primitives['email'],
      $primitives['is_deleted'] ?? false
    );
  }
}

class ConcreteUserDAO extends FirestoreDAO
{
}

final class FirestoreDAOTest extends TestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    EntityRegistry::clear();
    EntityRegistry::register('users', UserTestEntity::class);
  }

  protected function tearDown(): void
  {
    EntityRegistry::clear();
    parent::tearDown();
  }

  public function testFindByIdReturnsNullWhenDocumentDoesNotExist(): void
  {
    $docSnap = $this->createStub(DocumentSnapshot::class);
    $docSnap->method('exists')->willReturn(false);

    $docRef = $this->createStub(DocumentReference::class);
    $docRef->method('snapshot')->willReturn($docSnap);

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('document')->willReturn($docRef);

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $this->assertNull($dao->findByID('u-1'));
  }

  public function testFindByIdWithSoftDeleteFiltersOutDeletedWhenNotIncluded(): void
  {
    $docSnap = $this->createStub(DocumentSnapshot::class);
    $docSnap->method('exists')->willReturn(true);
    $docSnap->method('id')->willReturn('u-1');
    $docSnap->method('data')->willReturn(['name' => 'John', 'email' => 'john@test.com', 'is_deleted' => true]);
    $docSnap->method('get')->willReturn(true);

    $docRef = $this->createStub(DocumentReference::class);
    $docRef->method('snapshot')->willReturn($docSnap);

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('document')->willReturn($docRef);

    $dao = new ConcreteUserDAO($collection, DeleteMode::SOFT);

    $this->assertNull($dao->findByID('u-1', null, false));

    $entity = $dao->findByID('u-1', null, true);
    $this->assertInstanceOf(UserTestEntity::class, $entity);
    $this->assertSame('u-1', (string) $entity->getId());
  }

  public function testFindByIdWithTransactionUnitOfWork(): void
  {
    $docSnap = $this->createStub(DocumentSnapshot::class);
    $docSnap->method('exists')->willReturn(true);
    $docSnap->method('id')->willReturn('u-2');
    $docSnap->method('data')->willReturn(['name' => 'Jane', 'email' => 'jane@test.com']);

    $docRef = $this->createStub(DocumentReference::class);

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('document')->willReturn($docRef);

    $uow = $this->createMock(FirestoreTransactionUnitOfWork::class);
    $uow->expects($this->once())
      ->method('get')
      ->with($docRef)
      ->willReturn($docSnap);

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $entity = $dao->findByID('u-2', $uow);

    $this->assertInstanceOf(UserTestEntity::class, $entity);
    $this->assertSame('u-2', (string) $entity->getId());
  }

  public function testGetAllInHardDeleteMode(): void
  {
    $docSnap1 = $this->createStub(DocumentSnapshot::class);
    $docSnap1->method('exists')->willReturn(true);
    $docSnap1->method('id')->willReturn('u-1');
    $docSnap1->method('data')->willReturn(['name' => 'One', 'email' => '1@test.com']);

    $query = $this->createMock(Query::class);
    $query->expects($this->once())
      ->method('documents')
      ->willReturn(new \ArrayIterator([$docSnap1]));

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->expects($this->once())
      ->method('limit')
      ->with(1000)
      ->willReturn($query);

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $all = $dao->getAll();

    $this->assertCount(1, $all);
    $this->assertSame('u-1', (string) $all[0]->getId());
  }

  public function testGetAllInSoftDeleteMode(): void
  {
    $docSnap1 = $this->createStub(DocumentSnapshot::class);
    $docSnap1->method('exists')->willReturn(true);
    $docSnap1->method('id')->willReturn('u-1');
    $docSnap1->method('data')->willReturn(['name' => 'One', 'email' => '1@test.com']);

    $query1 = $this->createMock(Query::class);
    $query2 = $this->createMock(Query::class);

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->expects($this->once())->method('limit')->with(1000)->willReturn($query1);

    $query1->expects($this->once())
      ->method('where')
      ->with('is_deleted', '==', false)
      ->willReturn($query2);

    $query2->expects($this->once())
      ->method('documents')
      ->willReturn(new \ArrayIterator([$docSnap1]));

    $dao = new ConcreteUserDAO($collection, DeleteMode::SOFT);
    $all = $dao->getAll();

    $this->assertCount(1, $all);
  }

  public function testCreateInHardDeleteMode(): void
  {
    $entity = new UserTestEntity(new UserIdVo('u-10'), 'Ten', 'ten@test.com');

    $docRef = $this->createMock(DocumentReference::class);
    $docRef->expects($this->once())
      ->method('create')
      ->with([
        'name' => 'Ten',
        'email' => 'ten@test.com',
        'is_deleted' => false,
      ]);

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->expects($this->once())
      ->method('document')
      ->with('u-10')
      ->willReturn($docRef);

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $created = $dao->create($entity);

    $this->assertSame($entity, $created);
  }

  public function testCreateInSoftDeleteModeWithUoW(): void
  {
    $entity = new UserTestEntity(new UserIdVo('u-11'), 'Eleven', 'eleven@test.com');

    $docRef = $this->createStub(DocumentReference::class);

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->expects($this->once())
      ->method('document')
      ->with('u-11')
      ->willReturn($docRef);

    $uow = $this->createMock(FirestoreTransactionUnitOfWork::class);
    $uow->expects($this->once())
      ->method('create')
      ->with($docRef, [
        'name' => 'Eleven',
        'email' => 'eleven@test.com',
        'is_deleted' => false,
        'deleted_at' => null,
      ]);

    $dao = new ConcreteUserDAO($collection, DeleteMode::SOFT);
    $created = $dao->create($entity, $uow);

    $this->assertSame($entity, $created);
  }

  public function testUpdateInSoftDeleteMode(): void
  {
    $entity = new UserTestEntity(new UserIdVo('u-12'), 'Twelve', 'twelve@test.com');

    $docRef = $this->createMock(DocumentReference::class);
    $docRef->expects($this->once())
      ->method('update')
      ->with([
        ['path' => new FieldPath(['name']), 'value' => 'Twelve'],
        ['path' => new FieldPath(['email']), 'value' => 'twelve@test.com'],
        ['path' => new FieldPath(['is_deleted']), 'value' => false],
        ['path' => new FieldPath(['deleted_at']), 'value' => null],
      ]);

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->expects($this->once())
      ->method('document')
      ->with('u-12')
      ->willReturn($docRef);

    $dao = new ConcreteUserDAO($collection, DeleteMode::SOFT);
    $updated = $dao->update($entity);

    $this->assertSame($entity, $updated);
  }

  public function testDeleteHardDeleteMode(): void
  {
    $entity = new UserTestEntity(new UserIdVo('u-13'), 'Thirteen', '13@test.com');

    $docRef = $this->createMock(DocumentReference::class);
    $docRef->expects($this->once())->method('delete');

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->expects($this->once())
      ->method('document')
      ->with('u-13')
      ->willReturn($docRef);

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $result = $dao->delete($entity);

    $this->assertSame($entity, $result);
  }

  public function testDeleteSoftDeleteMode(): void
  {
    $entity = new UserTestEntity(new UserIdVo('u-14'), 'Fourteen', '14@test.com');

    $docRef = $this->createMock(DocumentReference::class);
    $docRef->expects($this->once())
      ->method('update')
      ->with($this->callback(function (array $data) {
        $this->assertInstanceOf(\DateTimeImmutable::class, $data[3]['value']);

        return $this->assertUpdatePayload([
          'name' => 'Fourteen',
          'email' => '14@test.com',
          'is_deleted' => true,
          'deleted_at' => $data[3]['value'],
        ], $data);
      }));

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->expects($this->once())
      ->method('document')
      ->with('u-14')
      ->willReturn($docRef);

    $dao = new ConcreteUserDAO($collection, DeleteMode::SOFT);
    $result = $dao->delete($entity);

    $this->assertSame($entity, $result);
  }

  public function testDeleteByIdFindsAndDeletes(): void
  {
    $docSnap = $this->createStub(DocumentSnapshot::class);
    $docSnap->method('exists')->willReturn(true);
    $docSnap->method('id')->willReturn('u-15');
    $docSnap->method('data')->willReturn(['name' => 'Fifteen', 'email' => '15@test.com']);

    $docRef = $this->createMock(DocumentReference::class);
    $docRef->method('snapshot')->willReturn($docSnap);
    $docRef->expects($this->once())->method('delete');

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('document')->willReturn($docRef);

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $dao->deleteByID('u-15');
  }

  public function testSaveCreatesWhenEntityDoesNotExist(): void
  {
    $entity = new UserTestEntity(new UserIdVo('u-16'), 'Sixteen', '16@test.com');

    $docSnap = $this->createStub(DocumentSnapshot::class);
    $docSnap->method('exists')->willReturn(false);

    $docRef = $this->createMock(DocumentReference::class);
    $docRef->method('snapshot')->willReturn($docSnap);
    $docRef->expects($this->once())
      ->method('create')
      ->with($this->callback(fn ($d) => $d['name'] === 'Sixteen'));

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('document')->willReturn($docRef);

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $saved = $dao->save($entity);

    $this->assertSame($entity, $saved);
  }

  public function testSaveUpdatesWhenEntityExists(): void
  {
    $entity = new UserTestEntity(new UserIdVo('u-17'), 'Seventeen Updated', '17@test.com');

    $docSnap = $this->createStub(DocumentSnapshot::class);
    $docSnap->method('exists')->willReturn(true);
    $docSnap->method('id')->willReturn('u-17');
    $docSnap->method('data')->willReturn(['name' => 'Seventeen', 'email' => '17@test.com']);

    $docRef = $this->createMock(DocumentReference::class);
    $docRef->method('snapshot')->willReturn($docSnap);
    $docRef->expects($this->once())
      ->method('update')
      ->with([
        ['path' => new FieldPath(['name']), 'value' => 'Seventeen Updated'],
        ['path' => new FieldPath(['email']), 'value' => '17@test.com'],
        ['path' => new FieldPath(['is_deleted']), 'value' => false],
      ]);

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('document')->willReturn($docRef);

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $saved = $dao->save($entity);

    $this->assertSame($entity, $saved);
  }

  public function testSaveManySavesEachEntity(): void
  {
    $e1 = new UserTestEntity(new UserIdVo('u-18'), 'E1', 'e1@test.com');
    $e2 = new UserTestEntity(new UserIdVo('u-19'), 'E2', 'e2@test.com');

    $docSnap = $this->createStub(DocumentSnapshot::class);
    $docSnap->method('exists')->willReturn(false);

    $docRef1 = $this->createMock(DocumentReference::class);
    $docRef1->method('snapshot')->willReturn($docSnap);
    $docRef1->expects($this->once())->method('create');

    $docRef2 = $this->createMock(DocumentReference::class);
    $docRef2->method('snapshot')->willReturn($docSnap);
    $docRef2->expects($this->once())->method('create');

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('document')->willReturnCallback(function (string $id) use ($docRef1, $docRef2) {
      return $id === 'u-18' ? $docRef1 : $docRef2;
    });

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $results = $dao->saveMany([$e1, $e2]);

    $this->assertSame([$e1, $e2], $results);
  }

  public function testRestoreThrowsExceptionInHardDeleteMode(): void
  {
    $entity = new UserTestEntity(new UserIdVo('u-20'), 'Twenty', '20@test.com');
    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);

    $this->expectException(EntityNotRecoverable::class);
    $dao->restore($entity);
  }

  public function testRestoreUpdatesDeletedFlagsInSoftDeleteMode(): void
  {
    $entity = new UserTestEntity(new UserIdVo('u-21'), 'Twenty One', '21@test.com', true);

    $docRef = $this->createMock(DocumentReference::class);
    $docRef->expects($this->once())
      ->method('update')
      ->with([
        ['path' => new FieldPath(['name']), 'value' => 'Twenty One'],
        ['path' => new FieldPath(['email']), 'value' => '21@test.com'],
        ['path' => new FieldPath(['is_deleted']), 'value' => false],
        ['path' => new FieldPath(['deleted_at']), 'value' => null],
      ]);

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('document')->willReturn($docRef);

    $dao = new ConcreteUserDAO($collection, DeleteMode::SOFT);
    $restored = $dao->restore($entity);

    $this->assertSame($entity, $restored);
  }

  public static function updateWritePaths(): iterable
  {
    foreach ([false, true] as $transactional) {
      $prefix = $transactional ? 'transactional ' : 'direct ';
      yield $prefix . 'hard-mode update' => ['update', DeleteMode::HARD, $transactional];
      yield $prefix . 'soft-mode update' => ['update', DeleteMode::SOFT, $transactional];
      yield $prefix . 'soft delete' => ['delete', DeleteMode::SOFT, $transactional];
      yield $prefix . 'restore' => ['restore', DeleteMode::SOFT, $transactional];
    }
  }

  #[DataProvider('updateWritePaths')]
  public function testUpdateWritesPreserveNestedCredentialsAndLiteralFieldNames(
    string $operation,
    DeleteMode $deleteMode,
    bool $transactional
  ): void {
    $entity = new class(new UserIdVo('u-credentials'), 'Listener', 'listener@test.com') extends UserTestEntity {
      public function toPrimitives(): array
      {
        return array_merge(parent::toPrimitives(), [
          'integrations' => [
            'spotify' => [
              'access_token' => 'refreshed-access-token',
              'refresh_token' => 'refreshed-refresh-token',
              'expires_at' => 1800000000,
              'scopes' => ['user-read-playback-state', 'user-modify-playback-state'],
              'metadata' => ['optional' => null, 'enabled' => false],
            ],
          ],
          'settings.with.dots' => ['literal.key' => 'unchanged'],
          'special*field/with[brackets]~and`tick\\' => [],
          '0' => 'numeric field name',
        ]);
      }
    };
    $expected = $entity->toPrimitives();
    unset($expected['id']);
    if ($deleteMode === DeleteMode::SOFT) {
      $expected['is_deleted'] = $operation === 'delete';
      $expected['deleted_at'] = null;
    }

    $docRef = $this->createMock(DocumentReference::class);
    $transaction = $transactional ? $this->createMock(Transaction::class) : null;
    $payload = $this->callback(function (array $data) use ($expected, $operation) {
      if ($operation === 'delete') {
        $deletedAt = $data[array_key_last($data)]['value'];
        $this->assertInstanceOf(\DateTimeImmutable::class, $deletedAt);
        $expected['deleted_at'] = $deletedAt;
      }

      return $this->assertUpdatePayload($expected, $data);
    });

    if ($transactional) {
      $docRef->expects($this->never())->method('update');
      $transaction->expects($this->once())->method('update')->with($docRef, $payload);
    } else {
      $docRef->expects($this->once())->method('update')->with($payload);
    }

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('document')->willReturn($docRef);
    $dao = new ConcreteUserDAO($collection, $deleteMode);
    $uow = $transactional ? new FirestoreTransactionUnitOfWork($transaction) : null;

    $this->assertSame($entity, $dao->$operation($entity, $uow));
  }

  #[TestWith([false])]
  #[TestWith([true])]
  public function testUpdateWriteErrorsPropagate(bool $transactional): void
  {
    $entity = new UserTestEntity(new UserIdVo('u-error'), 'Listener', 'listener@test.com');
    $error = new \RuntimeException('Persistence failed');
    $docRef = $this->createMock(DocumentReference::class);
    $transaction = $transactional ? $this->createMock(Transaction::class) : null;

    if ($transactional) {
      $docRef->expects($this->never())->method('update');
      $transaction->expects($this->once())->method('update')->willThrowException($error);
    } else {
      $docRef->expects($this->once())->method('update')->willThrowException($error);
    }

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('document')->willReturn($docRef);
    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $uow = $transactional ? new FirestoreTransactionUnitOfWork($transaction) : null;

    $this->expectExceptionObject($error);
    $dao->update($entity, $uow);
  }

  private function assertUpdatePayload(array $expected, array $actual): bool
  {
    $this->assertTrue(array_is_list($actual));
    $this->assertCount(count($expected), $actual);

    foreach (array_keys($expected) as $index => $field) {
      $this->assertSame(['path', 'value'], array_keys($actual[$index]));
      $this->assertInstanceOf(FieldPath::class, $actual[$index]['path']);
      $this->assertSame([$field], $actual[$index]['path']->path());
      $this->assertSame($expected[$field], $actual[$index]['value']);
    }

    return true;
  }

  public function testCountCountsDocuments(): void
  {
    $doc1 = $this->createStub(DocumentSnapshot::class);
    $doc2 = $this->createStub(DocumentSnapshot::class);

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->expects($this->once())
      ->method('documents')
      ->willReturn(new \ArrayIterator([$doc1, $doc2]));

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $count = $dao->count();

    $this->assertSame(2, $count);
  }

  public function testCountInSoftDeleteMode(): void
  {
    $doc1 = $this->createStub(DocumentSnapshot::class);

    $query = $this->createMock(Query::class);
    $query->expects($this->once())
      ->method('documents')
      ->willReturn(new \ArrayIterator([$doc1]));

    $collection = $this->createMock(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->expects($this->once())
      ->method('where')
      ->with('is_deleted', '==', false)
      ->willReturn($query);

    $dao = new ConcreteUserDAO($collection, DeleteMode::SOFT);
    $count = $dao->count();

    $this->assertSame(1, $count);
  }

  public function testExistsDelegatesToFindOneBy(): void
  {
    $docSnap = $this->createStub(DocumentSnapshot::class);
    $docSnap->method('exists')->willReturn(true);
    $docSnap->method('id')->willReturn('u-30');
    $docSnap->method('data')->willReturn(['name' => 'Thirty', 'email' => '30@test.com']);

    $query = $this->createStub(Query::class);
    $query->method('limit')->willReturnSelf();
    $query->method('documents')->willReturn(new \ArrayIterator([$docSnap]));

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('name')->willReturn('users');
    $collection->method('limit')->willReturn($query);

    $dao = new ConcreteUserDAO($collection, DeleteMode::HARD);
    $this->assertTrue($dao->exists(Criteria::none()));
  }
}
