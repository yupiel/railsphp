<?php
namespace Rails\ActiveRecord\Schema;

use Closure;
use Laminas\Db;
use Laminas\Db\Sql\Ddl;
use Rails;
use Rails\ActiveRecord\ActiveRecord;

class Schema
{
    /**
     * @var \Rails\ActiveRecord\Connection
     */
    protected $connection;

    protected $adapter;

    protected $sql;

    public function __construct(\Rails\ActiveRecord\Connection $connection = null)
    {
        if (!$connection) {
            $connection = ActiveRecord::connection(Rails::env());
        }
        $this->connection = $connection;
        $this->buildZfAdapter();
    }

    public function sql()
    {
        return $this->sql;
    }

    public function adapter()
    {
        return $this->sql;
    }

    public function connection()
    {
        return $this->connection;
    }

    public function createTable($tableName, $options = [], Closure $block = null)
    {
        if ($options && $options instanceof Closure) {
            $block = $options;
            $options = [];
        }

        $createDdl = new Ddl\CreateTable($tableName);
        $td = new TableDefinition($this, $createDdl);

        if (!empty($options['force'])) {
            $this->queryAdapter(
                new Ddl\DropTable($tableName)
            );
        }

        if (!isset($options['id']) || !empty($options['id'])) {
            $pk = isset($options['primary_key']) ? $options['primary_key'] : 'id';
            $td->primaryKey($pk);
        }

        if ($block) {
            $block($td);
        }

        $this->queryAdapter($createDdl);
    }

    public function addColumn($tableName, $columnName, $type, array $options = [])
    {
        $column = $this->getColumnDefinition($columnName, $type, $options);

        $ddl = new Ddl\AlterTable($tableName);
        $ddl->addColumn($column);

        $this->queryAdapter($ddl);
    }

    public function changeColumn($tableName, $columnName, $type, array $options = [])
    {
        $column = $this->getColumnDefinition($columnName, $type, $options);

        $ddl = new Ddl\AlterTable($tableName);
        $ddl->changeColumn($columnName, $column);

        $this->queryAdapter($ddl);
    }

    public function addIndex($tableName, $columnName, array $options = [])
    {
        if (!isset($options['name'])) {
            $options['name'] = '';
        }

        if (!empty($options['unique'])) {
            $index = new Ddl\Constraint\UniqueKey($columnName, $options['name']);
        } elseif (!empty($options['primary_key'])) {
            $index = new Constraint\PrimaryKey($columnName);
        } else {
            $index = new Constraint\IndexKey($columnName);
        }

        $ddl = new Ddl\AlterTable($tableName);
        $ddl->addConstraint($index);

        $this->queryAdapter($ddl);
    }

    public function getColumnDefinition($name, $type, $options)
    {
        switch ($type) {
            case 'string':
            case 'varchar':
                # Default options.
                $options = array_merge([
                    'limit' => 255
                ], $options);

                $column = new Ddl\Column\Varchar($name, $options['limit']);
                break;

            case 'char':
                # Default options.
                $options = array_merge([
                    'limit' => 255
                ], $options);

                $column = new Ddl\Column\Char($name, $options['limit']);
                break;

            case 'integer':
                $column = new Ddl\Column\Integer($name);
                break;

            case 'datetime':
                $column = new Column\DateTime($name);
                break;

            case 'text':
                $column = new Column\Text($name);
                break;

            // case 'primary_key':

            // break;

            default:
                throw new Exception\RuntimeException(
                    sprintf("Unknown column type '%s'", $type)
                );
        }

        # Allow/disallow null, TRUE by default.
        $column->setNullable(!isset($options['null']) || !empty($options['null']));

        # Set default value.
        if (isset($options['default'])) {
            $column->setDefault($options['default']);
        }

        return $column;
    }

    public function tableExists($tableName)
    {
        return $this->connection->tableExists($tableName);
    }

    public function execute()
    {
        return call_user_func_array([$this->connection, 'executeSql'], func_get_args());
    }

    protected function buildZfAdapter()
    {
        $pdoCon = new Db\Adapter\Driver\Pdo\Connection();
        $pdoCon->setResource($this->connection->resource());

        $pdo = new Db\Adapter\Driver\Pdo\Pdo($pdoCon);
        $this->adapter = new Db\Adapter\Adapter($pdo);

        $this->sql = new Db\Sql\Sql(
            $this->adapter
        );
    }

    protected function queryAdapter($ddl)
    {
        $adapter = $this->adapter;
        $adapter->query(
            $this->sql->getSqlStringForSqlObject($ddl),
                $adapter::QUERY_MODE_EXECUTE
        );
    }
}