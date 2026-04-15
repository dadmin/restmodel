<?php

namespace RestModel\Models;

use \RestModel\Exceptions\ModelException;
use ORM;

abstract class Model {

    private static ?array $defaultDbSettings = null;
    private static mixed $defaultLogger = null;

    public static function setDefaults(array $dbSettings, mixed $logger = null): void {
        self::$defaultDbSettings = $dbSettings;
        self::$defaultLogger = $logger;
    }

    protected $connectionName;

    protected $logger;
    protected $dbSettings;
    protected $tableName;
    protected $updateMode = false;

    protected $validators = [];
    protected $fields = [];
    protected $values = [];
    protected $errors = [];
    protected $postponeDeleteOnFieldName = 'deleted_on';

    public function getORM() {
        return ORM::for_table($this->tableName, $this->connectionName);
    }

    public function getPDO() {
        return ORM::get_db($this->connectionName);
    }

    public function isUpdateMode() {
        return $this->updateMode;
    }

    public function getFieldsValidators() {
        return $this->validators;
    }

    public function setLogger($logger) {

        $this->logger = $logger;

        ORM::configure('logging', true, $this->connectionName);
        ORM::configure('logger', function($log_string, $query_time) use ($logger) {
            $logger->debug($log_string . ' in ' . $query_time);
        }, $this->connectionName);

    }

    public function init($dbSettings, $logger = null) {

        $this->connectionName = !empty($dbSettings['connectionName']) ? $dbSettings['connectionName'] : $dbSettings['dbname'];

        ORM::configure("pgsql:host={$dbSettings['host']};dbname={$dbSettings['dbname']}", null, $this->connectionName);
        ORM::configure('username', $dbSettings['username'], $this->connectionName);
        ORM::configure('password', $dbSettings['password'], $this->connectionName);
        ORM::configure('driver_options', [
            \PDO::ATTR_EMULATE_PREPARES => $dbSettings['emulatePrepares'],
            \PDO::ATTR_PERSISTENT => $dbSettings['persistent'],
        ],$this->connectionName);

        $this->dbSettings = $dbSettings;

        if($logger && isset($dbSettings['debug']) && $dbSettings['debug']) {
            $this->setLogger($logger);

        }
        
    }


    public function __construct(?array $dbSettings = null, mixed $logger = null) {

        $dbSettings ??= self::$defaultDbSettings;
        $logger ??= self::$defaultLogger;

        if (!is_null($dbSettings)) {
            $this->init($dbSettings, $logger);
        }

        $this->fields = $this->getFieldsValidators();

        //fields deleted_on can not be set|changed and not include on validation.
        if(isset($this->fields[$this->postponeDeleteOnFieldName])) {
            unset($this->fields[$this->postponeDeleteOnFieldName]);
        }

    }

    public function setFieldsValidators($validators) {
        $this->validators = $validators;
        return $this;
    }

    protected function flushValues() {
        foreach($this->fields as $key => $v) {
            if($this->fields[$key]) {
                $this->setValue($key, null, true);
            }
        }
    }

    protected function setError($key, $value) {
        $this->errors[$key] = $value;
    }

    protected function getError($key) {
        return isset($this->errors[$key]) ? $this->errors[$key] : null;
    }

    protected function flushErrors() {
        $this->errors = [];
    }

    public function getErrors() {
        return $this->errors;
    }


    public function setValues($values, $updateMode = false) {

        $this->updateMode = $updateMode;

        $this->flushErrors();

        if(!$updateMode) {
            $this->flushValues();
        }

        foreach($values as $key => $value) {
            $this->setValue($key, $value, $updateMode);
        }

        return $this;
    }

    public function setValue($field, $value, $force = false) {
/*
        if(isset($this->fields[$field]) && !$this->fields[$field]) {
            return;
        }
*/

        if($force || (isset($this->fields[$field]) && $this->fields[$field])) {
            $this->values[$field] = $value;
        }
    }

    public function getValue($key) {
        return isset($this->values[$key]) ? $this->values[$key] : null;
    }

    protected function beforeValidateValues() {}

    protected function afterValidateValues() {}


    public function validateValues() {

        $this->beforeValidateValues();

        foreach($this->values as $field => $val) {
            $this->validateValue($field, $this->values[$field]);
        }

        if($this->errors) {

            ModelException::throwException($this->errors);
        }

        $this->afterValidateValues();

        return $this;
    }

    protected function validateValue($field, $value) {

        if(!isset($this->fields[$field])) {

            $this->setError($field, "Field doesn't exist");
            return false;

        } elseif($this->fields[$field] && !$this->fields[$field]->validate($value)) {

            $this->setError($field, "Missing or invalid value");
            return false;
        }

        return true;

    }

    public function save() {


        if(!empty($this->values['id'])) {
            $row = ORM::for_table($this->tableName, $this->connectionName)->find_one($this->values['id']);
        } else {
            $row = ORM::for_table($this->tableName, $this->connectionName)->create();
        }

        //error_log(print_r($row,1));
        foreach($this->values as $field => $value) {
            if($field === 'id') {
                continue;
            }
            $row->set($field, $value);
        }

        try {
            //error_log(print_r($row,1));
            $row->save();

        } catch (\Exception $e) {
            ModelException::throwException($e->getMessage());
        }

        $this->values['id'] = $row->id();

        return $this->values['id'];


    }

    public function getByCode($code) {
        $row = ORM::for_table($this->tableName, $this->connectionName)->where('code', $code)->find_array();
        $row = $row && isset($row[0]) ? $row[0] : [];
        return $row;
    }

    public function getByField($field, $value) {
        $row = ORM::for_table($this->tableName, $this->connectionName)->where_equal($field, $value)->find_array();
        return $row ? $row : [];
    }

    public function getById($id) {
        $row = ORM::for_table($this->tableName, $this->connectionName)->find_one($id);
        return $row ? $row->as_array() : [];
    }

    public function getTotalCount($params = null) {

        $orm = ORM::for_table($this->tableName, $this->connectionName);

        $orm = $this->applyFilterToORM($orm, $params);

        if(isset($this->fields[$this->postponeDeleteOnFieldName])) {
            $orm->where_null($this->postponeDeleteOnFieldName);
        }

        $count = $orm->count();
/*
        if($params && $params->getOffset()) {
            $count = $params->getOffset() < $count ? $count - $params->getOffset() : 0;
        }
*/

        return $count;

    }


    protected function applyFilterToORM(ORM $orm, $params = null) {

        if(is_null($params)) {
            return $orm;
        }

        $filters = $params->getFilter();
        $fields = $this->getFieldsValidators();

        foreach($filters as $filter) {
            foreach(array_keys($fields) as $field) {

                if(!array_key_exists($field, $filter)) {
                    continue;
                } else {

                    $fieldParams = $filter[$field];

                }

                $fromToFlag = false;

                if(is_array($fieldParams) && array_key_exists('not', $fieldParams) && !is_array($fieldParams['not'])) {
                    $fieldParams['not'] = [$fieldParams['not']];
                }

                if(is_array($fieldParams)) {
                    if(isset($fieldParams['not'])) {
                        foreach($fieldParams['not'] as $value) {
                            if(is_null($value)) {
                                $orm->where_not_null($field);
                            } else {
                                $orm->where_not_equal($field, $value);
                            }
                        }

                    } else {

                        if (isset($fieldParams['from']) && is_scalar($fieldParams['from'])) {

                            // Date fields should end by '_on' (posted_on)
                            if (substr($field, 0, 3) != 'is_' && substr($field, -3) == '_on') {

                                $time = strtotime((string)$fieldParams['from']);

                                $from = $time !== false ? date("Y-m-d H:i:s", $time) : false;
                            } else {
                                $from = $fieldParams['from'];
                            }

                            if ($fields[$field] && !$fields[$field]->validate($from)) {
                                ModelException::throwException("Wrong '$field' parameter");
                            }

                            if ($from !== false) {
                                $orm->where_gte($field, $from);
                                $fromToFlag = true;
                            }
                        }


                        if (isset($fieldParams['to']) && is_scalar($fieldParams['to'])) {

                            if (substr($field, 0, 3) != 'is_' && substr($field, -3) == '_on') {
                                $time = strtotime((string)$fieldParams['to']);
                                $to = $time !== false ? date("Y-m-d H:i:s", $time) : false;
                            } else {
                                $to = $fieldParams['to'];
                            }

                            if ($fields[$field] && !$fields[$field]->validate($to)) {
                                ModelException::throwException("Wrong '$field' parameter");
                            }

                            if ($to !== false) {
                                $orm->where_lte($field, $to);
                                $fromToFlag = true;
                            }
                        }

                        if (!$fromToFlag) {
                            $orm->where_in($field, $fieldParams);
                        }
                    }
                } else {

                    if (is_null($fieldParams)) {
                        $orm->where_null($field);
                    } else {

                        // Logical fields should start by 'is_' (is_logo_on)
                        if (substr($field, 0, 3) == 'is_') {
                            $fieldParams = !!$fieldParams;
                        } // Date fields should end by '_on' (posted_on)
                        elseif (substr($field, -3) == '_on') {

                            if($fields[$field] && !$fields[$field]->validate($fieldParams)) {
                                ModelException::throwException("Wrong '$field' parameter");
                            }

                            $time = strtotime((string)$fieldParams);
                            $fieldParams = $time !== false ? date("Y-m-d H:i:s", $time) : false;
                        }

                        if (is_string($fieldParams) && strpos($fieldParams, '%') !== false) {
                            $orm->where_like($field, $fieldParams);
                        } else {

                            if($fields[$field] && !$fields[$field]->validate($fieldParams)) {
                                ModelException::throwException("Wrong '$field' parameter");
                            }

                            $orm->where_equal($field, $fieldParams);
                        }

                    }

                }
            }
        }

        return $orm;
    }

    protected function applyOrderToORM(ORM $orm, $params = null) {

        if(is_null($params)) {
            return $orm;
        }

        $orders = $params->getOrder();

        foreach($orders as $order) {

            if(!is_array($order)) {
                ModelException::throwException("Wrong 'order' parameter");
            }

            $orderField = array_keys($order);

            if(!is_array($orderField) || !isset($orderField[0])) {
                ModelException::throwException("Wrong 'order' parameter");
            }

            $orderField = $orderField[0];

            $fields = $this->getFieldsValidators();

            if(!isset($fields[$orderField])) {
                continue;
            }

            if(strtolower($order[$orderField]) == 'asc') {
                $orm->order_by_asc($orderField);
            }

            if(strtolower($order[$orderField]) == 'desc') {
                $orm->order_by_desc($orderField);
            }
        }

        return $orm;
    }

    public function getManySQL($sql, $params=[]) {
        return self::getORM()->raw_query($sql, $params)->find_array();
    }

    public function getMany($params = null) {

        $orm = ORM::for_table($this->tableName, $this->connectionName);

        if(isset($this->fields[$this->postponeDeleteOnFieldName])) {
            $orm->where_null($this->postponeDeleteOnFieldName);
        }

        if($params && $params->getOffset()) {
            $orm->offset($params->getOffset());
        }

        if($params && $params->getLimit()) {
            $orm->limit($params->getLimit());
        }

        $orm = $this->applyFilterToORM($orm, $params);
        $orm = $this->applyOrderToORM($orm, $params);

        return $orm->find_array();
    }

    protected function beforeDelete($id) {

    }

    public function delete($id) {

        $this->beforeDelete($id);

        $row = ORM::for_table($this->tableName, $this->connectionName)->find_one($id);

        if($row) {
            try {
                $row->delete();
            } catch(\Exception $e) {
                ModelException::throwException($e->getMessage());
            }
        }

    }

    public function markAsDeleted($id) {

        $row = ORM::for_table($this->tableName, $this->connectionName)->find_one($id);

        if($row) {
            $row->set_expr($this->postponeDeleteOnFieldName, 'NOW()');
            try {
                $row->save();
            } catch(\Exception $e) {
                ModelException::throwException($e->getMessage());
            }
        }
    }

    public function markAsUnDeleted($id) {

        $row = ORM::for_table($this->tableName, $this->connectionName)->find_one($id);

        if($row) {
            $row->set($this->postponeDeleteOnFieldName, null);
            try {
                $row->save();
            } catch(\Exception $e) {
                ModelException::throwException($e->getMessage());
            }
        }
    }

    public function get($id) {

        $item = $this->getById($id);

        if(!$item) {
            ModelException::throwException("Entity with id = $id doesn't exist!");
        }

        return $item;

    }

    public function add($data) {

        if(isset($data['id'])) {
            unset($data['id']);
        }

        $id = $this->setValues($data)->validateValues()->save();

        return $id;

    }

    public function edit($id, $data) {


        $item = $this->getById($id);

        if(!$item) {
            ModelException::throwException("Entity with id = $id doesn't exist!");
        }

        $data['id'] = $id;

        return $this->setValues($data, true)->validateValues()->save();

    }

}