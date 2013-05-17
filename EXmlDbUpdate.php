<?php
/**
 * User: Alexok
 * Date: 09.02.12
 * Time: 16:17 
 */
class EXmlDbUpdate
{
    protected $newSchema;
    protected $query = array();
    protected $after_query = array();

    public function __construct()
    {
        $xmlData = simplexml_load_file(Yii::getPathOfAlias('application.data').DS.'db.xml');
        $this->newSchema = $this->parseXmlSchema($xmlData);
    }

    public function update($execute = false)
    {
        foreach($this->newSchema as $tbl_name=>$tbl_fields) {
            $this->checkTable($tbl_name, $tbl_fields);
        }

        if ($execute) {
            $this->runQueryList();
            $this->runQueryList($this->after_query);
        }

        return $this;
    }

    public function queryList()
    {
        return array(
            'general'=>$this->query,
            'after'=>$this->after_query
        );
    }

    private function parseXmlSchema($xmlData)
    {
        $schema = array();

        foreach($xmlData->children() as $table) {
            $table_name          = (string) $table->attributes()->name;
            $schema[$table_name] = $this->parseXmlTable($table);
        }

        return $schema;
    }

    private function parseXmlTable($table)
    {
        $tableSchema = array(
            'fields'=>array(),
            'keys'=>array()
        );

        foreach($table->children() as $field) {
            if ($field->getName() == 'field') {
                $columnName = (string) $field->attributes()->name;
                $tableSchema['fields'][$columnName] = $this->parseXmlColumn($field); //$columnValue;
            } else {
                $columnName = (string) $field->attributes()->name;
                $tableSchema['keys'][$columnName] = $this->parseXmlKey($field);
            }
        }

        return $tableSchema;
    }

    /**
     * Parse table column
     * @param $column
     * @return array
     */
    private function parseXmlColumn($column)
    {
        $options = $this->addDefaultOptions();

        foreach($column->attributes() as $name=>$value) {
            $name  = (string) $name;
            $value = (string) $value;

            if ($name == 'name')
                continue;

            if (!empty($value)) {
                $options[$name] = $value;
            }
        }
        return $options;
    }

    private function parseXmlKey($column)
    {
        $options = array();

        foreach($column->attributes() as $name=>$value) {
            $name  = (string) $name;
            $value = (string) $value;

            if ($name == 'name')
                continue;

            if (!empty($value)) {
                $options[$name] = $value;
            }
        }
        return $options;
    }


    /**
     * Add default option for column
     * @param $options
     * @return array
     */
    private function addDefaultOptions($options = array())
    {
        $defaults = array(
            'type'=>'varchar(255)',
            'null'=>'not null'
        );

        return $defaults;
    }

    /**
     * Check and update table in Database
     *
     * @param string $table
     * @param array $fields
     */
    private function checkTable($table, $fields)
    {
        $dbSchema    = Yii::app()->db->schema;
        $tableSchema = $dbSchema->getTable($table);

        if (!$tableSchema) {
            $this->createTable($table, $fields['fields']);
            $this->createKeys($table, $fields['keys']);
        } else {
            $this->updateTable($table, $fields['fields']);
            $this->updateKeys($table, $fields['keys']);
        }
    }

    /**
     * Run all queries
     * @param array $queryList
     */
    private function runQueryList($queryList = null)
    {
        if ($queryList === null)
            $queryList = $this->query;

        if (!$queryList)
            return;

        foreach($queryList as $query) {
            Yii::app()->db->createCommand($query)->execute();
        }
    }

    /**
     * Create a table to DataBase
     * @param $name
     * @param $fields
     */
    private function createTable($name, $fields)
    {
        $this->query[] = Yii::app()->db->schema->createTable($name, $this->convertTableFields($fields));
    }

    private function createKeys($name, $keys)
    {
        foreach($keys as $key=>$options) {
            $this->query[] = Yii::app()->db->schema->createIndex($key, $name, $key);
        }
    }

    /**
     * Update a fields in exists table
     * @param string $name
     * @param array $fields
     */
    private function updateTable($name, $fields)
    {
        foreach($fields as $field=>$options) {
            $this->updateField($name, $field, $options);
        }

        $colums = Yii::app()->db->schema->getTable($name)->columnNames;
        if (count($fields) != count($colums)) {
            $this->removeOldFields($name, $fields);
        }
    }

    private function updateKeys($tbl_name, $keys)
    {
        $isset = $this->getTableKeys($tbl_name);

        foreach($keys as $key=>$options) {
            if (!in_array($key, $isset)) {
                $this->query[] = Yii::app()->db->schema->createIndex($key, $tbl_name, $key);
            }
        }

        foreach($isset as $inx) {
            if (!isset($keys[$inx])) {
                $this->query[] = Yii::app()->db->schema->dropIndex($inx, $tbl_name);
            }
        }
    }

    /**
     * Update field in table
     * @param string $tbl_name
     * @param string $name
     * @param array $options
     */
    private function updateField($tbl_name, $name, $options)
    {
        $table = Yii::app()->db->schema->getTable($tbl_name);
        $field = $table->getColumn($name);

        $query = false;

        if (!$field) {
            $query = Yii::app()->db->schema->addColumn($tbl_name, $name, implode(' ', $options));
        } elseif($field->isPrimaryKey) {
            return;
        } elseif($this->hasChangedColumn($field, $options)) {
            $query = Yii::app()->db->schema->alterColumn($tbl_name, $name, implode(' ', $options));
        }

        if ($query) {
            $this->query[] = $query;
        }
    }

    private function removeOldFields($tbl_name, $new_columns)
    {
        $old_columns = Yii::app()->db->schema->getTable($tbl_name)->columnNames;

        foreach($old_columns as $c) {
            if (!isset($new_columns[$c])) {
                $this->after_query[] = Yii::app()->db->schema->dropColumn($tbl_name, $c);
            }
        }
    }

    private function hasChangedColumn($columnSchema, $options)
    {
        $types    = Yii::app()->db->schema->columnTypes;
        $shotType = array_search($columnSchema->dbType, $types);

        if ($options['type'] == $shotType) {
            return false;
        }

        if ($columnSchema->dbType == $options['type']) {
            return false;
        }

        return true;
    }

    private function convertTableFields($fields)
    {
        $result = array();

        foreach($fields as $n=>$v) {
            $result[$n] = implode(' ', $v);
        }

        return $result;
    }

    private function getTableKeys($tbl_name)
    {
        $db = Yii::app()->db;
        $indexes = $db->createCommand('SHOW KEYS FROM '. $db->quoteTableName($tbl_name))->queryAll();
        $result = array();

        foreach($indexes as $index) {
            if ($index['Key_name']!=='PRIMARY')
                $result[] = $index['Column_name'];
        }
        return $result;
    }
}
