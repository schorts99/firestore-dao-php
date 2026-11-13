<?php

namespace Schorts\FirestoreDAO\Formatters;

use Google\Cloud\Firestore\FieldPath;

final class FirestoreUpdateFormatter
{
  public static function format(array $data): array
  {
    $updates = [];

    foreach ($data as $field => $value) {
      // Map keys are literal field names, not dot-delimited paths.
      $updates[] = ['path' => new FieldPath([$field]), 'value' => $value];
    }

    return $updates;
  }
}
