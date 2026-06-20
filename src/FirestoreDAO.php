<?php

namespace Schorts\FirestoreDAO;

use Google\Cloud\Firestore\CollectionReference;
use Schorts\SharedKernel\DAO\DAO;
use Schorts\SharedKernel\DAO\DeleteMode;
use Schorts\SharedKernel\Logger\Logger;
use Schorts\SharedKernel\UnitOfWork\UnitOfWork;
use Schorts\SharedKernel\Entity\Entity;
use Schorts\SharedKernel\Criteria\Criteria;
use Schorts\SharedKernel\Criteria\Operator;
use Schorts\SharedKernel\DAO\Exceptions\EntityNotRecoverable;

use Schorts\FirestoreDAO\Entity\FirestoreEntityFactory;
use Schorts\FirestoreDAO\Entity\EntityFirestoreFactory;
use Schorts\FirestoreDAO\UnitOfWork\FirestoreTransactionUnitOfWork;
use Schorts\FirestoreDAO\Query\FirestoreCriteriaQueryExecutor;

final abstract class FirestoreDAO extends DAO
{
  private CollectionReference $collection;
  private FirestoreEntityFactory $firestoreEntityFactory;
  private ?Logger $logger;

  public function __construct(
    CollectionReference $collection,
    DeleteMode $deleteMode = DeleteMode::HARD,
    ?Logger $logger = null,
  ) {
    parent::__construct($deleteMode);

    $this->collection = $collection;
    $this->firestoreEntityFactory = new FirestoreEntityFactory(
      $collection->name(),
      $logger ? $logger->child(['collectionName' => $collection->name()]) : null
    );
    $this->logger = $logger;
  }

  public function findByID(mixed $id, ?UnitOfWork $uow = null, bool $includeDeleted = false): ?Entity
  {
    $docRef = $this->collection->document((string)$id);
    $docSnap = ($uow instanceof FirestoreTransactionUnitOfWork)
      ? $uow->get($docRef)
      : $docRef->snapshot();

    if ($this->deleteMode === DeleteMode::SOFT && $docSnap->exists()) {
      $isDeleted = $docSnap->get('is_deleted');

      if ($isDeleted && !$includeDeleted) return null;
    }

    return $this->firestoreEntityFactory->fromSnapshot($docSnap);
  }

  public function findOneBy(Criteria $criteria, ?UnitOfWork $uow = null, bool $includeDeleted = false): ?Entity
  {
    if ($this->deleteMode === DeleteMode::SOFT && !$includeDeleted) {
      $criteria = $criteria->where('is_deleted', Operator::EQUAL, false);
    }

    $criteria = $criteria->limitResults(1);
    $queryResult = FirestoreCriteriaQueryExecutor::execute($this->collection, $criteria, $uow, $this->logger);

    if ($queryResult->empty()) return null;

    return $this->firestoreEntityFactory->fromSnapshot($queryResult->docs()[0]);
  }

  public function getAll(?UnitOfWork $uow = null, bool $includeDeleted = false): array
  {
    $query = $this->collection->limit(1000);

    if ($this->deleteMode === DeleteMode::SOFT && !$includeDeleted) {
      $query = $query->where('is_deleted', '==', false);
    }

    $snapshot = ($uow instanceof FirestoreTransactionUnitOfWork)
      ? $uow->getQuery($query)
      : $query->documents();
    $docs = iterator_to_array($snapshot);

    return $this->firestoreEntityFactory->fromSnapshots($docs);
  }

  public function search(Criteria $criteria, ?UnitOfWork $uow = null, bool $includeDeleted = false): array
  {
    if ($this->deleteMode === DeleteMode::SOFT && !$includeDeleted) {
      $criteria = $criteria->where('is_deleted', Operator::EQUAL, false);
    }

    $queryResult = FirestoreCriteriaQueryExecutor::execute($this->collection, $criteria, $uow, $this->logger);

    if ($queryResult->empty()) return [];

    return $this->firestoreEntityFactory->fromSnapshots($queryResult->docs());
  }

  public function countBy(Criteria $criteria, ?UnitOfWork $uow = null, bool $includeDeleted = false): int
  {
    if ($this->deleteMode === DeleteMode::SOFT && !$includeDeleted) {
      $criteria = $criteria->where('is_deleted', Operator::EQUAL, false);
    }

    $queryResult = FirestoreCriteriaQueryExecutor::execute($this->collection, $criteria, $uow, $this->logger);

    return $queryResult->size();
  }

  public function create(Entity $entity, ?UnitOfWork $uow = null): Entity
  {
    $docRef = $this->collection->document((string)$entity->getId()->getValue());
    $data = EntityFirestoreFactory::fromEntity($entity);

    if ($this->deleteMode === DeleteMode::SOFT) {
      $data['is_deleted'] = false;
      $data['deleted_at'] = null;
    }

    ($uow instanceof FirestoreTransactionUnitOfWork)
      ? $uow->create($docRef, $data)
      : $docRef->create($data);

    return $entity;
  }

  public function update(Entity $entity, ?UnitOfWork $uow = null): Entity
  {
    $docRef = $this->collection->document((string)$entity->getId()->getValue());
    $data = EntityFirestoreFactory::fromEntity($entity);

    if ($this->deleteMode === DeleteMode::SOFT) {
      $data['is_deleted'] = false;
      $data['deleted_at'] = null;
    }

    ($uow instanceof FirestoreTransactionUnitOfWork)
      ? $uow->update($docRef, $data)
      : $docRef->update($data);

    return $entity;
  }

  public function delete(Entity $entity, ?UnitOfWork $uow = null): Entity
  {
    $docRef = $this->collection->document((string)$entity->getId()->getValue());

    if ($this->deleteMode === DeleteMode::HARD) {
      ($uow instanceof FirestoreTransactionUnitOfWork)
        ? $uow->delete($docRef)
        : $docRef->delete();
    } else {
      $data = EntityFirestoreFactory::fromEntity($entity);
      $data['is_deleted'] = true;
      $data['deleted_at'] = new \DateTimeImmutable();

      ($uow instanceof FirestoreTransactionUnitOfWork)
        ? $uow->update($docRef, $data)
        : $docRef->update($data);
    }

    return $entity;
  }

  public function exists(Criteria $criteria, ?UnitOfWork $uow = null, bool $includeDeleted = false): bool
  {
    return $this->findOneBy($criteria, $uow, $includeDeleted) !== null;
  }

  public function deleteByID(mixed $id, ?UnitOfWork $uow = null): void
  {
    $entity = $this->findByID($id, $uow);

    if ($entity) $this->delete($entity, $uow);
  }

  public function save(Entity $entity, ?UnitOfWork $uow = null): Entity
  {
    $existing = $this->findByID($entity->getId()->getValue(), $uow, true);

    return $existing ? $this->update($entity, $uow) : $this->create($entity, $uow);
  }

  public function saveMany(array $entities, ?UnitOfWork $uow = null): array
  {
    $results = [];

    foreach ($entities as $entity) {
      $results[] = $this->save($entity, $uow);
    }

    return $results;
  }

  public function restore(Entity $entity, ?UnitOfWork $uow = null): Entity
  {
    if ($this->deleteMode === DeleteMode::HARD) {
      throw new EntityNotRecoverable();
    }

    $docRef = $this->collection->document((string)$entity->getId()->getValue());
    $data = EntityFirestoreFactory::fromEntity($entity);
    $data['is_deleted'] = false;
    $data['deleted_at'] = null;

    ($uow instanceof FirestoreTransactionUnitOfWork)
      ? $uow->update($docRef, $data)
      : $docRef->update($data);

    return $entity;
  }
}
