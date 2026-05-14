# Tables

Tables is a small PHP ORM-like library that maps simple `.tbl` files to PDO rows.
It is designed for quick projects where table definitions live beside the code and
the library can create or repair the schema when a table or column is missing.

## Install

```bash
composer require kiss-php/tables
```

Or, inside this repository:

```bash
composer dump-autoload
```

## Configuration

Connections are read from `$_ENV`. The default connection uses the `DB_` prefix:

```php
$_ENV['DB_TYPE'] = 'sqlite';
$_ENV['DB_PATH'] = __DIR__ . '/db/app.sqlite';
```

MySQL is also supported:

```php
$_ENV['DB_TYPE'] = 'mysql';
$_ENV['DB_HOST'] = 'localhost';
$_ENV['DB_USER'] = 'root';
$_ENV['DB_PASS'] = '';
$_ENV['DB_DTBS'] = 'app';
$_ENV['DB_PORT'] = '3306';
```

Named connections use `DB_<FLAG>_`, for example `DB_LITLE_TYPE` and
`DB_LITLE_PATH`, then pass the flag to `TablesManager` or `Row` methods.

## Table Definitions

Create `.tbl` files in a folder, one file per table:

```txt
# Tables/User.tbl
user[varchar(90)]:unique
mail[varchar(255)]
active[boolean](true)
phone[varchar(25)]:null
profile@UserProfile.user_id
```

Supported syntax:

- `field[type]`
- `field[type](default)`
- `field[type]:null:unique:unsigned:zero:binary:increment`
- `field[type]$OtherTable.id` for a foreign key stored on the current table
- `field@OneTable.current_id` for a virtual one-to-one lookup
- `field@ManyTable.current_id` for a virtual one-to-many lookup
- `legacy_id=OtherTable.id` for older relation definitions

Every table gets an auto-increment `id` column automatically.

## Usage

```php
require 'vendor/autoload.php';

use Kiss\Tables\TablesManager;

TablesManager::setFolder(__DIR__ . '/Tables');
TablesManager::ensureSchema();

$user = TablesManager::new('User');
$user->setUser('ada');
$user->setMail('ada@example.test');
$user->persist();

$found = TablesManager::getOne('User', ['user' => 'ada']);
$found->setMail('ada@lovelace.test');
$found->persist();
```

Rows expose dynamic getters and setters. CamelCase method names are converted to
snake_case database columns:

```php
$user->setMailVerified(true); // mail_verified
$user->getMailVerified();
```

## Schema Repair

`persist()` and `get()` recover from missing tables by creating the table from
its `.tbl` definition. `persist()` can also add missing columns and retry the
operation. This auto-repair behavior is enabled by default because it is the core
workflow of the library.

You can disable it for stricter environments:

```php
TablesManager::setAutoRepair(false);
```

For explicit setup, call:

```php
TablesManager::ensureSchema();
```

## Rollback

Rows changed through `persist()` or `delete()` are tracked. Calling:

```php
TablesManager::rollback();
```

restores updated/deleted rows or removes rows inserted during the current process.

## History Logs

Tables keeps a small in-memory history. It stores SQL parameters as they are
provided. This keeps the library small and predictable, but it also means you
should not print or export history in production unless you are comfortable with
the values it contains.

You can route logs wherever you want with an optional callback:

```php
use Kiss\Tables\History;

History::callback(function (array $entry) {
    // Send to Monolog, a PSR-3 logger, stdout, a file, etc.
    // $entry contains: type, message, context.
    // Redact or drop sensitive values here if your app needs it.
});
```

Database exceptions are logged as sanitized error events with the exception class
and code, not the raw database error message.

## Security and Operational Notes

Tables is a lightweight library, not a full application security layer. Keep these
points in mind when using it:

- Treat `.tbl` files as trusted code. They drive table names, column names,
  column types, defaults and relations. Do not load table definition folders that
  can be edited by end users.
- Auto-repair is enabled by default and may run `CREATE TABLE` or `ALTER TABLE`
  during normal reads/writes. This is useful for KISS-style development, but it
  means the database user needs DDL permissions. In stricter deployments, run
  `TablesManager::ensureSchema()` during deploy and then call
  `TablesManager::setAutoRepair(false)`.
- `History` stores SQL parameters as provided. Do not call `History::printAll()`
  or export `History::getAll()` in production unless your application has already
  removed or filtered sensitive values.
- If you use `History::callback()`, the callback belongs to your application:
  redact sensitive data there, send logs to your preferred logger, and avoid
  throwing from the callback unless you intentionally want logging failures to
  interrupt database operations.
- `TablesManager::rollback()` is an in-memory convenience helper, not a database
  transaction. It is useful for simple undo flows in the current PHP process, but
  it does not provide ACID guarantees or protect concurrent writes.
- Database credentials should come from environment/configuration management.
  Do not hardcode real credentials in examples, scripts or committed files.

## Smoke Test

Run the SQLite smoke test:

```bash
php tests/smoke.php
```
