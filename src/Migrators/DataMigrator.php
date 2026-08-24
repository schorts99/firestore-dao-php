<?php

namespace Schorts\FirestoreDAO\Migrators;

use Google\Cloud\Firestore\CollectionReference;
use Schorts\SharedKernel\Logger\Logger;

final class DataMigrator
{
  private CollectionReference $collection;
  private ?Logger $logger;

  public function __construct(CollectionReference $collection, ?Logger $logger = null)
  {
    $this->collection = $collection;
    $this->logger = $logger;
  }

  public function migrateFromHardToSoftDelete(): void
  {
    $this->logger?->debug('[DataMigrator migrateFromHardToSoftDelete] started', [
      'collectionName' => $this->collection->name(),
    ]);

    $snapshot = $this->collection->documents();

    $this->logger?->debug('[DataMigrator migrateFromHardToSoftDelete] migration in progress');

    foreach ($snapshot as $doc) {
      $data = $doc->data();
      $newData = [];

      if (!array_key_exists('is_deleted', $data)) {
        $newData['is_deleted'] = false;
      }

      if (!array_key_exists('deleted_at', $data)) {
        $newData['deleted_at'] = null;
      }

      if (!empty($newData)) {
        $doc->reference()->update($newData);
      }
    }

    $this->logger?->debug('[DataMigrator migrateFromHardToSoftDelete] completed');
  }
}
