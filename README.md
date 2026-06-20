# schorts/firestore-dao

A type‑safe, domain‑driven Data Access Object (DAO) abstraction for Google Cloud Firestore in PHP.

Provides consistent CRUD operations, soft/hard delete support, criteria‑based queries, and transaction‑aware Unit of Work integration, built on top of the [schorts/shared‑kernel](https://packagist.org/packages/schorts/shared-kernel).

## 🚀 Installation

```bash
composer require schorts/firestore-dao
```

## ⚙️ Usage Example

### Basic DAO

```php
use Schorts\FirestoreDAO\FirestoreDAO;
use Schorts\SharedKernel\DAO\DeleteMode;

final class UserDAO extends FirestoreDAO
{
  // Extend with domain-specific methods if needed
}
```

### CRUD Operations

```php
$userDao = new UserDAO($firestore->collection('users'), DeleteMode::SOFT);

// Create
$userDao->create($user);

// Find
$found = $userDao->findByID($user->getId()->getValue());

// Update
$userDao->update($user);

// Delete (soft delete by default)
$userDao->delete($user);

// Restore
$userDao->restore($user);
```

## 🔄 Transaction Support

```php
$firestore->runTransaction(function ($transaction) use ($userDao) {
  $uow = new \Schorts\FirestoreDAO\UnitOfWork\FirestoreTransactionUnitOfWork($transaction);

  $user = $userDao->findByID('123', $uow);
  $user->setName('Updated Name');

  $userDao->update($user, $uow);
});

```

## 🗂 Features

- **Soft delete** with `is_deleted` and `deleted_at` fields.
- **Hard delete** for permanent removal.
- **Criteria queries** with filters, ordering, limits, offsets.
- **Geospatial queries** using Vincenty distance calculation.
- **Unit of Work** integration for transaction‑aware operations.
- **Consistent contract** across DAOs in different backends.

## 📖 License

LGPL-3.0-or-later
