<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests;

use DateTime;
use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\Query;
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
        'name' => 'Twelve',
        'email' => 'twelve@test.com',
        'is_deleted' => false,
        'deleted_at' => null,
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
        return $data['is_deleted'] === true
          && $data['deleted_at'] instanceof \DateTimeImmutable
          && $data['name'] === 'Fourteen';
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
      ->with($this->callback(fn ($d) => $d['name'] === 'Seventeen Updated'));

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
        'name' => 'Twenty One',
        'email' => '21@test.com',
        'is_deleted' => false,
        'deleted_at' => null,
      ]);

    $collection = $this->createStub(CollectionReference::class);
    $collection->method('id')->willReturn('users');
    $collection->method('document')->willReturn($docRef);

    $dao = new ConcreteUserDAO($collection, DeleteMode::SOFT);
    $restored = $dao->restore($entity);

    $this->assertSame($entity, $restored);
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
