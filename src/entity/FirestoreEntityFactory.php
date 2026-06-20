<?php

namespace Schorts\FirestoreDAO\Entity;

use Google\Cloud\Firestore\DocumentSnapshot;
use Schorts\SharedKernel\Entity\EntityRegistry;
use Schorts\SharedKernel\Model\Model;
use Schorts\SharedKernel\Logger\Logger;
use Schorts\FirestoreDAO\Formatters\FirestoreTypesToPrimitivesFormatter;

final class FirestoreEntityFactory
{
  private string $collectionName;
  private ?Logger $logger;

  public function __construct(string $collectionName, ?Logger $logger = null)
  {
    $this->collectionName = $collectionName;
    $this->logger = $logger;
  }

  public function fromSnapshot(DocumentSnapshot $docSnap): ?object
  {
    $this->logger?->debug('[FirestoreEntityFactory fromSnapshot] started', [
      'docSnap' => $docSnap,
    ]);

    if (!$docSnap->exists()) {
      return null;
    }

    $data = FirestoreTypesToPrimitivesFormatter::format($docSnap->data());

    $this->logger?->debug('[FirestoreEntityFactory fromSnapshot] formatting data', [
      'data' => $data,
    ]);

    $model = new class(array_merge(['id' => $docSnap->id()], $data)) implements Model {
      private array $attributes;
      public function __construct(array $attributes) { $this->attributes = $attributes; }
      public function getId(): string|int { return $this->attributes['id']; }
      public function getAttributes(): array { return $this->attributes; }
    };

    $entity = EntityRegistry::fromPrimitives($this->collectionName, $model);

    $this->logger?->debug('[FirestoreEntityFactory fromSnapshot] completed', [
      'entity' => $entity,
    ]);

    return $entity;
  }

  public function fromSnapshots(array $docs): array
  {
    $this->logger?->debug('[FirestoreEntityFactory fromSnapshots] started', [
      'docs' => $docs,
    ]);

    $entities = [];

    foreach ($docs as $doc) {
      if ($doc->exists()) {
        $entity = $this->fromSnapshot($doc);

        if ($entity !== null) {
          $entities[] = $entity;
        }
      }
    }

    $this->logger?->debug('[FirestoreEntityFactory fromSnapshots] completed', [
      'entities' => $entities,
    ]);

    return $entities;
  }
}
