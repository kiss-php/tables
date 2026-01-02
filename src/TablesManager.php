<?php
namespace Kiss\Tables;

use Error;
use Exception;
use PDO;
use PDOException;

class TablesManager {
    public static function __callStatic($method, $arguments) {
        if(!isset($GLOBALS['os-agrandesr-tables::table-manager'])) $GLOBALS['os-agrandesr-tables::table-manager'] = new TrueTablesManager();
        if (!method_exists($GLOBALS['os-agrandesr-tables::table-manager'], $method)) throw new Exception("$method doesn't exist");
        return $GLOBALS['os-agrandesr-tables::table-manager']->$method(...$arguments);
    }
}

class TrueTablesManager {
    private array $tables=[]; //Tables contains the data required to create the class Table for each definition of a table in the tables folder.
    //private array $tasks=[]; //This save all the SQL queries [UPDATE, INSERT] for the TRANSACTION
    private array $rollbacks=[]; //For each task we create a SQL to return the file to previous state
    private $error;

    /**
     * This functions add all the Tables definitions from a folder. This allow to create Table(Class) with the correct data.
     */
    public function setFolder($path) : bool {
        //We need to create a cache system that read quickly all the content in tables
        if(is_dir($path)) {
            $dir = opendir($path);
            while(($tableFile = readdir($dir)) !== false) {
                if ($tableFile != "." && $tableFile != "..") {
                    $fileWithPath = $path . DIRECTORY_SEPARATOR . $tableFile;

                    if (is_file($fileWithPath)) {
                        // Leer el contenido del file
                        $content = file_get_contents($fileWithPath);
                        $tableName=explode('.',$tableFile)[0]; //All type of extensions will be ignored
                        $this->tables[$tableName] = $this->parsecontent($content);
                    }
                }
            }
            return Count($this->tables)>0 ? true : false;
        }
        return false;
    }

    /**
     * This function create a new table class
     */
    public function new(string $tableName, string $flag='') {
        if(!isset($this->tables[$tableName])) throw new Exception("The table you want to create is not defined in the Tables folder (or tables folder is not defined)");
        return new Row($tableName, $this->tables[$tableName], [], $flag);
    }

    public function get(string $tableName, array $search =[], int $limit=0, string $flag='') {
        if(!isset($this->tables[$tableName])) throw new Exception("The table you want to create is not defined in the Tables folder (or tables folder is not defined)");
        try {
            $sql='';
            $pdo=Connections::get($flag);
            $tableName=trim($pdo->quote($tableName)," '\"");
            $sql = "SELECT * FROM $tableName";
            $first=true;
            foreach ($search as $key => $value) {
                if($first) $sql.=" WHERE ";
    
                $key=trim($pdo->quote($key)," '\"");
                $value=$pdo->quote($value);
    
                $sql.=" $key = $value ";
            }
    
            $stmt=$pdo->prepare($sql);
            
            $result = $stmt->execute();
    
            $dataToReturn=[];
            if($result) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $dataToReturn[]=new Row($tableName,$this->tables[$tableName],$row,$flag);
                }
            }
            return $dataToReturn;
        } catch(PDOException $e) {
            throw $e;
        }
    }

    /**
     * This is to get only one element that match with the search
     */
    public function getOne(string $tableName, array $search =[], string $flag='') {
        $data = $this->get( $tableName, $search, 1, $flag);
        return (Count($data)>0) ? $data : false;
    }
    public function getById(string $tableName, int $id, string $flag='') {
        $data = $this->get( $tableName, ['id'=>$id], 1, $flag);
        return (Count($data)>0) ? $data[0] : false;
    }

    private function parseContent($content) {
        // TODO: We need to make some cache of this to make the faster... Only reparse went tables have been changed
        // IMPORTANT - The CACHE file will be created in the tables folder like tables.cache
        $seedData = [];
        if (preg_match('/<default>(.*?)<\/default>/s', $content, $matches)) {
            $csvContent = trim($matches[1]);
            $rows = explode("\n", $csvContent);
            foreach ($rows as $row) {
                if (trim($row) === '') continue;
                $seedData[] = str_getcsv(trim($row));
            }
            $content = str_replace($matches[0], '', $content);
        }

        $lines = explode("\n", $content);
        
        $fields=[
            'id'=>[
                'type'=>'int',
                'primary'=>true,
                'unique'=>true,
                'increment'=>true
            ]
        ];

        foreach ($lines as $line) {
            $line=trim($line," \t\n\r");
            if($line=='' || $line[0]=='#') continue; // Skip comments and empty lines

            // Parse: name[type](default):opt1:opt2$relation
            // 1. Extract name and type: name[type]
            if (!preg_match('/^([^\[]+)\[([^\]]+)\](.*)$/', $line, $matches)) {
                continue; // Invalid format
            }

            $fieldName = trim($matches[1]);
            $type = trim($matches[2]);
            $rest = $matches[3];

            if($fieldName=='id') continue;
            else $fieldName = $this->camelToSnake($fieldName);

            $fields[$fieldName] = ['type' => $type];

            // 2. Extract Default Value: (value)
            // It should be immediately after type, or before other options?
            // Spec says: isActive[boolean](false)
            if (preg_match('/^\(([^)]+)\)(.*)$/', $rest, $defMatches)) {
                $fields[$fieldName]['default'] = $defMatches[1];
                $rest = $defMatches[2];
            }

            // 3. Extract Relation: $Table.field
            // Spec says: userId[int]$User.id (at the end usually, or anywhere?)
            // Assuming end or part of options logic.
            // Let's check for $
            if (strpos($rest, '$') !== false) {
                list($rest, $relation) = explode('$', $rest, 2);
                list($table, $field) = explode('.', $relation);
                $fields[$fieldName]['related'] = [
                    'table' => $table,
                    'field' => $field,
                    'from'  => $fieldName
                ];
            }

            // 4. Extract Modifiers: :opt1:opt2
            if (strpos($rest, ':') !== false) {
                $options = explode(':', $rest);
                // Remove empty first element if line started with :
                $options = array_filter($options, fn($val) => !empty($val));
                
                $fields[$fieldName]['null'] = in_array('null', $options);
                $fields[$fieldName]['unique'] = in_array('unique', $options);
                $fields[$fieldName]['binary'] = in_array('binary', $options);
                $fields[$fieldName]['unsigned'] = in_array('unsigned', $options);
                $fields[$fieldName]['zero'] = in_array('zero', $options);
                $fields[$fieldName]['increment'] = in_array('increment', $options);
                
                // Keep increment logic if type is int?
                if ($fields[$fieldName]['increment']) {
                     // Usually implies primary or unique, but let's just mark it.
                }
            }
        }

        if (!empty($seedData)) {
            $fields['__seed_data'] = $seedData;
        }

        return $fields;
    }

    public function rollback() {
        //@TODO: Massive rollback
    }

    private function camelToSnake($input) {
        $snake = preg_replace('/([a-z])([A-Z])/', '$1_$2', $input);
        return strtolower($snake);
    }
}

//php array