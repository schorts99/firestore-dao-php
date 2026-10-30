<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests\Formatters;

use DateTime;
use Google\Cloud\Core\Timestamp;
use PHPUnit\Framework\TestCase;
use Schorts\FirestoreDAO\Formatters\FirestoreTypesToPrimitivesFormatter;

final class FirestoreTypesToPrimitivesFormatterTest extends TestCase
{
  public function testFormatEmptyArray(): void
  {
    $this->assertSame([], FirestoreTypesToPrimitivesFormatter::format([]));
  }

  public function testFormatConvertsTimestampToAtomString(): void
  {
    $dt = new DateTime('2026-10-30T14:00:00+00:00');
    $ts = new Timestamp($dt);

    $formatted = FirestoreTypesToPrimitivesFormatter::format([
      'created_at' => $ts,
      'name' => 'Item',
      'count' => 5,
      'is_active' => true,
    ]);

    $this->assertSame($dt->format(DateTime::ATOM), $formatted['created_at']);
    $this->assertSame('Item', $formatted['name']);
    $this->assertSame(5, $formatted['count']);
    $this->assertTrue($formatted['is_active']);
  }
}
