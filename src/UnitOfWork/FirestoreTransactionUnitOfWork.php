<?php

namespace Schorts\FirestoreDAO\UnitOfWork;

use Google\Cloud\Firestore\Transaction;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\Query;
use Schorts\SharedKernel\UnitOfWork\UnitOfWork;

class FirestoreTransactionUnitOfWork implements UnitOfWork
{
  private Transaction $transaction;

  public function __construct(Transaction $transaction)
  {
    $this->transaction = $transaction;
  }

  public function isActive(): bool
  {
    return true;
  }

  public function begin(): void {}
  public function commit(): void {}

  public function rollback(): void
  {
    throw new \RuntimeException('Manual rollback not supported in Firestore transactions');
  }

  public function get(DocumentReference $ref)
  {
    return $this->transaction->snapshot($ref);
  }

  public function getQuery(Query $query)
{
    $snapshot = $query->documents();
    $refs = [];

    foreach ($snapshot as $doc) {
      $refs[] = $doc->reference();
    }

    return $this->transaction->documents($refs);
}

  public function create(DocumentReference $docRef, array $data): void
  {
    $this->transaction->create($docRef, $data);
  }

  public function update(DocumentReference $docRef, array $data): void
  {
    $this->transaction->update($docRef, $data);
  }

  public function delete(DocumentReference $docRef): void
  {
    $this->transaction->delete($docRef);
  }
}
