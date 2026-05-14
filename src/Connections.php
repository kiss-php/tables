<?php
namespace Kiss\Tables;

use PDO;

class Connections {
    static function get(string $flagConnection='') {
        if(isset($GLOBALS["os-agrandesr-tables::connections:$flagConnection"])) return $GLOBALS["os-agrandesr-tables::connections:$flagConnection"];
        $flag = (empty($flagConnection))? 'DB_': 'DB_'.$flagConnection.'_';
        $type=$_ENV[$flag . 'TYPE'];
        switch ($type) {
            case 'mysql':
                $host=$_ENV[$flag . 'HOST'];
                $user=$_ENV[$flag . 'USER'];
                $pass=$_ENV[$flag . 'PASS'];
                $dtbs=$_ENV[$flag . 'DTBS'];
                $port=$_ENV[$flag . 'PORT'];
                $char=isset($_ENV[$flag . 'CHAR']) ? $_ENV[$flag . 'CHAR'] : 'UTF8';
                $dsn = "$type:host=$host;port=$port;dbname=$dtbs;charset=$char";
                break;
            case 'sqlite':
                $path=$_ENV[$flag . 'PATH'];
                $user=null;
                $pass=null;
                $dsn = "$type:$path";
                break;
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if ($type === 'mysql') {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }

        $GLOBALS["os-agrandesr-tables::connections:$flagConnection"] = new PDO($dsn, $user, $pass, $options);

        return $GLOBALS["os-agrandesr-tables::connections:$flagConnection"];
    }

    static function getType(string $flagConnection='') {
        $flag = (empty($flagConnection))? 'DB_': 'DB_'.$flagConnection.'_';
        return $_ENV[$flag . 'TYPE'];
    }
}
