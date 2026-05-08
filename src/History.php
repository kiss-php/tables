<?php
namespace Kiss\Tables;

use Exception;
use Throwable;

class History {
    public static function __callStatic($method, $arguments) {
        if (!isset($GLOBALS['os-agrandesr-tables::history'])) {
            $GLOBALS['os-agrandesr-tables::history'] = new TrueHistory();
        }
        if (!method_exists($GLOBALS['os-agrandesr-tables::history'], $method)) {
            throw new Exception("$method doesn't exist");
        }
        return $GLOBALS['os-agrandesr-tables::history']->$method(...$arguments);
    }
}

class TrueHistory {
    private array $data = [];
    private $callback = null;

    public function callback(?callable $callback = null): bool {
        $this->callback = $callback;
        return true;
    }

    public function save($info) {
        $this->record('info', (string) $info);
    }

    public function saveSql(string $sql, array $params = []) {
        $this->record('sql', 'SQL', [
            'sql' => $sql,
            'params' => $params,
        ]);
    }

    public function saveError(Throwable $error) {
        $this->record('error', 'Database operation failed', [
            'code' => (string) $error->getCode(),
            'class' => get_class($error),
        ]);
    }

    public function printAll() {
        foreach ($this->data as $info) {
            echo $info . PHP_EOL;
        }
    }

    public function getAll(): array {
        return $this->data;
    }

    private function record(string $type, string $message, array $context = []): void {
        $entry = [
            'type' => $type,
            'message' => $message,
            'context' => $context,
        ];

        $this->data[] = $this->format($entry);

        if ($this->callback !== null) {
            call_user_func($this->callback, $entry);
        }
    }

    private function format(array $entry): string {
        if ($entry['type'] === 'sql') {
            return 'SQL || ' . $entry['context']['sql'] . ' || ' . json_encode($entry['context']['params']);
        }

        if ($entry['type'] === 'error') {
            return 'ERROR || ' . $entry['message'] . ' || ' . json_encode($entry['context']);
        }

        return $entry['message'];
    }
}
