<?php

namespace Schorts\FirestoreDAO\Query;

final class QueryResult
{
  private array $docs;

  public function __construct(array $docs)
  {
    $this->docs = $docs;
  }

  public function docs(): array
  {
    return $this->docs;
  }

  public function size(): int
  {
    return count($this->docs);
  }

  public function empty(): bool
  {
    return $this->size() === 0;
  }

  public function forEach(callable $callback): void
  {
    foreach ($this->docs as $doc) {
      $callback($doc);
    }
  }
}
