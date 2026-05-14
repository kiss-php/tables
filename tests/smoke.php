<?php

require_once __DIR__ . '/../src/Connections.php';
require_once __DIR__ . '/../src/History.php';
require_once __DIR__ . '/../src/Reparations.php';
require_once __DIR__ . '/../src/RowReparations.php';
require_once __DIR__ . '/../src/Row.php';
require_once __DIR__ . '/../src/TablesManager.php';

use Kiss\Tables\TablesManager;
use Kiss\Tables\History;

function check($condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$_ENV['DB_TYPE'] = 'sqlite';
$_ENV['DB_PATH'] = ':memory:';

$events = [];
History::callback(function (array $entry) use (&$events): void {
    $events[] = $entry;
});

if (TablesManager::setFolder(__DIR__ . '/../Tables') !== true) {
    throw new RuntimeException('Could not load table definitions');
}
if (TablesManager::ensureSchema() !== true) {
    throw new RuntimeException('Could not create schema');
}

$user = TablesManager::new('User');
$user->setUser('ada');
$user->setUserComplicatedWordTest('ada-key');
$user->setMail('ada@example.test');
$user->setPass('secret');
$user->setName('Ada');
$user->setNameVerified('yes');
$user->persist();

check($user->id() !== null, 'User insert did not assign an id');

$found = TablesManager::getOne('User', ['user' => 'ada']);
check($found !== false, 'User was not found');
check($found->getName() === 'Ada', 'User getter returned unexpected data');

$found->setName('Ada Lovelace');
$found->persist();

$again = TablesManager::getById('User', (int) $found->id());
check($again->getName() === 'Ada Lovelace', 'User update was not persisted');

$profile = TablesManager::new('UserProfile');
$profile->setSrc('profile.png');
$profile->setUserId($again->id());
$profile->persist();

$image = TablesManager::new('UserImages');
$image->setSrc('image.png');
$image->setUserId($again->id());
$image->persist();

check($again->getUserProfile()->getSrc() === 'profile.png', 'One-to-one relation failed');
check(count($again->getUserImages()) === 1, 'One-to-many relation failed');

$history = implode("\n", History::getAll());
check(strpos($history, 'secret') !== false, 'History should keep raw SQL params for user-managed logging');
check(count($events) > 0, 'History callback did not receive events');

$_ENV['DB_REPAIR_TYPE'] = 'sqlite';
$_ENV['DB_REPAIR_PATH'] = ':memory:';
$repairUser = TablesManager::new('User', 'REPAIR');
$repairUser->setUser('repair');
$repairUser->setUserComplicatedWordTest('repair-key');
$repairUser->setMail('repair@example.test');
$repairUser->setPass('repair-secret');
$repairUser->setName('Repair');
$repairUser->setNameVerified('yes');
$repairUser->persist();
check(count(array_filter($events, fn($event) => $event['type'] === 'error')) > 0, 'History callback did not receive repair error event');

TablesManager::rollback();
check(TablesManager::getById('User', (int) $user->id()) === false, 'Rollback did not delete inserted user');

echo "Smoke test passed\n";
