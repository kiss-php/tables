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
        $haveId=false;
        $lines = explode("\n", $content);
        
        
        $fields=[
            'id'=>[
                'type'=>'int',
                'primary'=>true,
                'unique'=>true,
                'increment'=>true
            ]
        ];
        $types = [];

        foreach ($lines as $line) {
            $line=trim($line," \t\n\r");
            //$line = str_replace([' ','\t','\n','\r'],'',$line);
            if($line=='') continue;
            //echo $line . "\n";
            list($rawField, $rawData) = explode('[', $line);
            list($type, $rawData) = explode(']',$rawData);
            if(strpos($rawData,'$')!==false) list($rawData, $relation) = explode('$',$rawData);
            if(strpos($rawData, ':')!==false) $options = explode(':',$rawData);

            $fieldName=trim($rawField);
            if($fieldName=='id') continue;

            $fields[$fieldName]=['type'=>$type];
            if(isset($options)) {
                //$fields[$fieldName]['primary']=in_array('primary',$options);
                $fields[$fieldName]['null']=in_array('null',$options);
                $fields[$fieldName]['unique']=in_array('unique',$options);
                $fields[$fieldName]['binary']=in_array('binary',$options);
                $fields[$fieldName]['unsigned']=in_array('unsigned',$options);
                $fields[$fieldName]['zero']=in_array('zero',$options);
                $fields[$fieldName]['increment']=in_array('increment',$options);
                $fields[$fieldName]['generated(default)']=in_array('generated(default)',$options);
                if($relation??false) {
                    list($table, $field)=explode('.',$relation);
                    $fields[$fieldName]['related']=[
                        'table'=>$table,
                        'field'=>$field,
                        'from'=>$fieldName
                    ];
                }
            }
        }

        return $fields;
    }

    public function rollback() {
        echo "\nMassive rollback\n";
    }
}

//php array