<?php
require 'vendor/autoload.php';




$_ENV['DB_TYPE']='mysql';
$_ENV['DB_HOST']='localhost';
$_ENV['DB_USER']='root';
$_ENV['DB_PASS']='**********';
$_ENV['DB_DTBS']='entitymaster';
$_ENV['DB_PORT']='5555';

use Kiss\Tables\PDOhandler;
use Kiss\Tables\TablesManager;
TablesManager::setFolder('Tables');



$Animal = TablesManager::new('Animals');
$Animal->setName('Weird duck');
$Animal->setInteligence(1000);
$Animal->setGastronomy('frutarian');
$Animal->persist();

$Users = TablesManager::get('User',['name'=>'caat']);

foreach ($Users as $User) {
    echo "Deleting " . $User->getName();
    $User->delete();
}

$User = TablesManager::new('User');
$User->setName('caat');
$User->persist();

echo $Animal->getId();

TablesManager::save();
echo $Animal->getId();
list($globalConnections, $globalHistory, $globalActive) = PDOhandler::getGlobalNames();

print_r($GLOBALS[$globalHistory]??["wwww"]);
return; 


try {   
    TablesManager::setFolder('Tables');
    
    
    $Animal = TablesManager::new('Animals');
    $Animal->setGastronomy('Vegetarian');
    $Animal->setAge(23);
    $Animal->save();
    TablesManager::commit();
    //TablesManager::cpoo();
} catch(PDOException | Exception |Error $e){
    
    echo $e->getMessage() . 'LINE: ' . $e->getLine() . 'FILE: ' .$e->getFile() . "\n" ;
    //PDOhandler::rollback();
    
}
try {
    PDOhandler::rollback();
} catch(PDOException | Exception |Error $e){
    list($globalConnections, $globalHistory, $globalActive) = PDOhandler::getGlobalNames();
    print_r($GLOBALS[$globalHistory]);
    echo $e->getMessage() . 'LINE: ' . $e->getLine() . 'FILE: ' .$e->getFile() . "\n" ;
}
list($globalConnections, $globalHistory, $globalActive) = PDOhandler::getGlobalNames();
print_r($GLOBALS[$globalHistory]);
