<?php

namespace Schorts\FirestoreDAO\Formatters;

use Google\Cloud\Core\Timestamp;
use Location\Coordinate;
use Location\Utility\Geohash;
use Schorts\SharedKernel\ValueObjects\CoordinatesValue;
use Schorts\SharedKernel\ValueObjects\DateValue;
use Schorts\SharedKernel\Formatters\PascalCamelToSnake;

class PrimitiveTypesToFirestoreFormatter
{
  public static function format(array $entity): array
  {
    return array_merge(
      self::formatCoordinates($entity),
      self::formatDates($entity)
    );
  }

  private static function formatCoordinates(array $entity): array
  {
    $geoData = [];

    foreach ($entity as $key => $value) {
      if ($value instanceof CoordinatesValue) {
        $formattedKey = str_starts_with($key, '_') ? substr($key, 1) : $key;
        $snakeKey = PascalCamelToSnake::format($formattedKey);
        $coordinate = new Coordinate($value->getLatitude(), $value->getLongitude());
        $geoData[$snakeKey . '_geohash'] = Geohash::encode($coordinate, 12);
      }
    }

    return $geoData;
  }

  private static function formatDates(array $entity): array
  {
    $formattedDates = [];

    foreach ($entity as $key => $value) {
      if ($value instanceof \DateTimeInterface) {
        $formattedKey = str_starts_with($key, '_') ? substr($key, 1) : $key;
        $snakeKey = PascalCamelToSnake::format($formattedKey);
        $formattedDates[$snakeKey] = new Timestamp($value);
      } elseif ($value instanceof DateValue && $value->getValue() instanceof \DateTimeInterface) {
        $formattedKey = str_starts_with($key, '_') ? substr($key, 1) : $key;
        $snakeKey = PascalCamelToSnake::format($formattedKey);
        $formattedDates[$snakeKey] = new Timestamp($value->getValue());
      }
    }

    return $formattedDates;
  }
}
