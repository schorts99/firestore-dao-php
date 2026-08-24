<?php

namespace Schorts\FirestoreDAO\Entity;

use Schorts\SharedKernel\Entity\Entity;
use Schorts\FirestoreDAO\Formatters\PrimitiveTypesToFirestoreFormatter;

final class EntityFirestoreFactory
{
  public static function fromEntity(Entity $entity): array
  {
    $attributes = $entity->toPrimitives()->getAttributes();
    $formatted = PrimitiveTypesToFirestoreFormatter::format($attributes);
    $raw = array_merge($attributes, $formatted);

    unset($raw['id']);

    return $raw;
  }
}
