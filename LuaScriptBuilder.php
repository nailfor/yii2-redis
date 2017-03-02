<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\redis;

use yii\base\InvalidParamException;
use yii\base\NotSupportedException;
use yii\db\Exception;
use yii\db\Expression;
use yii\helpers\ArrayHelper;

/**
 * LuaScriptBuilder builds lua scripts used for retrieving data from redis.
 *
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
class LuaScriptBuilder extends \yii\base\Object
{
    /**
     * Builds a Lua script for finding a list of records
     * @param ActiveQuery $query the query used to build the script
     * @return string
     */
    public function buildAll($query)
    {
        // TODO add support for orderBy
        return $this->build($query, "n=n+1 local rec=redis.call('HGETALL',pkey) rec[#rec+1]='_PrimaryKey' rec[#rec+1]=pkey pks[n]=rec", 'pks');
    }

    /**
     * Builds a Lua script for finding one record
     * @param ActiveQuery $query the query used to build the script
     * @return string
     */
    public function buildOne($query)
    {
        // TODO add support for orderBy
        return $this->build($query, "do local rec=redis.call('HGETALL',pkey) rec[#rec+1]='_PrimaryKey' rec[#rec+1]=pkey return rec end", 'pks');
    }

    /**
     * Builds a Lua script for finding a column
     * @param ActiveQuery $query the query used to build the script
     * @param string $column name of the column
     * @return string
     */
    public function buildColumn($query, $column)
    {
        // TODO add support for orderBy and indexBy
        return $this->build($query, "n=n+1 pks[n]=redis.call('HGET',pkey," . $this->quoteValue($column) . ")", 'pks');
    }

    /**
     * Builds a Lua script for getting count of records
     * @param ActiveQuery $query the query used to build the script
     * @return string
     */
    public function buildCount($query)
    {
        return $this->build($query, 'n=n+1', 'n');
    }

    /**
     * Builds a Lua script for finding the sum of a column
     * @param ActiveQuery $query the query used to build the script
     * @param string $column name of the column
     * @return string
     */
    public function buildSum($query, $column)
    {
        return $this->build($query, "n=n+redis.call('HGET',pkey," . $this->quoteValue($column) . ")", 'n');
    }

    /**
     * Builds a Lua script for finding the average of a column
     * @param ActiveQuery $query the query used to build the script
     * @param string $column name of the column
     * @return string
     */
    public function buildAverage($query, $column)
    {
        return $this->build($query, "n=n+1 if v==nil then v=0 end v=v+redis.call('HGET',pkey," . $this->quoteValue($column) . ")", 'v/n');
    }

    /**
     * Builds a Lua script for finding the min value of a column
     * @param ActiveQuery $query the query used to build the script
     * @param string $column name of the column
     * @return string
     */
    public function buildMin($query, $column)
    {
        return $this->build($query, "n=redis.call('HGET',pkey," . $this->quoteValue($column) . ") if v==nil or n<v then v=n end", 'v');
    }

    /**
     * Builds a Lua script for finding the max value of a column
     * @param ActiveQuery $query the query used to build the script
     * @param string $column name of the column
     * @return string
     */
    public function buildMax($query, $column)
    {
        return $this->build($query, "n=redis.call('HGET',pkey," . $this->quoteValue($column) . ") if v==nil or n>v then v=n end", 'v');
    }
    
    protected function geoRadius($query, $res, &$suffix) {
        $lat = ArrayHelper::getValue($query->radius, 'lat');
        $lon = ArrayHelper::getValue($query->radius, 'lon');
        $rad = explode(' ', ArrayHelper::getValue($query->radius, 'radius'));
        $radius = ArrayHelper::getValue($rad, 0);
        $m = ArrayHelper::getValue($rad, 1);
        if ($lat && $lon && $radius && $m) {
            $modelClass = $query->modelClass;
            $suffix   = $modelClass::TYPE_GEO;
            $lat    = $this->quoteValue($lat);
            $lon    = $this->quoteValue($lon);
            $radius = $this->quoteValue($radius);
            $m      = $this->quoteValue($m);
            return "redis.call('GEORADIUS',_key,$lon,$lat,$radius,$m)";
        }
        return $res;
    }

    protected function expire($query, $res, &$suffix) {
        $modelClass = $query->modelClass;
        if(!$query->withExpiring && !$query->radius) {
            $suffix = $modelClass::TYPE_EXPIRE.':';
        }
        $expire = "*";
        if ($query->radius) {
            $expire = ':*';
        }
        $pool = $this->quoteValue($expire);
        $expression = <<<EOF
for i,_key in ipairs(keys) do 
    local members=$res
    for k,pk in ipairs(members) do
        allpks[pk]=pk
    end
end
EOF;
        if ($query->radius && $query->withExpiring) {
            $expression = "keys[#keys+1]=_key\n".$expression;
        }
        elseif ($query->withExpiring) {
            $expression = "allpks=keys\nkey=''";
        }
        
        return <<<EOF
{}
local keys=redis.call('KEYS', key..suffix..$pool)
$expression
EOF;
    }


    protected function allpks($query) {
        $modelClass = $query->modelClass;
        $suffix = $modelClass::TYPE_KEY.':';
        $key    = $this->quoteValue($modelClass::keyPrefix());
        $res    = "redis.call('SMEMBERS', _key)";
        $_key   = 'local _key=key';

        if ($query->radius && is_array($query->radius)) {
            $_key   = 'local _key=key..suffix';
            $res = $this->geoRadius($query, $res, $suffix);
        }
        if ($query->expire || $query->withExpiring) {
            $res = $this->expire($query, $res, $suffix);
        }
        $suffix = $this->quoteValue(":$suffix");
        return "local key=$key\nlocal suffix=$suffix\n$_key\nlocal allpks=$res";
    }

    /**
     * @param ActiveQuery $query the query used to build the script
     * @param string $buildResult the lua script for building the result
     * @param string $return the lua variable that should be returned
     * @throws NotSupportedException when query contains unsupported order by condition
     * @return string
     */
    private function build($query, $buildResult, $return)
    {
        if (!empty($query->orderBy)) {
            throw new NotSupportedException('orderBy is currently not supported by redis ActiveRecord.');
        }

        $columns = [];
        if ($query->where !== null) {
            $condition = $this->buildCondition($query->where, $columns);
        } else {
            $condition = 'true';
        }

        $start = $query->offset === null ? 0 : $query->offset;
        $limitCondition = 'i>' . $start . ($query->limit === null ? '' : ' and i<=' . ($start + $query->limit));

        /* @var $modelClass ActiveRecord */
        $modelClass = $query->modelClass;
        $tk         = $this->quoteValue(':'.$modelClass::TYPE_KEY.':');
        $loadColumnValues = '';
        foreach ($columns as $column => $alias) {
            $loadColumnValues .= "local $alias=redis.call('HGET',pkey, '$column')\n";
        }
        $allpks = $this->allpks($query);

        return <<<EOF
$allpks
local pks={}
local n=0
local v=nil
local i=0
for k,pk in pairs(allpks) do
    local pkey=key..$tk..pk 
    $loadColumnValues
    if $condition then
        i=i+1
        if $limitCondition then
            $buildResult
        end
    end
end
return $return
EOF;
    }

    /**
     * Adds a column to the list of columns to retrieve and creates an alias
     * @param string $column the column name to add
     * @param array $columns list of columns given by reference
     * @return string the alias generated for the column name
     */
    private function addColumn($column, &$columns)
    {
        if (isset($columns[$column])) {
            return $columns[$column];
        }
        $name = 'c' . preg_replace("/[^A-z]+/", "", $column) . count($columns);

        return $columns[$column] = $name;
    }

    /**
     * Quotes a string value for use in a query.
     * Note that if the parameter is not a string or int, it will be returned without change.
     * @param string $str string to be quoted
     * @return string the properly quoted string
     */
    private function quoteValue($str)
    {
        if (!is_string($str) && !is_int($str)) {
            return $str;
        }

        return "'" . addcslashes($str, "\000\n\r\\\032\047") . "'";
    }

    /**
     * Parses the condition specification and generates the corresponding Lua expression.
     * @param string|array $condition the condition specification. Please refer to [[ActiveQuery::where()]]
     * on how to specify a condition.
     * @param array $columns the list of columns and aliases to be used
     * @return string the generated SQL expression
     * @throws \yii\db\Exception if the condition is in bad format
     * @throws \yii\base\NotSupportedException if the condition is not an array
     */
    public function buildCondition($condition, &$columns)
    {
        static $builders = [
            'not' => 'buildNotCondition',
            'and' => 'buildAndCondition',
            'or' => 'buildAndCondition',
            'between' => 'buildBetweenCondition',
            'not between' => 'buildBetweenCondition',
            'in' => 'buildInCondition',
            'not in' => 'buildInCondition',
            'like' => 'buildLikeCondition',
            'not like' => 'buildLikeCondition',
            'or like' => 'buildLikeCondition',
            'or not like' => 'buildLikeCondition',
        ];

        if (!is_array($condition)) {
            throw new NotSupportedException('Where condition must be an array in redis ActiveRecord.');
        }
        if (isset($condition[0])) { // operator format: operator, operand 1, operand 2, ...
            $operator = strtolower($condition[0]);
            if (isset($builders[$operator])) {
                $method = $builders[$operator];
                array_shift($condition);

                return $this->$method($operator, $condition, $columns);
            } else {
                throw new Exception('Found unknown operator in query: ' . $operator);
            }
        } else { // hash format: 'column1' => 'value1', 'column2' => 'value2', ...

            return $this->buildHashCondition($condition, $columns);
        }
    }

    private function buildHashCondition($condition, &$columns)
    {
        $parts = [];
        foreach ($condition as $column => $value) {
            if (is_array($value)) { // IN condition
                $parts[] = $this->buildInCondition('in', [$column, $value], $columns);
            } else {
                if (is_bool($value)) {
                    $value = (int) $value;
                }
                if ($value === null) {
                    $parts[] = "redis.call('HEXISTS',pkey, ".$this->quoteValue($column).")==0";
                } elseif ($value instanceof Expression) {
                    $column = $this->addColumn($column, $columns);
                    $parts[] = "$column==" . $value->expression;
                } else {
                    $column = $this->addColumn($column, $columns);
                    $value = $this->quoteValue($value);
                    $parts[] = "$column==$value";
                }
            }
        }

        return count($parts) === 1 ? $parts[0] : '(' . implode(') and (', $parts) . ')';
    }

    private function buildNotCondition($operator, $operands, &$params)
    {
        if (count($operands) != 1) {
            throw new InvalidParamException("Operator '$operator' requires exactly one operand.");
        }

        $operand = reset($operands);
        if (is_array($operand)) {
            $operand = $this->buildCondition($operand, $params);
        }

        return "(not ($operand))";
    }

    private function buildAndCondition($operator, $operands, &$columns)
    {
        $parts = [];
        foreach ($operands as $operand) {
            if (is_array($operand)) {
                $operand = $this->buildCondition($operand, $columns);
            }
            if ($operand !== '') {
                $parts[] = $operand;
            }
        }
        if (!empty($parts)) {
            return '(' . implode(") $operator (", $parts) . ')';
        } else {
            return '';
        }
    }

    private function buildBetweenCondition($operator, $operands, &$columns)
    {
        if (!isset($operands[0], $operands[1], $operands[2])) {
            throw new Exception("Operator '$operator' requires three operands.");
        }

        list($column, $value1, $value2) = $operands;

        $value1 = $this->quoteValue($value1);
        $value2 = $this->quoteValue($value2);
        $column = $this->addColumn($column, $columns);

        return "$column >= $value1 and $column <= $value2";
    }

    private function buildInCondition($operator, $operands, &$columns)
    {
        if (!isset($operands[0], $operands[1])) {
            throw new Exception("Operator '$operator' requires two operands.");
        }

        list($column, $values) = $operands;

        $values = (array) $values;

        if (empty($values) || $column === []) {
            return $operator === 'in' ? 'false' : 'true';
        }

        if (count($column) > 1) {
            return $this->buildCompositeInCondition($operator, $column, $values, $columns);
        } elseif (is_array($column)) {
            $column = reset($column);
        }
        $columnAlias = $this->addColumn($column, $columns);
        $parts = [];
        foreach ($values as $value) {
            if (is_array($value)) {
                $value = isset($value[$column]) ? $value[$column] : null;
            }
            if ($value === null) {
                $parts[] = "redis.call('HEXISTS',pkey, ".$this->quoteValue($column).")==0";
            } elseif ($value instanceof Expression) {
                $parts[] = "$columnAlias==" . $value->expression;
            } else {
                $value = $this->quoteValue($value);
                $parts[] = "$columnAlias==$value";
            }
        }
        $operator = $operator === 'in' ? '' : 'not ';

        return "$operator(" . implode(' or ', $parts) . ')';
    }

    protected function buildCompositeInCondition($operator, $inColumns, $values, &$columns)
    {
        $vss = [];
        foreach ($values as $value) {
            $vs = [];
            foreach ($inColumns as $column) {
                if (isset($value[$column])) {
                    $columnAlias = $this->addColumn($column, $columns);
                    $vs[] = "$columnAlias==" . $this->quoteValue($value[$column]);
                } else {
                    $vs[] = "redis.call('HEXISTS',pkey, ".$this->quoteValue($column).")==0";
                }
            }
            $vss[] = '(' . implode(' and ', $vs) . ')';
        }
        $operator = $operator === 'in' ? '' : 'not ';

        return "$operator(" . implode(' or ', $vss) . ')';
    }

    private function buildLikeCondition($operator, $operands, &$columns)
    {
        throw new NotSupportedException('LIKE conditions are not suppoerted by redis ActiveRecord.');
    }
}
