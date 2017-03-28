<?php
namespace Ekhaled\MysqlSchema;

use \PDO;

class Parser{

    private $config = array();
    private $adapter = 'mysql';
    private $DB;

    public function __construct($config = array())
    {
        $this->setConnection($config);
    }

    public function setConnection($config = array())
    {
        //declare default options
        $defaults = array(
            "host"      => '',
            "username"  => '',
            "password"  => '',
            "dbname"  => ''
        );

        foreach ($config as $key => $value) {
            //overwrite the default value of config item if it exists
            if (array_key_exists($key, $defaults)) {
                $defaults[$key] = $value;
            }
        }

        //store the config back into the class property
        $this->config = $defaults;

        $dsn = $this->adapter . ':host=' . $this->config['host'] . ';dbname=' . $this->config['dbname'];
        $this->DB = new PDO(
            $dsn,
            $this->config['username'],
            $this->config['password']
        );
    }

    public function getSchema($raw = false)
    {
        $TABLES = [];
        $tables = $this->DB->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);

        foreach($tables as $tableRow){

            $table = $tableRow[0];
            $tableType = $tableRow[1];

            $TABLES[$table] = [
                'name' => $table,
                'type' => $tableType,
                'columns' => [],
                'foreignKeys' => [],
                'primaryKey' => null,
                'relations' => []
            ];

            //columns
            $columns = $this->DB->query('SHOW FULL COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            $colarr = [];
            foreach($columns as $col){
                $isPrimaryKey = strpos($col['Key'],'PRI')!==false;
                $colarr[$col['Field']] = [
                    'name' => $col['Field'],
                    'type' => $col['Type'],
                    'nullable' => $col['Null']==='YES',
                    'default' => $col['Default'],
                    'isPrimaryKey' => $isPrimaryKey,
                    'isForeignKey' => false,
                    'relation' => [],
                    'autoIncrement' => strpos(strtolower($col['Extra']),'auto_increment')!==false
                ];
                if($isPrimaryKey){
                    if($TABLES[$table]['primaryKey'] === null)
                        $TABLES[$table]['primaryKey'] = $col['Field'];
                    elseif(is_string($TABLES[$table]['primaryKey']))
                        $TABLES[$table]['primaryKey'] = [$TABLES[$table]['primaryKey'], $col['Field']];
                    else
                        $TABLES[$table]['primaryKey'][] = $col['Field'];
                }
            }
            $TABLES[$table]['columns'] = $colarr;

            //constraints
            $constraints = $this->DB->query('SHOW CREATE TABLE `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN, 1);
            $create_table = $constraints[0];

            $matches=array();
            $regexp='/FOREIGN KEY\s+\(([^\)]+)\)\s+REFERENCES\s+([^\(^\s]+)\s*\(([^\)]+)\)/mi';
            preg_match_all($regexp, $create_table, $matches, PREG_SET_ORDER);
            foreach($matches as $match){
                $keys=array_map('trim',explode(',',str_replace(array('`','"'),'',$match[1])));
                $fks=array_map('trim',explode(',',str_replace(array('`','"'),'',$match[3])));
                foreach($keys as $k => $name){
                    $TABLES[$table]['foreignKeys'][$name]=array(str_replace(array('`','"'),'',$match[2]),$fks[$k]);
                    if(isset($TABLES[$table]['columns'][$name]))
                         $TABLES[$table]['columns'][$name]['isForeignKey'] = true;
                }
            }

        }

        if (!$raw) {
            $this->normalize($TABLES);
        }

        return $TABLES;
    }

    private function normalize(&$schema)
    {
        foreach($schema as &$table){

            if(count($table['foreignKeys']) > 0){
                foreach($table['foreignKeys'] as $k => $v){
                    list($targetTable, $targetColumn) = $v;
                    $table['columns'][$k]['relation'] = [
                        'type' => 'belongs-to-one',
                        'table' => $targetTable
                    ];
                    //update other side of relation
                    $schema[$targetTable]['relations'][$table['name']] = [
                        'type' => 'has-many',
                        'table' => $table['name'],
                        'column' => $k
                    ];
                }
            }
        }

        //working out many-to-many connector tables
        foreach($schema as &$table){
            $isRelationTable = true;
            if(
                count($table['foreignKeys']) > 0 && //if it has foreign keys
                count($table['foreignKeys']) == 2 && // only 2 foreign keys
                is_array($table['primaryKey']) && // has more than one primary key
                count($table['primaryKey']) >= 2 // at least 2 primary keys
            ){
                //if all it's foreign keys are also primary keys
                foreach($table['foreignKeys'] as $k => $fk){
                    if(!in_array($k, $table['primaryKey'])){
                        $isRelationTable = false;
                        break;
                    }
                }
            }else{
                $isRelationTable = false;
            }

            if($isRelationTable){
                $i = 0;
                $referencedTables = [];
                $connections = [];
                foreach($table['foreignKeys'] as $k => $fk){
                    $referencedTables[$i] = $fk[0];
                    $connections[$i] = $k;
                    $i++;
                }

                unset($schema[$referencedTables[0]]['relations'][$table['name']]);
                unset($schema[$referencedTables[1]]['relations'][$table['name']]);

                $schema[$referencedTables[1]]['relations'][$referencedTables[0]] = [
                    'type' => 'has-many',
                    'table' => $referencedTables[0],
                    'column' => $connections[1],
                    'via' => $table['name'],
                    'selfColumn' => $connections[0],
                ];

                $schema[$referencedTables[0]]['relations'][$referencedTables[1]] = [
                    'type' => 'has-many',
                    'table' => $referencedTables[1],
                    'column' => $connections[0],
                    'via' => $table['name'],
                    'selfColumn' => $connections[1],
                ];

                unset($schema[$table['name']]);

            }
        }
    }

}