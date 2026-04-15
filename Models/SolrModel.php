<?php

namespace RestModel\Models;

use \RestModel\Exceptions\ModelException;
use \SolrClient;
use \SolrQuery;

class SolrModel {

    const FIELD_STRING = 0;
    const FIELD_DATETIME = 1;
    const FIELD_BOOLEAN = 2;

    protected $validators = [];
    protected $client;
    protected $logger;
    protected $totalResultSetCount = 0;
    protected $fields = [];
    protected $query;


    public static function sanitizeSolrFieldValue($fieldName, $fieldValue) {

        $fieldType = self::getFieldType($fieldName);

        switch($fieldType) {
            case self::FIELD_BOOLEAN:
                // Logical fields should start by 'is_' (is_logo_on)
                $fieldValue = $fieldValue ? 'true' : 'false';
                break;

            case self::FIELD_DATETIME:
                $time = strtotime($fieldValue);
                if($time !== false) {
                    $fieldValue = date('Y-m-d') . 'T' . date('H:i:s') . 'Z';
                } else {
                    ModelException::throwException('Wrong format of datetime value!');
                }
                break;

            default:
                $match = array('\\', '+', '-', '&', '|', '!', '(', ')', '{', '}', '[', ']', '^', '~', '*', '?', ':', '"', ';', ' ');
                $replace = array('\\\\', '\\+', '\\-', '\\&', '\\|', '\\!', '\\(', '\\)', '\\{', '\\}', '\\[', '\\]', '\\^', '\\~', '\\*', '\\?', '\\:', '\\"', '\\;', '\\ ');
                $fieldValue = str_replace($match, $replace, $fieldValue);

        }

        return $fieldValue;
    }

    public static function convertFilterStrValue($string) {

        if(!$string || !is_string($string)) {
            return $string;
        }

        $firstChar = mb_substr($string, 0, 1);
        $firstCharFixed = false;
        $lastCharFixed = false;

        if($firstChar !== false && $firstChar == '%') {
            $string = '*' . mb_substr($string, 1);
            $firstCharFixed = true;
        }

        $lastChar = mb_substr($string, -1, 1);

        if($lastChar !== false && $lastChar == '%') {
            $string = mb_substr($string, 0, -1) . '*';
            $lastCharFixed = true;
        }

        if($firstCharFixed === false && $lastCharFixed === false) {
            $string = '"' . $string . '"';
        }

        if(!empty($_GET['solrdebug'])) {
            error_log("Solr filter value: $string");
        }

        return $string;
    }

    protected static function getFieldType($fieldName) {

        if(substr($fieldName, -3) == '_on') {
            return self::FIELD_DATETIME;
        }

        if(substr($fieldName, 0, 3) == 'is_') {
            return self::FIELD_BOOLEAN;
        }

        return self::FIELD_STRING;

    }


    public function __construct($config, $logger) {

        $this->logger = $logger;
        $this->client = new SolrClient($config);

    }

    public function getQuery() {

        if(!$this->query) {
            $this->query = new SolrQuery();
        }

        return $this->query;
    }

    public function setFieldsValidators($validators) {
        $this->validators = $validators;
        return $this;
    }

    public function getFieldsValidators() {
        return $this->validators;
    }

    public function addFields($fields) {
        $this->fields = $fields;
        return $this;
    }

    public function getMany($params = null) {

        $query = $this->getQuery();

        foreach($this->fields as $f) {
            $query->addField($f);
        }

        $q = $params && $params->getQuery() ? self::sanitizeSolrFieldValue('q',trim($params->getQuery())) : false;
        $q = $q ? $q : "*:*";

        $query->setQuery($q);


        if($params && $params->getOffset()) {
            $query->setStart($params->getOffset());
        }

        if($params && $params->getLimit()) {
            $query->setRows($params->getLimit());
        }

        $this->applyFilter($params);

        $this->applyOrder( $params);


        try {
            $queryResponse = $this->client->query($query);
        } catch(\SolrClientException $e) {
            $this->logger->debug(print_r($e->getInternalInfo(), 1));
            throw $e;
        }

        if($queryResponse->success()) {
            //$response = $queryResponse->getResponse();
            $response = $queryResponse->getRawResponse();
            //error_log($response);
            $response = json_decode($response, true, 512, JSON_OBJECT_AS_ARRAY);
            $this->totalResultSetCount = (int)$response['response']['numFound'];
            $response = $response['response']['docs'];

        } else {
            $response = [];
        }

        return $response;
    }


    public function getTotalCount($params = null) {
        return $this->totalResultSetCount;
    }

    protected function applyNotToField($fieldName, $fieldValue) {

        $query = $this->getQuery();

        if (is_scalar($fieldValue)) {
            $fieldValue = $fieldValue ? [$fieldValue] : [];
        }

        foreach ($fieldValue as $val) {
            if(!is_scalar($val)) {
                continue;
            } else {
                $val = self::sanitizeSolrFieldValue($fieldName, $val);
            }

            $query->addFilterQuery(is_null($val) ? "$fieldName:[* TO *]" : "!$fieldName:$val");
        }

        return $query;

    }

    protected function applyFromToField($fieldName, $fieldValue) {

        $query = $this->getQuery();

        if(is_null($fieldValue) || !is_scalar($fieldValue)) {
            return $query;
        }

        $fieldValue = self::sanitizeSolrFieldValue($fieldName, $fieldValue);

        $query->addFilterQuery("$fieldName:[$fieldValue TO *]");

        return $query;

    }

    protected function applyToToField($fieldName, $fieldValue) {

        $query = $this->getQuery();

        if(is_null($fieldValue) || !is_scalar($fieldValue)) {
            return $query;
        }

        $fieldValue = self::sanitizeSolrFieldValue($fieldName, $fieldValue);

        $query->addFilterQuery("$fieldName:[* TO $fieldValue]");

        return $query;

    }

    protected function applyInToField($fieldName, $fieldValue) {

        $query = $this->getQuery();

        if(!is_array($fieldValue) || count($fieldValue) == 0) {
            return $query;
        }

        $params = [];

        foreach($fieldValue as $val) {
            if(is_scalar($val)) {
                $params[] = self::sanitizeSolrFieldValue($fieldName, $val);
            }
        }

        if($params) {
            $query->addFilterQuery("$fieldName:(" . implode(' OR ', $params) . ")");
        }

        return $query;
    }

    protected function applyNullToField($fieldName) {

        //http://stackoverflow.com/questions/4238609/how-to-query-solr-for-empty-fields
        $query = $this->getQuery();
        $query->addFilterQuery("-$fieldName:[* TO *]");

        return $query;
    }

    protected function applyValueToField($fieldName, $fieldValue) {

        $query = $this->getQuery();

        if(!is_scalar($fieldValue)) {
            return $query;
        }

        $fieldValue = self::sanitizeSolrFieldValue($fieldName, $fieldValue);
        $fieldValue = self::convertFilterStrValue($fieldValue);

        $query->addFilterQuery("$fieldName:$fieldValue");

        return $query;
    }


    protected function applyFilter($params = null) {

        $query = $this->getQuery();

        if (is_null($params)) {
            return $query;
        }

        $filters = $params->getFilter();
        $fields = $this->getFieldsValidators();

        foreach ($filters as $filter) {

            foreach (array_keys($fields) as $field) {

                if (!array_key_exists($field, $filter)) {
                    continue;
                }

                $fieldParams = $filter[$field];

                if (is_array($fieldParams)) {

                    if (isset($fieldParams['not'])) {
                        $this->applyNotToField($field, $fieldParams['not']);
                        unset($fieldParams['not']);
                    }

                    if (isset($fieldParams['from'])) {
                        $this->applyFromToField($field, $fieldParams['from']);
                        unset($fieldParams['from']);
                    }

                    if (isset($fieldParams['to'])) {
                        $this->applyToToField($field, $fieldParams['to']);
                        unset($fieldParams['to']);
                    }

                    $this->applyInToField($field, $fieldParams);

                } else {

                    if (is_null($fieldParams)) {
                        $this->applyNullToField($field);
                    } else {
                        $this->applyValueToField($field, $fieldParams);
                    }
                }
            }
        }

        if(!empty($_GET['solrdebug'])) {
            error_log("Solr URL:" . print_r($this->query->getFilterQueries(),1));
        }
        //error_log("Solr URL:" . print_r($this->query->getFilterQueries(),1));

        return $query;
    }

    protected function applyOrder($params = null) {

        $query = $this->getQuery();

        if(is_null($params)) {
            return $query;
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

            if($orderField === 'random' && $order[$orderField]) {
                $randStr = substr(preg_replace("/[^a-zA-Z0-9]/", "", (string)$order[$orderField]), 0, 16);
                $query->addSortField("{$orderField}_{$randStr}", \SolrQuery::ORDER_DESC);
            }

            if(!isset($fields[$orderField])) {
                continue;
            }

            if(strtolower($order[$orderField]) == 'asc') {
                $query->addSortField($orderField, \SolrQuery::ORDER_ASC);
            }

            if(strtolower($order[$orderField]) == 'desc') {
                $query->addSortField($orderField, \SolrQuery::ORDER_DESC);
            }
        }

        return $query;
    }


}