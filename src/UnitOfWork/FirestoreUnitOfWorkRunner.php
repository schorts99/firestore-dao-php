<?php

namespace Schorts\FirestoreDAO\UnitOfWork;

use Google\Cloud\Firestore\FirestoreClient;
use Schorts\SharedKernel\UnitOfWork\UnitOfWorkRunner;

use Schorts\FirestoreDAO\UnitOfWork\FirestoreTransactionUnitOfWork;

class FirestoreUnitOfWorkRunner implements UnitOfWorkRunner
{
  private FirestoreClient $firestore;

  public function __construct(FirestoreClient $firestore)
  {
    $this->firestore = $firestore;
  }

  public function run(callable $operation): mixed
  {
    return $this->firestore->runTransaction(function ($transaction) use ($operation) {
      $uow = new FirestoreTransactionUnitOfWork($transaction);

      return $operation($uow);
    });
  }
}
