<?php

namespace ibibicloud\cloudflare;

use ibibicloud\facade\HttpClient;

class D1
{
    private $config;
    
    // 链式变量
    private $dbId;
    private $table;
    private $where = [];
    private $order = '';
    private $limit = '';
    private $field = '*';
    private $lastSql = '';

    public function __construct()
    {
        $this->config = config('cloudflare');
        $this->dbId = $this->config['D1']['dbId1'];
    }

    // 指定数据库ID
    public function database($dbId)
    {
        $this->dbId = $dbId;
        return $this;
    }

    // 指定表名
    public function table($table)
    {
        $this->table = $table;
        return $this;
    }

    // 指定字段
    public function field($field)
    {
        $this->field = $field;
        return $this;
    }

    // WHERE 条件解析 (支持 TP5.1 风格)
    public function where($field, $op = null, $val = null)
    {
        // 重点：支持二维数组条件 [ ['name','=','a'], ['status','=',1] ]
        if (is_array($field) && is_array(reset($field))) {
            foreach ($field as $condition) {
                $this->where[] = $condition;
            }
            return $this;
        }

        if (is_array($field)) {
            foreach ($field as $k => $v) {
                if (is_array($v)) {
                    // 支持 where(['id' => ['>', 10]])
                    $this->where[] = [$k, $v[0], $v[1]];
                } else {
                    // 支持 where(['status' => 1])
                    $this->where[] = [$k, '=', $v];
                }
            }
        } else {
            if ($val === null) {
                // 支持 where('id', 1)
                $val = $op;
                $op = '=';
            }
            // 支持 where('id', '>', 1)
            $this->where[] = [$field, $op, $val];
        }
        return $this;
    }

    // 排序
    public function order($order)
    {
        $this->order = $order;
        return $this;
    }

    // 限制条数
    public function limit($limit, $offset = 0)
    {
        $limit = (int)$limit;
        $offset = (int)$offset;
        $this->limit = $offset > 0 ? "{$offset}, {$limit}" : "{$limit}";
        return $this;
    }

    // 查询多条
    public function select()
    {
        $sql = $this->buildSelectSql();
        $params = $this->getWhereParams();
        $res = $this->query($sql, $params);
        return $res['result'] ? $this->format($res['result'][0]['results']) : [];
    }

    // 查询单条
    public function find()
    {
        $res = $this->limit(1)->select();
        return $res[0] ?? null;
    }

    // 获取单个字段的值
    public function value($field)
    {
        $res = $this->field($field)->find();
        return $res['rows'] ? $res['rows'][0][0] : null;
    }

    // 获取某一列的值
    public function column($field, $key = null)
    {
        $fieldStr = $key ? "{$key},{$field}" : $field;
        $res = $this->field($fieldStr)->select();
        if ( $key === null ) {
            return $res['rows'] ? array_column($res['rows'], 0) : [];
        } else {
            // return $res['rows'];
            return $res['rows'] ? array_column($res['rows'], 1, 0) : [];
        }
    }

    // 插入数据
    public function insert($data)
    {
        // 判断是否为批量插入
        $isBatch = is_array(reset($data));

        if ( !$isBatch ) {
            // 单条插入
            $keys = implode(',', array_keys($data));
            $holder = rtrim(str_repeat('?,', count($data)), ',');
            $sql = "INSERT INTO {$this->table} ({$keys}) VALUES ({$holder})";
            $params = array_values($data);
        } else {
            // 批量插入
            $first = reset($data);
            $keys = implode(',', array_keys($first));
            
            // 拼接多组 (?,?,...)
            $holder = '(' . rtrim(str_repeat('?,', count($first)), ',') . ')';
            $holders = implode(',', array_fill(0, count($data), $holder));
            
            $sql = "INSERT INTO {$this->table} ({$keys}) VALUES {$holders}";
            
            // 扁平化参数
            $params = [];
            foreach ( $data as $item ) {
                $params = array_merge($params, array_values($item));
            }
        }

        $res = $this->query($sql, $params);
        return $res['success'] ? $res['result'][0]['meta']['changes'] : 0;
    }

    // 更新数据
    public function update($data)
    {
        if ( empty($this->where) ) {
            throw new \Exception('Update must have where condition');
        }
        $sets = [];
        $params = [];
        foreach ( $data as $k => $v ) {
            $sets[] = "{$k}=?";
            $params[] = $v;
        }
        $sql = "UPDATE {$this->table} SET " . implode(',', $sets) . $this->buildWhereSql();
        $params = array_merge($params, $this->getWhereParams());
        $res = $this->query($sql, $params);
        return $res['success'] ? $res['result'][0]['meta']['changes'] : 0;
    }

    // 删除数据
    public function delete()
    {
        if ( empty($this->where) ) {
            throw new \Exception('Delete must have where condition');
        }
        $sql = "DELETE FROM {$this->table}" . $this->buildWhereSql();
        $res = $this->query($sql, $this->getWhereParams());
        return $res['success'] ? $res['result'][0]['meta']['changes'] : 0;
    }

    // 获取最后一条 SQL
    public function getLastSql()
    {
        return $this->lastSql;
    }

    // ================= 内部方法 =================
    private function reset()
    {
        $this->where = [];
        $this->order = '';
        $this->limit = '';
        $this->field = '*';
    }

    // 统一格式化数据：columns + rows => 键值对数组
    private function format($data)
    {
        $cols = $data['columns'] ?? [];
        $rows = $data['rows'] ?? [];
        $result = [];

        foreach ( $rows as $row ) {
            $result[] = array_combine($cols, $row);
        }

        return $result;
    }

    private function buildSelectSql()
    {
        $sql = "SELECT {$this->field} FROM {$this->table}";
        $sql .= $this->buildWhereSql();
        if ( $this->order ) $sql .= " ORDER BY {$this->order}";
        if ( $this->limit ) $sql .= " LIMIT {$this->limit}";
        return $sql;
    }

    private function buildWhereSql()
    {
        if ( empty($this->where) ) return '';
        $arr = [];
        foreach ( $this->where as $w ) {
            $arr[] = "{$w[0]} {$w[1]} ?";
        }
        return " WHERE " . implode(' AND ', $arr);
    }

    private function getWhereParams()
    {
        $p = [];
        foreach ( $this->where as $w ) $p[] = $w[2];
        return $p;
    }

    private function query($sql, $params = [])
    {
        $this->lastSql = $sql; // 记录日志
        
        $api = 'https://api.cloudflare.com/client/v4/accounts/' . $this->config['D1']['accountId'] . '/d1/database/' . $this->dbId . '/raw';
        $header = [
            'Authorization: ' . $this->config['D1']['token'],
            'Content-Type: application/json',
        ];

        $res = HttpClient::post($api, json_encode(['sql' => $sql, 'params' => $params]), $header);
        
        // 关键点：操作完成后立即重置
        $this->reset();

        if ( $res['statusCode'] != 200 ) {
            return ['success' => false, 'error' => 'HTTP_ERROR_' . $res['statusCode']];
        }

        return json_decode($res['body'], true);
    }
}