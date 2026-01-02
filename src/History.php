<?php
namespace Kiss\Tables;

use Exception;

class History {
    public static function __callStatic($method, $arguments) {
        if(!isset($GLOBALS['os-agrandesr-tables::history'])) $GLOBALS['os-agrandesr-tables::history'] = new TrueHistory();
        if (!method_exists($GLOBALS['os-agrandesr-tables::history'], $method)) throw new Exception("$method doesn't exist");
        return $GLOBALS['os-agrandesr-tables::history']->$method(...$arguments);
    }
}

class TrueHistory {
    private $data = [];

    public function save($info) {
        $this->data[] = $info;
    }

    public function printAll() {
        foreach ($this->data as $info) {
            echo $info . PHP_EOL;
        }
    }

    public function getAll() : array {
        return $this->data;
    }
}