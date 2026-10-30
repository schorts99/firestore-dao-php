<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests\UnitOfWork;

use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\Query;
use Google\Cloud\Firestore\QuerySnapshot;
use Google\Cloud\Firestore\Transaction;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Schorts\FirestoreDAO\UnitOfWork\FirestoreTransactionUnitOfWork;

final class FirestoreTransactionUnitOfWorkTest extends TestCase
{
  public function testIsActiveReturnsTrue(): void
  {
    $transaction = $this->createStub(Transaction::class);
    $uow = new FirestoreTransactionUnitOfWork($transaction);

    $this->assertTrue($uow->isActive());
  }

  public function testBeginAndCommitAreNoOps(): void
  {
    $transaction = $this->createStub(Transaction::class);
    $uow = new FirestoreTransactionUnitOfWork($transaction);

    $uow->begin();
    $uow->commit();
    $this->assertTrue($uow->isActive());
  }

  public function testRollbackThrowsRuntimeException(): void
  {
    $transaction = $this->createStub(Transaction::class);
    $uow = new FirestoreTransactionUnitOfWork($transaction);

    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('Manual rollback not supported in Firestore transactions');
    $uow->rollback();
  }

  public function testGetDelegatesToTransactionSnapshot(): void
  {
    $docRef = $this->createStub(DocumentReference::class);
    $docSnap = $this->createStub(DocumentSnapshot::class);

    $transaction = $this->createMock(Transaction::class);
    $transaction->expects($this->once())
      ->method('snapshot')
      ->with($docRef)
      ->willReturn($docSnap);

    $uow = new FirestoreTransactionUnitOfWork($transaction);
    $result = $uow->get($docRef);

    $this->assertSame($docSnap, $result);
  }

  public function testGetQueryCollectsReferencesAndFetchesSnapshots(): void
  {
    $docRef1 = $this->createStub(DocumentReference::class);
    $docRef2 = $this->createStub(DocumentReference::class);

    $snap1 = $this->createStub(DocumentSnapshot::class);
    $snap1->method('reference')->willReturn($docRef1);

    $snap2 = $this->createStub(DocumentSnapshot::class);
    $snap2->method('reference')->willReturn($docRef2);

    $query = $this->createMock(Query::class);
    $query->expects($this->once())
      ->method('documents')
      ->willReturn(new \ArrayIterator([$snap1, $snap2]));

    $transaction = $this->createMock(Transaction::class);
    $transaction->expects($this->once())
      ->method('documents')
      ->with([$docRef1, $docRef2])
      ->willReturn([$snap1, $snap2]);

    $uow = new FirestoreTransactionUnitOfWork($transaction);
    $result = $uow->getQuery($query);

    $this->assertSame([$snap1, $snap2], $result);
  }

  public function testCreateUpdateDeleteOperations(): void
  {
    $docRef = $this->createStub(DocumentReference::class);
    $data = ['field' => 'value'];

    $transaction = $this->createMock(Transaction::class);
    $transaction->expects($this->once())->method('create')->with($docRef, $data);
    $transaction->expects($this->once())->method('update')->with($docRef, $data);
    $transaction->expects($this->once())->method('delete')->with($docRef);

    $uow = new FirestoreTransactionUnitOfWork($transaction);
    $uow->create($docRef, $data);
    $uow->update($docRef, $data);
    $uow->delete($docRef);
  }
}
