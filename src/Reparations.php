<?php
namespace Kiss\Tables;

use Exception;
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
                if($retry=='addColumn') return false;
                
                preg_match("/Unknown column '(.*?)' in /", $errorMsg, $matches);
                if (!isset($matches[1])) throw $error; //If we can't identify column we fail
                $columnName = $matches[1];

                $row->addColumn($columnName, $flag);

                return $row->$retry($flag);
            case '23000': 
                // Integrity Constrain Violation:
                // 1062 Duplicate entry: We can try to update the row or ignore
                // 1452 Foreign key constraint fails: We might need to create the parent row first
                // 1048 Column cannot be null: We can try to alter table to make it nullable or set a default
                break;
            case 'HY000':
                // General Error:
                // 1364 Field doesn't have a default value: Alter table to add default or make nullable
                // 1265 Data truncated: Alter table to increase column size (varchar, int, etc)
                break;
            case '42000':
                // Syntax error or access violation
                // 1075 Incorrect table definition (auto column must be key): Add primary key
                // 1071 Specified key was too long: Reduce length of column or change encoding
                break;
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
            if($retry=='addColumn') return false;

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
