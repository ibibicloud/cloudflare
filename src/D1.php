<?php

declare(strict_types=1);

namespace ibibicloud\cloudflare;

use ibibicloud\facade\HttpClient;

class D1
{
    private array $config;
    
    // 链式查询属性
    private string $dbId;
    private string $table;
    private array $where = [];
    private string $order = '';
    private string $limit = '';
    private string $field = '*';
    private string $lastSql = '';

    public function __construct()
    {
        $this->config = config('cloudflare');
        $this->dbId = $this->config['D1']['dbId1'];
    }

    // 指定数据库 ID
    public function database(string $dbId): self
    {
        $this->dbId = $dbId;
        return $this;
    }

    // 指定数据表
    public function table(string $table): self
    {
        $this->table = $table;
        return $this;
    }

    // 指定查询字段
    public function field(string $field): self
    {
        $this->field = $field;
        return $this;
    }

    // WHERE 条件查询 (支持 TP5.1 风格)
    public function where($field, $operator = null, $value = null): self
    {
        // 二维数组批量条件 [ ['name', '=', 'duck'], ['status', '=', 1] ]
        if ( is_array($field) && is_array(reset($field)) ) {
            foreach ( $field as $condition ) {
                $this->where[] = $condition;
            }

            return $this;
        }

        // 一维数组条件 ['status' => 1]
        if ( is_array($field) ) {
            foreach ( $field as $k => $v ) {
                $this->where[] = [$k, '=', $v];
            }

            return $this;
        }

        // 字符串条件
        if ( $value === null ) {
            $value = $operator;
            $operator = '=';
        }

        $this->where[] = [$field, $operator, $value];

        return $this;
    }

    // 排序
    public function order(string $order): self
    {
        $this->order = $order;
        return $this;
    }

    // 限制条数
    public function limit(int $limit, int $offset = 0): self
    {
        $this->limit = $offset > 0 ? "{$offset}, {$limit}" : "{$limit}";

        return $this;
    }

    // 统计总数
    public function count(): int
    {
        $sql = "SELECT COUNT(*) AS count FROM {$this->table}";
        $sql .= $this->buildWhereSql();
        $params = $this->getWhereParams();
        
        $res = $this->query($sql, $params);

        if ( $res['result'] && isset($res['result'][0]['results']) ) {
            $data = $this->format($res['result'][0]['results']);
            return (int)($data[0]['count'] ?? 0);
        }

        return 0;
    }

    // 查询多条
    public function select(): array
    {
        $sql = $this->buildSelectSql();
        $params = $this->getWhereParams();
        $res = $this->query($sql, $params);

        return $res['result'] ? $this->format($res['result'][0]['results']) : [];
    }

    // 查询单条
    public function find(): ?array
    {
        $res = $this->limit(1)->select();

        return $res[0] ?? null;
    }

    // 获取单个字段的值
    public function value(string $field)
    {
        $res = $this->field($field)->find();

        return $res[$field] ?? null;
    }

    // 获取某一列的数据
    public function column(string $field, ?string $key = null): array
    {
        $fieldStr = $key ? "{$key},{$field}" : $field;
        $res = $this->field($fieldStr)->select();

        return $key === null ? array_column($res, $field) : array_column($res, $field, $key);
    }

    // 插入数据
    public function insert(array $data): int
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

        return $res['success'] ? (int)$res['result'][0]['meta']['changes'] : 0;
    }

    // 更新数据
    public function update(array $data): int
    {
        if ( empty($this->where) ) {
            throw new \Exception('Update operation must requires WHERE condition');
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

        return $res['success'] ? (int)$res['result'][0]['meta']['changes'] : 0;
    }

    // 删除数据
    public function delete(): int
    {
        if ( empty($this->where) ) {
            throw new \Exception('Delete operation must requires WHERE condition');
        }

        $sql = "DELETE FROM {$this->table}" . $this->buildWhereSql();
        $res = $this->query($sql, $this->getWhereParams());

        return $res['success'] ? (int)$res['result'][0]['meta']['changes'] : 0;
    }

    // 获取最后执行的 SQL 语句
    public function getLastSql(): string
    {
        return $this->lastSql;
    }

    // ================= 内部方法 =================

    // 重置查询条件
    private function reset(): void
    {
        $this->where = [];
        $this->order = '';
        $this->limit = '';
        $this->field = '*';
    }

    // 统一格式化数据：columns + rows => 键值对数组
    private function format(array $data): array
    {
        $cols = $data['columns'] ?? [];
        $rows = $data['rows'] ?? [];
        $res = [];

        foreach ( $rows as $row ) {
            $res[] = array_combine($cols, $row);
        }

        return $res;
    }

    // 构建查询 SQL 语句
    private function buildSelectSql(): string
    {
        $sql = "SELECT {$this->field} FROM {$this->table}";
        $sql .= $this->buildWhereSql();

        if ( $this->order ) {
            $sql .= " ORDER BY {$this->order}";
        }

        if ( $this->limit ) {
            $sql .= " LIMIT {$this->limit}";
        }

        return $sql;
    }

    // 构建 WHERE 条件
    private function buildWhereSql(): string
    {
        if ( empty($this->where) ) {
            return '';
        }

        $arr = [];

        foreach ( $this->where as $w ) {
            $arr[] = "{$w[0]} {$w[1]} ?";
        }

        return " WHERE " . implode(' AND ', $arr);
    }

    // 获取预处理参数
    private function getWhereParams(): array
    {
        $params = [];

        foreach ( $this->where as $w ) {
            $params[] = $w[2];
        }

        return $params;
    }

    // 执行 SQL 语句
    private function query(string $sql, array $params = []): array
    {
        $this->lastSql = $sql;
        
        $api = 'https://api.cloudflare.com/client/v4/accounts/' . $this->config['D1']['accountId'] . '/d1/database/' . $this->dbId . '/raw';
        $header = [
            'Authorization: ' . $this->config['D1']['token'],
            'Content-Type: application/json',
        ];

        $res = HttpClient::post($api, json_encode(['sql' => $sql, 'params' => $params]), $header);
        
        // 关键点：执行后立即清空链式条件
        $this->reset();

        if ( $res['statusCode'] != 200 ) {
            return ['success' => false, 'error' => 'HTTP_ERROR_' . $res['statusCode']];
        }

        return json_decode($res['body'], true) ?? [];
    }
}