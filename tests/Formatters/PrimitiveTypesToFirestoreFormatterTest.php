<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests\Formatters;

use DateTime;
use DateTimeImmutable;
use Google\Cloud\Core\Timestamp;
use PHPUnit\Framework\TestCase;
use Schorts\FirestoreDAO\Formatters\PrimitiveTypesToFirestoreFormatter;
use Schorts\SharedKernel\ValueObjects\CoordinatesValue;
use Schorts\SharedKernel\ValueObjects\DateValue;

class TestLocationValue extends CoordinatesValue
{
  public function getAttributeName(): string
  {
    return 'location';
  }
}

class TestDateValue extends DateValue
{
  public function getAttributeName(): string
  {
    return 'createdAt';
  }
}

final class PrimitiveTypesToFirestoreFormatterTest extends TestCase
{
  public function testFormatEmptyArrayReturnsEmpty(): void
  {
    $result = PrimitiveTypesToFirestoreFormatter::format([]);
    $this->assertSame([], $result);
  }

  public function testFormatCoordinatesEncodesGeohash(): void
  {
    $coords = new TestLocationValue([
      'latitude' => 37.7749,
      'longitude' => -122.4194,
    ]);

    $result = PrimitiveTypesToFirestoreFormatter::format([
      'location' => $coords,
      '_geoCoordinates' => $coords,
    ]);

    $this->assertArrayHasKey('location_geohash', $result);
    $this->assertArrayHasKey('geo_coordinates_geohash', $result);
    $this->assertSame(12, strlen($result['location_geohash']));
    $this->assertSame('9q8yyk8ytpxr', $result['location_geohash']);
    $this->assertSame('9q8yyk8ytpxr', $result['geo_coordinates_geohash']);
  }

  public function testFormatDatesConvertsToTimestamp(): void
  {
    $dt = new DateTime('2026-10-30T10:00:00Z');
    $dti = new DateTimeImmutable('2026-10-30T11:00:00Z');
    $dateVo = new TestDateValue(new DateTime('2026-10-30T12:00:00Z'));

    $result = PrimitiveTypesToFirestoreFormatter::format([
      'publishedAt' => $dt,
      '_modifiedAt' => $dti,
      'createdAt' => $dateVo,
      '_archivedAt' => new TestDateValue(new DateTime('2026-10-30T13:00:00Z')),
    ]);

    $this->assertInstanceOf(Timestamp::class, $result['published_at']);
    $this->assertEquals($dt, $result['published_at']->get());

    $this->assertInstanceOf(Timestamp::class, $result['modified_at']);
    $this->assertEquals($dti, $result['modified_at']->get());

    $this->assertInstanceOf(Timestamp::class, $result['created_at']);
    $this->assertEquals(new DateTime('2026-10-30T12:00:00Z'), $result['created_at']->get());

    $this->assertInstanceOf(Timestamp::class, $result['archived_at']);
  }

  public function testFormatIgnoresNonTargetTypes(): void
  {
    $result = PrimitiveTypesToFirestoreFormatter::format([
      'name' => 'Alice',
      'age' => 30,
      'tags' => ['admin', 'user'],
      'active' => true,
    ]);

    $this->assertSame([], $result);
  }
}
