<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests\Query;

use PHPUnit\Framework\TestCase;
use Schorts\FirestoreDAO\Query\QueryResult;

final class QueryResultTest extends TestCase
{
  public function testEmptyQueryResult(): void
  {
    $result = new QueryResult([]);

    $this->assertTrue($result->empty());
    $this->assertSame(0, $result->size());
    $this->assertSame([], $result->docs());

    $called = 0;
    $result->forEach(function () use (&$called) {
      $called++;
    });
    $this->assertSame(0, $called);
  }

  public function testNonEmptyQueryResult(): void
  {
    $docs = ['doc1', 'doc2', 'doc3'];
    $result = new QueryResult($docs);

    $this->assertFalse($result->empty());
    $this->assertSame(3, $result->size());
    $this->assertSame($docs, $result->docs());

    $collected = [];
    $result->forEach(function ($doc) use (&$collected) {
      $collected[] = $doc;
    });

    $this->assertSame($docs, $collected);
  }
}
