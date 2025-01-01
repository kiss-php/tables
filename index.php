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
    $auth = TablesManager::new('UserAuth');
    $auth->setMail("asdasd@asdasd.es");
    $auth->setPass("asdasdea");
    $auth->setUserId($user->getId());
    $auth->persist();
    die;

    $newRow = TablesManager::new('UserAuth');
    $newRow->setPass('sdasdeasdasdasdeasdasd');
    $newRow->persist();

    $notFound = TablesManager::getById('Animals',10191938);
    echo ($notFound)  ? "Duck found \n" : "Duck not found \n";

    $oldDuck = TablesManager::getById('Animals',1);
    echo $oldDuck->getName();
    $oldDuck->setName('Not weird anymore');
    $oldDuck->persist();
    
    $Animal = TablesManager::new('Animals'); //TablesManager return a Table Class with the properties from the Table definition
    $Animal->setName('Weird duck');
    $Animal->setInteligence(1000);
    $Animal->setGastronomy('frutarian');
    $Animal->persist(); //Now is saved and 

    $Animal->rollback();
} catch(Exception $e) {
    TablesManager::rollback(); //Hace rollback de absolutamente todas las inserciones
    throw $e;
}