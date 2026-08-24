<?php

namespace Schorts\FirestoreDAO\Query;

use Google\Cloud\Firestore\CollectionReference;
use Schorts\SharedKernel\Criteria\Criteria;
use Schorts\SharedKernel\Logger\Logger;
use Schorts\FirestoreDAO\Translators\CriteriaToFirestoreSymbolsTranslator;
use Schorts\FirestoreDAO\Query\QueryResult;
use Schorts\FirestoreDAO\UnitOfWork\FirestoreTransactionUnitOfWork;
use Location\Coordinate;
use Location\Distance\Vincenty;

final class FirestoreCriteriaQueryExecutor
{
  public static function execute(
    CollectionReference $collection,
    Criteria $criteria,
    ?FirestoreTransactionUnitOfWork $uow = null,
    ?Logger $logger = null
  ): QueryResult {
    $geoFilter = null;

    foreach ($criteria->filters() as $f) {
      if ($f->operator === 'GEO_RADIUS') {
        $geoFilter = $f;

        break;
      }
    }

    $logger?->debug('[FirestoreCriteriaQueryExecutor execute] started', [
        'geoFilter' => $geoFilter,
        'criteria' => $criteria,
        'collection' => $collection->name(),
        'uow' => $uow,
      ],
    );

    if ($geoFilter) {
      $geoField = $geoFilter->field;
      $center = $geoFilter->value['center'];
      $radiusInM = $geoFilter->value['radiusInM'];
      $query = $collection;

      foreach ($criteria->filters() as $filter) {
        if ($filter->field === $geoField) continue;

        $query = $query->where(
          CriteriaToFirestoreSymbolsTranslator::translateField($filter->field),
          CriteriaToFirestoreSymbolsTranslator::translateOperator($filter->operator),
          CriteriaToFirestoreSymbolsTranslator::translateValue($filter->value)
        );
      }

      foreach ($criteria->orders() as $order) {
        $direction = CriteriaToFirestoreSymbolsTranslator::translateOrderDirection($order->direction);
        $query = $direction
          ? $query->orderBy($order->field, $direction)
          : $query->orderBy($order->field);
      }

      if ($criteria->limit()) {
        $query = $query->limit($criteria->limit());
      }

      $snapshot = $uow ? $uow->getQuery($query) : $query->documents();
      $filteredDocs = [];
      $distanceCalc = new Vincenty();

      foreach ($snapshot as $doc) {
        $data = $doc->data();

        if (!isset($data[$geoField])) continue;

        $coords = $data[$geoField];
        $coord = new Coordinate($coords['latitude'], $coords['longitude']);
        $centerCoord = new Coordinate($center[0], $center[1]);
        $distanceInM = $distanceCalc->getDistance($centerCoord, $coord);

        if ($distanceInM <= $radiusInM) {
          $filteredDocs[] = $doc;
        }
      }

      return new QueryResult($filteredDocs);
    }

    $query = $collection;

    foreach ($criteria->filters() as $filter) {
      $query = $query->where(
        CriteriaToFirestoreSymbolsTranslator::translateField($filter->field),
        CriteriaToFirestoreSymbolsTranslator::translateOperator($filter->operator),
        CriteriaToFirestoreSymbolsTranslator::translateValue($filter->value)
      );
    }

    foreach ($criteria->orders() as $order) {
      $direction = CriteriaToFirestoreSymbolsTranslator::translateOrderDirection($order->direction);
      $query = $direction
        ? $query->orderBy($order->field, $direction)
        : $query->orderBy($order->field);
    }

    if ($criteria->limit()) {
      $query = $query->limit($criteria->limit());
    }

    if ($criteria->offset()) {
      $query = $query->offset($criteria->offset());
    }

    $snapshot = $uow ? $uow->getQuery($query) : $query->documents();

    return new QueryResult(iterator_to_array($snapshot));
  }
}
