<?php
require 'vendor/autoload.php';

$_ENV['DB_TYPE']=$_ENV['DB_TYPE'] ?? 'sqlite';
$_ENV['DB_PATH']=$_ENV['DB_PATH'] ?? ':memory:';

$_ENV['DB_LITLE_TYPE']='sqlite';
$_ENV['DB_LITLE_PATH']='db/tablesTest.db';

use Kiss\Tables\History;
use Kiss\Tables\TablesManager;
TablesManager::setFolder('Tables');
TablesManager::ensureSchema();


try {
    $user = TablesManager::new('User');
    $user->setUser('demo');
    $user->setUserComplicatedWordTest('demo-key');
    $user->setMail('demo@example.test');
    $user->setPass('change-me');
    $user->setName('Demo');
    $user->setNameVerified('yes');
    $user->persist();

    echo $user->getUserComplicatedWordTest();
    if (($_ENV['DEBUG'] ?? false) === 'true') {
        History::printAll();
    }
} catch(Exception | Error $e) {
    TablesManager::rollback(); //Hace rollback de absolutamente todas las inserciones
    throw $e;
}
