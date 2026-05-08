<?php

namespace Kiss\Tables;

use Exception;
use PDOException;

class Row {
    use RowReparations;

    private $id;
    private string $flag = '';
    private string $tableName;
    private array $fieldsInfo;
    private array $fieldsData;
    private array $rollbacks = [];
    private bool $deleted = false;

    public function __construct(string $tableName, array $fieldsInfo, array $fieldsData = [], string $flag = '') {
        $this->flag = $flag;
        $this->id = $fieldsData['id'] ?? null;
        $this->tableName = $tableName;
        $this->fieldsInfo = $fieldsInfo;
        $this->fieldsData = $this->normalizeData($fieldsData);
        $this->rollbacks[$flag] = $this->fieldsData;
    }

    public function name(): string {
        return $this->tableName;
    }

    public function data(): array {
        return $this->fieldsData;
    }

    public function originalData($flag = false): array {
        $flag = $flag === false ? $this->flag : $flag;
        return $this->rollbacks[$flag] ?? [];
    }

    public function flag(): string {
        return $this->flag;
    }

    public function info(): array {
        return $this->fieldsInfo;
    }

    public function id() {
        return $this->id;
    }

    public function __call($method, $arguments) {
        if (strpos($method, 'get') === 0) {
            if ($this->deleted) {
                return null;
            }

            $fieldName = self::methodFieldName($method, 'get');
            if (!isset($this->fieldsInfo[$fieldName])) {
                throw new Exception("$method doesn't exist", 1);
            }

            if (($this->fieldsInfo[$fieldName]['virtual'] ?? false) || isset($this->fieldsInfo[$fieldName]['related'])) {
                return $this->resolveRelation($fieldName);
            }

            return $this->fieldsData[$fieldName] ?? $this->defaultFor($fieldName);
        }

        if (strpos($method, 'set') === 0) {
            $fieldName = self::methodFieldName($method, 'set');
            if (!isset($this->fieldsInfo[$fieldName]) || ($this->fieldsInfo[$fieldName]['virtual'] ?? false)) {
                if (($arguments[1] ?? true) === true) {
                    throw new Exception("$method doesn't exist", 1);
                }
                return $this;
            }

            $this->fieldsData[$fieldName] = $arguments[0] ?? null;
            if ($fieldName === 'id') {
                $this->id = $arguments[0] ?? null;
            }
            return $this;
        }

        throw new Exception("$method doesn't exist", 1);
    }

    public function persist($flag = false) {
        $flag = $flag === false ? $this->flag : $flag;

        try {
            $pdo = Connections::get($flag);
            $type = Connections::getType($flag);
            $table = self::quoteIdentifier($this->tableName, $type);
            $data = $this->persistableData();

            if ($this->id) {
                if (empty($this->rollbacks[$flag] ?? []) && !array_key_exists($flag, $this->rollbacks)) {
                    $row = TablesManager::getById($this->tableName, (int) $this->id, $flag);
                    $this->rollbacks[$flag] = $row ? $row->data() : [];
                }

                $assignments = [];
                foreach ($data as $fieldName => $value) {
                    if ($fieldName === 'id') {
                        continue;
                    }
                    $assignments[] = self::quoteIdentifier($fieldName, $type) . " = :$fieldName";
                }

                if (empty($assignments)) {
                    return true;
                }

                $sql = "UPDATE $table SET " . implode(', ', $assignments) . " WHERE " . self::quoteIdentifier('id', $type) . " = :id";
                if ($type !== 'sqlite') {
                    $sql .= ' LIMIT 1';
                }
                $sql .= ';';
                $params = $data;
                $params['id'] = $this->id;
            } else {
                if (empty($data)) {
                    $sql = "INSERT INTO $table DEFAULT VALUES;";
                    $params = [];
                } else {
                    $fieldsString = implode(', ', array_map(fn($field) => self::quoteIdentifier($field, $type), array_keys($data)));
                    $valuesString = ':' . implode(', :', array_keys($data));
                    $sql = "INSERT INTO $table ($fieldsString) VALUES ($valuesString);";
                    $params = $data;
                }
            }

            $stmt = $pdo->prepare($sql);
            History::saveSql($sql, $params);
            $stmt->execute($params);

            if (!$this->id) {
                $this->id = $pdo->lastInsertId();
                $this->fieldsData['id'] = $this->id;
            }

            $this->deleted = false;
            TablesManager::track($this);
            return true;
        } catch (PDOException $pdoException) {
            History::saveError($pdoException);
            if (!TablesManager::autoRepairEnabled()) {
                throw $pdoException;
            }
            $ok = Reparations::errorHandler($pdoException, $this, 'persist', $flag);
            if (!$ok) {
                throw $pdoException;
            }
            return $ok;
        }
    }

    public function delete($flag = false): bool {
        $flag = $flag === false ? $this->flag : $flag;
        if (!$this->id) {
            return true;
        }

        if (!array_key_exists($flag, $this->rollbacks)) {
            $row = TablesManager::getById($this->tableName, (int) $this->id, $flag);
            $this->rollbacks[$flag] = $row ? $row->data() : [];
        }

        $type = Connections::getType($flag);
        $sql = 'DELETE FROM ' . self::quoteIdentifier($this->tableName, $type) . ' WHERE ' . self::quoteIdentifier('id', $type) . ' = :id';
        if ($type !== 'sqlite') {
            $sql .= ' LIMIT 1';
        }
        $sql .= ';';

        $pdo = Connections::get($flag);
        $stmt = $pdo->prepare($sql);
        History::saveSql($sql, ['id' => $this->id]);
        $stmt->execute(['id' => $this->id]);

        $this->deleted = true;
        TablesManager::track($this);
        return true;
    }

    public function reset(): bool {
        foreach ($this->rollbacks as $flag => $originalData) {
            $pdo = Connections::get($flag);
            $type = Connections::getType($flag);
            $table = self::quoteIdentifier($this->tableName, $type);

            if (empty($originalData)) {
                if (!$this->id || $this->deleted) {
                    continue;
                }

                $sql = "DELETE FROM $table WHERE " . self::quoteIdentifier('id', $type) . ' = :id';
                if ($type !== 'sqlite') {
                    $sql .= ' LIMIT 1';
                }
                $sql .= ';';
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $this->id]);
                $this->deleted = true;
                continue;
            }

            if ($this->deleted) {
                $fieldsString = implode(', ', array_map(fn($field) => self::quoteIdentifier($field, $type), array_keys($originalData)));
                $valuesString = ':' . implode(', :', array_keys($originalData));
                $sql = "INSERT INTO $table ($fieldsString) VALUES ($valuesString);";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($originalData);
                $this->deleted = false;
                $this->fieldsData = $originalData;
                $this->id = $originalData['id'] ?? $this->id;
                continue;
            }

            $assignments = [];
            foreach ($originalData as $fieldName => $value) {
                if ($fieldName === 'id') {
                    continue;
                }
                $assignments[] = self::quoteIdentifier($fieldName, $type) . " = :$fieldName";
            }

            if (empty($assignments)) {
                continue;
            }

            $sql = "UPDATE $table SET " . implode(', ', $assignments) . ' WHERE ' . self::quoteIdentifier('id', $type) . ' = :id';
            if ($type !== 'sqlite') {
                $sql .= ' LIMIT 1';
            }
            $sql .= ';';
            $originalData['id'] = $this->id;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($originalData);
            $this->fieldsData = $originalData;
        }

        return true;
    }

    public function create($flag = false, bool $ifNotExists = false) {
        $flag = $flag === false ? $this->flag : $flag;

        try {
            $uniques = [];
            $foreigns = [];
            $conType = Connections::getType($flag);
            $sql = 'CREATE TABLE ' . ($ifNotExists ? 'IF NOT EXISTS ' : '') . self::quoteIdentifier($this->tableName, $conType) . ' (';
            $columns = [];

            foreach ($this->fieldsInfo as $fieldName => $fieldInfo) {
                if ($fieldName === '__seed_data' || ($fieldInfo['virtual'] ?? false)) {
                    continue;
                }

                $columns[] = $this->columnSql($fieldName, $fieldInfo, $conType);

                if (($fieldInfo['unique'] ?? false) && $fieldName !== 'id') {
                    $uniques[] = $fieldName;
                }
                if (isset($fieldInfo['related']) && !($fieldInfo['virtual'] ?? false)) {
                    $foreigns[$fieldName] = $fieldInfo['related'];
                }
            }

            if ($conType !== 'sqlite') {
                $columns[] = 'PRIMARY KEY (' . self::quoteIdentifier('id', $conType) . ')';
            }

            foreach ($uniques as $fieldName) {
                $columns[] = 'UNIQUE (' . self::quoteIdentifier($fieldName, $conType) . ')';
            }

            foreach ($foreigns as $fieldName => $relatedData) {
                $columns[] = 'FOREIGN KEY (' . self::quoteIdentifier($fieldName, $conType) . ') REFERENCES ' .
                    self::quoteIdentifier($relatedData['table'], $conType) . ' (' . self::quoteIdentifier($relatedData['field'], $conType) . ')';
            }

            $sql .= implode(', ', $columns) . ');';
            $pdo = Connections::get($flag);
            $stmt = $pdo->prepare($sql);
            History::saveSql($sql);
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            History::saveError($e);
            if (!TablesManager::autoRepairEnabled()) {
                throw $e;
            }
            return Reparations::errorHandler($e, $this, 'create', $flag);
        }
    }

    public function addColumn($columnName, $flag = false) {
        $flag = $flag === false ? $this->flag : $flag;
        $columnName = self::methodFieldName((string) $columnName, '');

        if (!isset($this->fieldsInfo[$columnName]) || ($this->fieldsInfo[$columnName]['virtual'] ?? false)) {
            return false;
        }

        try {
            $type = Connections::getType($flag);
            $sql = 'ALTER TABLE ' . self::quoteIdentifier($this->tableName, $type) . ' ADD COLUMN ' .
                $this->columnSql($columnName, $this->fieldsInfo[$columnName], $type, true) . ';';

            $pdo = Connections::get($flag);
            $stmt = $pdo->prepare($sql);
            History::saveSql($sql);
            $stmt->execute();
            return true;
        } catch (PDOException $e) {
            History::saveError($e);
            if (!TablesManager::autoRepairEnabled()) {
                throw $e;
            }
            return Reparations::errorHandler($e, $this, 'addColumn', $flag);
        }
    }

    public static function quoteIdentifier(string $identifier, string $type = 'mysql'): string {
        $quote = $type === 'mysql' ? '`' : '"';
        return $quote . str_replace($quote, $quote . $quote, $identifier) . $quote;
    }

    private function resolveRelation(string $fieldName) {
        $relation = $this->fieldsInfo[$fieldName]['related'] ?? null;
        if ($relation === null) {
            return $this->fieldsData[$fieldName] ?? null;
        }

        $mode = $relation['mode'] ?? 'one';
        $fromValue = $this->fieldsData[$relation['from']] ?? null;
        if ($fromValue === null) {
            return $mode === 'many' ? [] : false;
        }

        if (($this->fieldsInfo[$fieldName]['virtual'] ?? false) || $relation['from'] === 'id') {
            $search = [$relation['field'] => $fromValue];
            return $mode === 'many'
                ? TablesManager::get($relation['table'], $search, 0, $this->flag)
                : TablesManager::getOne($relation['table'], $search, $this->flag);
        }

        return $mode === 'many'
            ? TablesManager::get($relation['table'], [$relation['field'] => $fromValue], 0, $this->flag)
            : TablesManager::getOne($relation['table'], [$relation['field'] => $fromValue], $this->flag);
    }

    private function persistableData(): array {
        $data = [];
        foreach ($this->fieldsData as $fieldName => $value) {
            if ($fieldName === 'id' || !isset($this->fieldsInfo[$fieldName]) || ($this->fieldsInfo[$fieldName]['virtual'] ?? false)) {
                continue;
            }
            $data[$fieldName] = $value;
        }

        return $data;
    }

    private function columnSql(string $fieldName, array $fieldInfo, string $conType, bool $forAlter = false): string {
        if ($fieldName === 'id') {
            if ($conType === 'sqlite') {
                return self::quoteIdentifier('id', $conType) . ' INTEGER PRIMARY KEY AUTOINCREMENT';
            }
            return self::quoteIdentifier('id', $conType) . ' INT NOT NULL AUTO_INCREMENT';
        }

        $sql = self::quoteIdentifier($fieldName, $conType) . ' ' . $this->sqlType($fieldInfo['type'], $conType);
        $sql .= ($fieldInfo['null'] ?? false) ? ' NULL' : ' NOT NULL';

        if ($conType === 'mysql') {
            if ($fieldInfo['unsigned'] ?? false) {
                $sql .= ' UNSIGNED';
            }
            if ($fieldInfo['zero'] ?? false) {
                $sql .= ' ZEROFILL';
            }
            if ($fieldInfo['binary'] ?? false) {
                $sql .= ' BINARY';
            }
        }

        if (isset($fieldInfo['default'])) {
            $sql .= ' DEFAULT ' . $this->sqlDefault($fieldInfo['default']);
        }

        if ($forAlter && ($fieldInfo['unique'] ?? false)) {
            $sql .= ' UNIQUE';
        }

        return $sql;
    }

    private function sqlType(string $type, string $conType): string {
        if ($conType === 'sqlite') {
            $lower = strtolower($type);
            if (strpos($lower, 'int') !== false || $lower === 'boolean') {
                return 'INTEGER';
            }
            if (strpos($lower, 'text') !== false) {
                return 'TEXT';
            }
        }

        return $type;
    }

    private function sqlDefault($value): string {
        $lower = strtolower((string) $value);
        if ($lower === 'true') {
            return '1';
        }
        if ($lower === 'false') {
            return '0';
        }
        if ($lower === 'null') {
            return 'NULL';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    private function defaultFor(string $fieldName) {
        if (!isset($this->fieldsInfo[$fieldName]['default'])) {
            return null;
        }

        $default = $this->fieldsInfo[$fieldName]['default'];
        $lower = strtolower((string) $default);
        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        return $default;
    }

    private function normalizeData(array $data): array {
        $normalized = [];
        foreach ($data as $key => $value) {
            $fieldName = self::methodFieldName((string) $key, '');
            $normalized[$fieldName] = $value;
        }
        return $normalized;
    }

    private static function methodFieldName(string $method, string $prefix): string {
        $fieldName = $prefix === '' ? $method : lcfirst(substr($method, strlen($prefix)));
        return strtolower(preg_replace('/([a-z])([A-Z])/', '$1_$2', $fieldName));
    }
}
