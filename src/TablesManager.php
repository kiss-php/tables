<?php
namespace Kiss\Tables;

use Exception;
use PDO;
use PDOException;

class TablesManager {
    public static function __callStatic($method, $arguments) {
        if (!isset($GLOBALS['os-agrandesr-tables::table-manager'])) {
            $GLOBALS['os-agrandesr-tables::table-manager'] = new TrueTablesManager();
        }
        if (!method_exists($GLOBALS['os-agrandesr-tables::table-manager'], $method)) {
            throw new Exception("$method doesn't exist");
        }
        return $GLOBALS['os-agrandesr-tables::table-manager']->$method(...$arguments);
    }
}

class TrueTablesManager {
    private array $tables = [];
    private array $trackedRows = [];
    private bool $autoRepair = true;

    public function setFolder($path): bool {
        if (!is_dir($path)) {
            return false;
        }

        $this->tables = [];
        $dir = opendir($path);
        if ($dir === false) {
            return false;
        }

        while (($tableFile = readdir($dir)) !== false) {
            if ($tableFile === '.' || $tableFile === '..') {
                continue;
            }
            if (pathinfo($tableFile, PATHINFO_EXTENSION) !== 'tbl') {
                continue;
            }

            $fileWithPath = $path . DIRECTORY_SEPARATOR . $tableFile;
            if (!is_file($fileWithPath)) {
                continue;
            }

            $content = file_get_contents($fileWithPath);
            if ($content === false) {
                continue;
            }

            $tableName = pathinfo($tableFile, PATHINFO_FILENAME);
            $this->assertValidIdentifier($tableName, 'table');
            $this->tables[$tableName] = $this->parseContent($content);
        }
        closedir($dir);

        return count($this->tables) > 0;
    }

    public function definitions(): array {
        return $this->tables;
    }

    public function definition(string $tableName): array {
        $this->assertTableDefined($tableName);
        return $this->tables[$tableName];
    }

    public function new(string $tableName, string $flag = ''): Row {
        $this->assertTableDefined($tableName);
        return new Row($tableName, $this->tables[$tableName], [], $flag);
    }

    public function get(string $tableName, array $search = [], int $limit = 0, string $flag = ''): array {
        $this->assertTableDefined($tableName);

        try {
            return $this->selectRows($tableName, $search, $limit, $flag);
        } catch (PDOException $e) {
            if (!$this->autoRepair) {
                throw $e;
            }
            $row = $this->new($tableName, $flag);
            $ok = $row->create($flag);
            if (!$ok) {
                throw $e;
            }
            return $this->selectRows($tableName, $search, $limit, $flag);
        }
    }

    public function getOne(string $tableName, array $search = [], string $flag = '') {
        $data = $this->get($tableName, $search, 1, $flag);
        return count($data) > 0 ? $data[0] : false;
    }

    public function getById(string $tableName, int $id, string $flag = '') {
        return $this->getOne($tableName, ['id' => $id], $flag);
    }

    public function setAutoRepair(bool $enabled): bool {
        $this->autoRepair = $enabled;
        return $this->autoRepair;
    }

    public function autoRepairEnabled(): bool {
        return $this->autoRepair;
    }

    public function ensureSchema(string $flag = ''): bool {
        foreach (array_keys($this->tables) as $tableName) {
            $row = $this->new($tableName, $flag);
            $row->create($flag, true);
            $this->seed($tableName, $flag);
        }

        return true;
    }

    public function track(Row $row): void {
        $this->trackedRows[spl_object_hash($row)] = $row;
    }

    public function rollback(): bool {
        $rows = array_reverse($this->trackedRows);
        foreach ($rows as $row) {
            $row->reset();
        }
        $this->trackedRows = [];
        return true;
    }

    private function selectRows(string $tableName, array $search, int $limit, string $flag): array {
        $pdo = Connections::get($flag);
        $sql = 'SELECT * FROM ' . Row::quoteIdentifier($tableName, Connections::getType($flag));
        $params = [];

        if (!empty($search)) {
            $conditions = [];
            foreach ($search as $key => $value) {
                $fieldName = $this->camelToSnake((string) $key);
                $param = ':where_' . $fieldName;
                $conditions[] = Row::quoteIdentifier($fieldName, Connections::getType($flag)) . " = $param";
                $params[$param] = $value;
            }
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $pdo->prepare($sql);
        History::saveSql($sql, $params);
        $stmt->execute($params);

        $dataToReturn = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dataToReturn[] = new Row($tableName, $this->tables[$tableName], $row, $flag);
        }

        return $dataToReturn;
    }

    private function seed(string $tableName, string $flag): void {
        $definition = $this->tables[$tableName];
        if (empty($definition['__seed_data']['rows'])) {
            return;
        }

        foreach ($definition['__seed_data']['rows'] as $rowData) {
            $search = [];
            if (isset($rowData['id'])) {
                $search = ['id' => $rowData['id']];
            } else {
                $firstKey = array_key_first($rowData);
                if ($firstKey !== null) {
                    $search = [$firstKey => $rowData[$firstKey]];
                }
            }

            if (!empty($search) && $this->getOne($tableName, $search, $flag)) {
                continue;
            }

            $row = new Row($tableName, $definition, $rowData, $flag);
            $row->persist($flag);
        }
    }

    private function parseContent($content): array {
        $seedData = $this->extractSeedData($content);
        $content = $seedData['content'];
        $fields = [
            'id' => [
                'type' => 'int',
                'primary' => true,
                'unique' => true,
                'increment' => true,
            ],
        ];

        foreach (explode("\n", $content) as $line) {
            $line = $this->stripComments(trim($line));
            if ($line === '') {
                continue;
            }

            $virtual = $this->parseVirtualRelation($line);
            if ($virtual !== null) {
                $fields[$virtual['name']] = $virtual['info'];
                continue;
            }

            $legacy = $this->parseLegacyRelation($line);
            if ($legacy !== null) {
                $fields[$legacy['name']] = $legacy['info'];
                continue;
            }

            if (!preg_match('/^([^\[]+)\[([^\]]+)\](.*)$/', $line, $matches)) {
                continue;
            }

            $fieldName = trim($matches[1]);
            if ($fieldName === 'id') {
                continue;
            }

            $type = trim($matches[2]);
            if (strtolower($type) === 'id') {
                $type = 'int';
            }
            $this->assertValidSqlType($type);

            $rest = trim($matches[3]);
            $fieldName = $this->camelToSnake($fieldName);
            $this->assertValidIdentifier($fieldName, 'field');
            $fields[$fieldName] = ['type' => $type];

            if (preg_match('/\(([^)]*)\)/', $rest, $defMatches)) {
                $fields[$fieldName]['default'] = $defMatches[1];
                $rest = trim(str_replace($defMatches[0], '', $rest));
            }

            if (strpos($rest, '$') !== false) {
                [$rest, $relation] = explode('$', $rest, 2);
                $fields[$fieldName]['related'] = $this->relationInfo($relation, $fieldName, 'one');
            }

            if (strpos($rest, '@') !== false) {
                [$rest, $relation] = explode('@', $rest, 2);
                $fields[$fieldName]['related'] = $this->relationInfo($relation, $fieldName, 'many');
            }

            $this->applyOptions($fields[$fieldName], $rest);
        }

        if (!empty($seedData['seed'])) {
            $fields['__seed_data'] = $seedData['seed'];
        }

        return $fields;
    }

    private function parseVirtualRelation(string $line): ?array {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*([@$])\s*([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)$/', $line, $matches)) {
            return null;
        }

        $name = $this->camelToSnake($matches[1]);
        $relatedField = $this->camelToSnake($matches[4]);
        $this->assertValidIdentifier($name, 'relation');
        $this->assertValidIdentifier($matches[3], 'related table');
        $this->assertValidIdentifier($relatedField, 'related field');
        $mode = $matches[2] === '@' ? 'many' : 'one';

        return [
            'name' => $name,
            'info' => [
                'type' => 'relation',
                'virtual' => true,
                'related' => [
                    'table' => $matches[3],
                    'field' => $relatedField,
                    'from' => 'id',
                    'mode' => $mode,
                ],
            ],
        ];
    }

    private function parseLegacyRelation(string $line): ?array {
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)(?:\[([^\]]+)\])?\s*=\s*([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*)$/', $line, $matches)) {
            return null;
        }

        $fieldName = $this->camelToSnake($matches[1]);
        $type = !empty($matches[2]) && strtolower($matches[2]) !== 'id' ? $matches[2] : 'int';
        $relatedField = $this->camelToSnake($matches[4]);
        $this->assertValidIdentifier($fieldName, 'field');
        $this->assertValidIdentifier($matches[3], 'related table');
        $this->assertValidIdentifier($relatedField, 'related field');
        $this->assertValidSqlType($type);

        return [
            'name' => $fieldName,
            'info' => [
                'type' => $type,
                'related' => [
                    'table' => $matches[3],
                    'field' => $relatedField,
                    'from' => $fieldName,
                    'mode' => 'one',
                ],
            ],
        ];
    }

    private function relationInfo(string $relation, string $fieldName, string $mode): array {
        $relation = trim($relation);
        $relation = preg_split('/\s+/', $relation)[0];
        [$table, $field] = array_pad(explode('.', $relation, 2), 2, 'id');
        $table = trim($table);
        $field = $this->camelToSnake(trim($field));
        $this->assertValidIdentifier($table, 'related table');
        $this->assertValidIdentifier($field, 'related field');

        return [
            'table' => $table,
            'field' => $field,
            'from' => $fieldName,
            'mode' => $mode,
        ];
    }

    private function applyOptions(array &$field, string $rest): void {
        $options = array_filter(array_map('trim', explode(':', $rest)));
        foreach (['null', 'unique', 'binary', 'unsigned', 'zero', 'increment', 'primary'] as $option) {
            $field[$option] = in_array($option, $options, true);
        }
    }

    private function extractSeedData(string $content): array {
        $seed = [];
        if (preg_match('/<default>(.*?)<\/default>/s', $content, $matches)) {
            $rows = array_values(array_filter(array_map('trim', explode("\n", trim($matches[1])))));
            if (!empty($rows)) {
                $headers = array_map(fn($value) => $this->camelToSnake(trim($value)), str_getcsv(array_shift($rows)));
                foreach ($rows as $row) {
                    $values = str_getcsv($row);
                    $seedRow = [];
                    foreach ($headers as $index => $header) {
                        $seedRow[$header] = $values[$index] ?? null;
                    }
                    $seed[] = $seedRow;
                }
            }
            $content = str_replace($matches[0], '', $content);
        }

        return [
            'content' => $content,
            'seed' => ['rows' => $seed],
        ];
    }

    private function stripComments(string $line): string {
        foreach (['//', '#'] as $marker) {
            $pos = strpos($line, $marker);
            if ($pos !== false) {
                $line = substr($line, 0, $pos);
            }
        }
        return trim($line);
    }

    private function assertTableDefined(string $tableName): void {
        if (!isset($this->tables[$tableName])) {
            throw new Exception("The table '$tableName' is not defined in the Tables folder");
        }
    }

    private function assertValidIdentifier(string $identifier, string $label): void {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new Exception("Invalid $label identifier '$identifier'");
        }
    }

    private function assertValidSqlType(string $type): void {
        $normalized = strtolower(trim($type));
        $allowed = [
            'bool',
            'boolean',
            'date',
            'datetime',
            'time',
            'timestamp',
            'text',
            'longtext',
            'mediumtext',
            'tinytext',
            'blob',
            'longblob',
            'mediumblob',
            'tinyblob',
            'float',
            'double',
            'real',
            'int',
            'integer',
            'bigint',
            'smallint',
            'tinyint',
            'mediumint',
            'varchar',
            'char',
            'decimal',
            'numeric',
        ];

        if (!preg_match('/^([a-z]+)(?:\(([0-9]+)(?:,\s*([0-9]+))?\))?$/', $normalized, $matches)) {
            throw new Exception("Invalid SQL type '$type'");
        }

        if (!in_array($matches[1], $allowed, true)) {
            throw new Exception("Unsupported SQL type '$type'");
        }
    }

    private function camelToSnake($input): string {
        $snake = preg_replace('/([a-z])([A-Z])/', '$1_$2', (string) $input);
        return strtolower($snake);
    }
}
