<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests\UnitOfWork;

use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Firestore\Transaction;
use PHPUnit\Framework\TestCase;
use Schorts\FirestoreDAO\UnitOfWork\FirestoreTransactionUnitOfWork;
use Schorts\FirestoreDAO\UnitOfWork\FirestoreUnitOfWorkRunner;

final class FirestoreUnitOfWorkRunnerTest extends TestCase
{
  public function testRunExecutesOperationInsideTransaction(): void
  {
    $transaction = $this->createStub(Transaction::class);

    $firestore = $this->createMock(FirestoreClient::class);
    $firestore->expects($this->once())
      ->method('runTransaction')
      ->willReturnCallback(function (callable $callback) use ($transaction) {
        return $callback($transaction);
      });

    $runner = new FirestoreUnitOfWorkRunner($firestore);

    $executed = false;
    $result = $runner->run(function ($uow) use (&$executed) {
      $this->assertInstanceOf(FirestoreTransactionUnitOfWork::class, $uow);
      $executed = true;
      return 'success';
    });

    $this->assertTrue($executed);
    $this->assertSame('success', $result);
  }
}
