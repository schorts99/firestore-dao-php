<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests\Entity;

use DateTime;
use Google\Cloud\Core\Timestamp;
use PHPUnit\Framework\TestCase;
use Schorts\FirestoreDAO\Entity\EntityFirestoreFactory;
use Schorts\SharedKernel\Entity\Entity;
use Schorts\SharedKernel\ValueObjects\CoordinatesValue;
use Schorts\SharedKernel\ValueObjects\StringValue;

class TestEntityId extends StringValue
{
  public function getAttributeName(): string
  {
    return 'id';
  }
}

class UserLocationValue extends CoordinatesValue
{
  public function getAttributeName(): string
  {
    return 'location';
  }
}

class SampleUserEntity extends Entity
{
  public function __construct(
    TestEntityId $id,
    public string $name,
    public string $email,
    public ?UserLocationValue $location = null,
    public ?DateTime $createdAt = null
  ) {
    parent::__construct($id);
  }

  public function toPrimitives(): array
  {
    $primitives = [
      'id' => (string) $this->id,
      'name' => $this->name,
      'email' => $this->email,
    ];

    if ($this->location !== null) {
      $primitives['location'] = $this->location;
    }

    if ($this->createdAt !== null) {
      $primitives['createdAt'] = $this->createdAt;
    }

    return $primitives;
  }
}

final class EntityFirestoreFactoryTest extends TestCase
{
  public function testFromEntityRemovesIdAndFormatsAttributes(): void
  {
    $id = new TestEntityId('usr-100');
    $coords = new UserLocationValue(['latitude' => 40.7128, 'longitude' => -74.0060]);
    $created = new DateTime('2026-10-30T10:00:00Z');

    $entity = new SampleUserEntity($id, 'John Doe', 'john@example.com', $coords, $created);

    $raw = EntityFirestoreFactory::fromEntity($entity);

    $this->assertArrayNotHasKey('id', $raw);
    $this->assertSame('John Doe', $raw['name']);
    $this->assertSame('john@example.com', $raw['email']);
    $this->assertSame($coords, $raw['location']);
    $this->assertSame($created, $raw['createdAt']);
    $this->assertArrayHasKey('location_geohash', $raw);
    $this->assertArrayHasKey('created_at', $raw);
    $this->assertInstanceOf(Timestamp::class, $raw['created_at']);
    $this->assertSame(12, strlen($raw['location_geohash']));
  }
}
