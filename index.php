<?php
require 'vendor/autoload.php';

$_ENV['DB_TYPE']='mysql';
$_ENV['DB_HOST']='localhost';
$_ENV['DB_USER']='root';
$_ENV['DB_PASS']='**********';
$_ENV['DB_DTBS']='entitymaster';
$_ENV['DB_PORT']='5555';

$_ENV['DB_LITLE_TYPE']='sqlite';
$_ENV['DB_LITLE_PATH']='db/tablesTest.db';

use Kiss\Tables\TablesManager;
TablesManager::setFolder('Tables');


try {
    $user = TablesManager::getById('User',1);
    $user2 = TablesManager::new('User');
    $user2->setName("tst");
    $user2->setNameVerified(true);
    $user2->persist();
} catch(Exception $e) {
    TablesManager::rollback(); //Hace rollback de absolutamente todas las inserciones
    throw $e;
}