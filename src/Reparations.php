<?php
namespace Kiss\Tables;

use Kiss\Tables\Connections;
use Kiss\Tables\Row;

class Reparations {
    static function errorHandler($error, Row &$row, string $retry, string $flag) {
        switch (Connections::getType($flag)) {
            case 'mysql':
                return self::mysqlErrorHandler($error, $row, $retry, $flag);
            case 'sqlite':
                return self::sqliteErrorHandler($error, $row, $retry, $flag);
            case 'postgress':
            default:
                throw new Exception("Unsupported type " . Connections::getType($flag), 1999);
        }
        
    }

    static function mysqlErrorHandler($error, Row &$row, string $retry, string $flag) {
        $errorCode=$error->getCode();
        $errorMsg=$error->getMessage();

        switch ($errorCode) {
            case '42S02': //Base table or view not found for this reason will create the table
                if($retry=='create') return false;

                $row->create($flag);

                return $row->$retry($flag);
            case '42S22': //Column not found
                if($retry=='newTableField') return false;
                
                preg_match("/Unknown column '(.*?)' in /", $errorMsg, $matches);
                if (!isset($matches[1])) throw $error; //If we can't identify column we fail
                $columnName = $matches[1];

                $row->addColumn($columnName, $flag);

                return $row->$retry($flag);
        }
    }
    static function sqliteErrorHandler($error, Row &$row, string $retry, string $flag) {
        $errorCode=$error->getCode();
        $errorMsg=$error->getMessage();

        if(strpos($errorMsg, 'no such table') !== false) { //Base table or view not found for this reason will create the table
            if($retry=='create') return false;

            $row->create($flag);

            return $row->$retry($flag);
        } elseif(strpos($errorMsg, 'has no column named') !== false) {
            if($retry=='newTableField') return false;

            preg_match("/has no column named (\w+)/", $errorMsg, $matches);
            if (!isset($matches[1])) throw $error; //If we can't identify column we fail
            $columnName = $matches[1];

            $row->addColumn($columnName, $flag);

            return $row->$retry($flag);
        } else {
            return false;
        }
    }
}