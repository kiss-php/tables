<?php

namespace Kiss\Tables;

use Error;
use Exception;
use PDOException;
use Kiss\Tables\RowReparations;
use Kiss\Tables\Reparations;

class Row {
    use RowReparations; //This is an extension of methods for RowReparations

    private $id; //int null
    private string $flag='';
    private string $tableName; //Name of the table at Table Definiton

    private array $fieldsInfo; //This are the fields that contains the information of the DB
    private array $fieldsData;

    private array $rollbacks=[]; //key:flag || value: originalFlagData

    //Rollback required info
    private bool $deleted=false;
    
    public function __construct(string $tableName, array $fieldsInfo, array $fieldsData=[], string $flag='') {
        $this->flag=$flag; //This define the default flag for persist (but you can save to another flag space)

        $this->id=$fieldsData['id']??null;
        $this->tableName=$tableName;

        $this->fieldsInfo=$fieldsInfo;
        
        $this->fieldsData=$fieldsData;
        $this->rollbacks[$flag]=$fieldsData;
    }

    public function name() : string {
        return $this->tableName;
    }

    public function data() : array {
        return $this->fieldsData;
    }

    public function originalData($flag=false) : array {
        return $flag ? $this->rollbacks[$flag] : $this->rollbacks[$this->flag];
    }

    public function flag() : string {
        return $this->flag;
    }

    public function info() : array {
        return $this->fieldsInfo;
    }

    public function __call($method, $arguments) {
        if (strpos($method, 'get') === 0) {
            if($this->deleted) return null;
            $fieldName=lcfirst(substr($method, strlen('get')));
            $fieldName=strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $fieldName));
            if(in_array($fieldName, array_keys($this->fieldsInfo))) return $this->fieldsData[$fieldName]??null;
        } elseif (strpos($method, 'set') === 0) {
            $fieldName=lcfirst(substr($method, strlen('set')));
            $fieldName=strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $fieldName));
            if(in_array($fieldName, array_keys($this->fieldsInfo))) $this->fieldsData[$fieldName]=$arguments[0];
            elseif(($arguments[1]??true)==true) throw new Exception("$method doesn't exist", 1);
        }
    }

    /**
     * Persist the row INSERT or UPDATE
     */
    public function persist($flag=false) {
        try {

            if($this->id) {     //UPDATE ROW
                $sql='UPDATE ' . $this->tableName . ' SET ';
                $first=true;
                foreach ($this->fieldsData as $fieldName => $value) {
                    if($fieldName=='id') continue;
                    if(!$first) $sql.=', ';
                    $sql.=" $fieldName = :$fieldName ";
                    $first=false;
                }
                $sql .= " WHERE id=:id LIMIT 1;";
            } else {            //INSERT ROW
                $sql='INSERT INTO ' . $this->tableName .' ';
                foreach ($this->fieldsData as $fieldName => $value) {
                    if($fieldName=='id') continue;
                    $valuesList[$fieldName]=$value;
                }
                $fieldsString = implode(', ', array_keys($valuesList));
                $valuesString = ':' . implode(',:', array_keys($valuesList)); //Example: ':name, :something, :more'
                $sql .= " ($fieldsString) VALUES ($valuesString);";
            }
            //echo $sql . "\n";
            if($flag!==$this->flag && !in_array($flag, array_keys($this->rollbacks)) && $this->id) {
                // Cogemos datos para inyectarlos con el mismo ID que esta tabla, si tiene ya un id
                $row = TablesManager::getById($this->tableName, $this->id, $flag);
                $this->rollbacks[$flag]=$row->data();
            }

            $pdo = Connections::get($flag ? $flag : $this->flag);

            $stmt=$pdo->prepare($sql);
            History::save("SQL || $sql || ".json_encode($this->fieldsData));
            $stmt->execute($this->fieldsData);

            if(!$this->id) $this->id=$pdo->lastInsertId();

            $this->deleted=false;
            return true;
        } catch (PDOException $pdoException) {
            History::save("PDOException::".$pdoException->getMessage());
            $ok = Reparations::errorHandler($pdoException, $this, 'persist', $flag ? $flag : $this->flag);
            if(!$ok) throw $pdoException;
        }
    }


    /**
     * Delete the row DELETE
     */
    public function delete($flag) {
        $sql='DELETE FROM ' . $this->tableName . " WHERE id=:id LIMIT 1;";

        if($flag!==$this->flag && !in_array($flag, array_keys($this->rollbacks))) {
            //Cogemos datos para inyectarlos con el mismo ID que esta tabla
            $row = TablesManager::getById($this->tableName, $this->id, $flag);
            $this->rollbacks[$flag]=$row->data();
        }

        $pdo = Connections::get($flag ? $flag : $this->flag);
        $stmt=$pdo->prepare($sql);
        History::save("SQL || $sql || ".json_encode(['id'=>$this->id]));
        $stmt->execute(['id'=>$this->id]);

        $this->deleted=true;
        return true;
    }

    /**
     * Return the Row to the initial state, if doesn't exist it is deleted
     */
    public function reset() {
        foreach ($this->rollbacks as $flag => $originalData) {
            $pdo = Connections::get($flag);

            if (empty($originalData)) {
                // If there is no content, delete the row
                if ($this->deleted) return true; // Already deleted, no further action needed
                $sql = 'DELETE FROM ' . $this->tableName . " WHERE id=:id LIMIT 1;";
                $stmt = $ ->prepare($sql);
                $stmt->execute(['id' => $this->id]);
                $this->deleted = true;
            } elseif ($this->deleted) {
                // If the row was deleted, re-insert it with the original data
                $fieldsString = implode(', ', array_keys($originalData));
                $valuesString = ':' . implode(', :', array_keys($originalData));
                $sql = 'INSERT INTO ' . $this->tableName . " ($fieldsString) VALUES ($valuesString);";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($originalData);
                $this->deleted = false;
                $this->fieldsData = $originalData;
            } else {
                // If the row exists, update it to the original values
                $sql = 'UPDATE ' . $this->tableName . ' SET ';
                $first = true;
                foreach ($originalData as $fieldName => $value) {
                    if ($fieldName === 'id') continue;
                    if (!$first) $sql .= ', ';
                    $sql .= "$fieldName = :$fieldName";
                    $first = false;
                }
                $sql .= " WHERE id = :id LIMIT 1;";
                $originalData['id'] = $this->id; // Ensure the ID is included in the parameters
                $stmt = $pdo->prepare($sql);
                $stmt->execute($originalData);
                $this->fieldsData = $originalData;
            }
        }
        return true;
    }

    public function create($flag=false) {
        try {
            $uniques=[];
            $foreigns=[];

            $conType=Connections::getType($flag);

            $sql="CREATE TABLE ". $this->tableName ." (";

            foreach ($this->fieldsInfo as $fieldName => $fieldInfo) {
                if($conType=='sqlite' && $fieldName=='id') {
                    $sql.=' id INTEGER PRIMARY KEY, ';
                    continue;
                }
                $sql.=$fieldName .' '. $fieldInfo['type'];
                $sql.=($fieldInfo['null'] ?? false) ? ' NULL ' : ' NOT NULL ';
                if ($fieldInfo['unsigned']??false) $sql.=' UNSIGNED ';
                if ($fieldInfo['zero']??false) $sql.=' ZEROFILL ';
                if ($fieldInfo['binary']??false) $sql.=' BINARY ';

                if (isset($fieldInfo['default'])) {
                    $defVal = $fieldInfo['default'];
                    if (strtolower($defVal) === 'true') $defVal = 1;
                    elseif (strtolower($defVal) === 'false') $defVal = 0;
                    elseif (strtolower($defVal) === 'null') $defVal = 'NULL';
                    elseif (!is_numeric($defVal)) $defVal = "'$defVal'";
                    $sql.=" DEFAULT $defVal ";
                }

                if ($fieldInfo['unique']??false) $uniques[]=$fieldName;
                if ($fieldInfo['related']??false) $foreigns[$fieldName] = $fieldInfo['related'];

                $sql.=', ';
            }
            $sql = substr($sql, 0, -2); //We remove last ','

            if(!in_array($conType, ['sqlite'])) $sql.=', PRIMARY KEY (id) '; //sqlite not allow this

            if(!empty($uniques))
                $sql.=', UNIQUE ('.implode(',',$uniques).') ';

            foreach ($foreigns as $fieldName=>$relatedData) {
                $table=$relatedData['table'];
                $field=$relatedData['field'];
                $sql.=", FOREIGN KEY ($fieldName) REFERENCES $table ($field) ";
            }
            
            $sql.=");";
            //echo $sql; die;
            $pdo = Connections::get($flag ? $flag : $this->flag);
            $stmt=$pdo->prepare($sql);
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            echo $e->getMessage() . "\n";
            return Reparations::errorHandler($e, $this, 'create', $flag ? $flag : $this->flag);
        }
    }

    public function addColumn($columnName, $flag=false) {
        try {
            $fieldInfo=$this->fieldsInfo;
            $sql="ALTER TABLE ".$this->tableName." ADD COLUMN $columnName " . $fieldInfo[$columnName]['type'];

            $pdo = Connections::get($flag ? $flag : $this->flag);
            $stmt=$pdo->prepare($sql);
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            echo $e->getMessage() . "\n";
            return Reparations::errorHandler($e, $this, 'addColumn', $flag ? $flag : $this->flag);
        }
    }
}