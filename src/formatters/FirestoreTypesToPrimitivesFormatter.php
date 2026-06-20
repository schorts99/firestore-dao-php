<?php

namespace Schorts\FirestoreDAO\Formatters;

use Google\Cloud\Core\Timestamp;

class FirestoreTypesToPrimitivesFormatter
{
  public static function format(array $data): array
  {
    return self::formatTimestamps($data);
  }

  private static function formatTimestamps(array $data): array
  {
    $formatted = $data;

    foreach ($formatted as $key => $value) {
      if ($value instanceof Timestamp) {
        $formatted[$key] = $value->get()->format(\DateTime::ATOM);
      }
    }

    return $formatted;
  }
}
