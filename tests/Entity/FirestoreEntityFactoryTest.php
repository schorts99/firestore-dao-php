<?php

declare(strict_types=1);

namespace Schorts\FirestoreDAO\Tests\Entity;

use DateTime;
use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentSnapshot;
use PHPUnit\Framework\TestCase;
use Schorts\FirestoreDAO\Entity\FirestoreEntityFactory;
use Schorts\SharedKernel\Entity\Entity;
use Schorts\SharedKernel\Entity\EntityRegistry;
use Schorts\SharedKernel\Logger\InMemoryLogger;
use Schorts\SharedKernel\ValueObjects\StringValue;

class CustomerId extends StringValue
{
  public function getAttributeName(): string
  {
    return 'id';
  }
}

class CustomerEntity extends Entity
{
  public function __construct(
    CustomerId $id,
    public string $name,
    public ?string $createdAt = null
  ) {
    parent::__construct($id);
  }

  public function toPrimitives(): array
  {
    return [
      'id' => (string) $this->id,
      'name' => $this->name,
      'created_at' => $this->createdAt,
    ];
  }

  public static function fromPrimitives(array $primitives): self
  {
    return new self(
      new CustomerId($primitives['id']),
      $primitives['name'],
      $primitives['created_at'] ?? null
    );
  }
}

final class FirestoreEntityFactoryTest extends TestCase
{
  protected function setUp(): void
  {
    parent::setUp();
    EntityRegistry::clear();
    EntityRegistry::register('customers', CustomerEntity::class);
  }

  protected function tearDown(): void
  {
    EntityRegistry::clear();
    parent::tearDown();
  }

  public function testFromSnapshotReturnsNullWhenNotExists(): void
  {
    $snapshot = $this->createStub(DocumentSnapshot::class);
    $snapshot->method('exists')->willReturn(false);

    $factory = new FirestoreEntityFactory('customers');
    $result = $factory->fromSnapshot($snapshot);

    $this->assertNull($result);
  }

  public function testFromSnapshotHydratesEntityWithFormattedTimestamp(): void
  {
    $dt = new DateTime('2026-10-30T10:00:00+00:00');
    $timestamp = new Timestamp($dt);

    $snapshot = $this->createStub(DocumentSnapshot::class);
    $snapshot->method('exists')->willReturn(true);
    $snapshot->method('id')->willReturn('cust-123');
    $snapshot->method('data')->willReturn([
      'name' => 'Charlie',
      'created_at' => $timestamp,
    ]);

    $logger = new InMemoryLogger();
    $factory = new FirestoreEntityFactory('customers', $logger);
    $entity = $factory->fromSnapshot($snapshot);

    $this->assertInstanceOf(CustomerEntity::class, $entity);
    $this->assertSame('cust-123', (string) $entity->getId());
    $this->assertSame('Charlie', $entity->name);
    $this->assertSame($dt->format(DateTime::ATOM), $entity->createdAt);

    $logs = $logger->getLogs();
    $this->assertNotEmpty($logs);
  }

  public function testFromSnapshotsFiltersNonExistentAndReturnsHydratedList(): void
  {
    $snap1 = $this->createStub(DocumentSnapshot::class);
    $snap1->method('exists')->willReturn(true);
    $snap1->method('id')->willReturn('cust-1');
    $snap1->method('data')->willReturn(['name' => 'One']);

    $snap2 = $this->createStub(DocumentSnapshot::class);
    $snap2->method('exists')->willReturn(false);

    $snap3 = $this->createStub(DocumentSnapshot::class);
    $snap3->method('exists')->willReturn(true);
    $snap3->method('id')->willReturn('cust-3');
    $snap3->method('data')->willReturn(['name' => 'Three']);

    $factory = new FirestoreEntityFactory('customers');
    $entities = $factory->fromSnapshots([$snap1, $snap2, $snap3]);

    $this->assertCount(2, $entities);
    $this->assertSame('cust-1', (string) $entities[0]->getId());
    $this->assertSame('cust-3', (string) $entities[1]->getId());
  }
}
