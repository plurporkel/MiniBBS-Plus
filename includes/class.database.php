<?php

declare(strict_types=1);

use PDO;
use PDOException;
use PDOStatement;

/**
 * Database handler using PDO with query builder functionality
 */
class Database extends PDO
{
    private ?array $sql = null;
    private const SQL_DEFAULT = [
        'selects' => [],
        'distinct' => false,
        'froms' => [],
        'joins' => [],
        'wheres' => [],
        'order_by' => [],
        'group_by' => [],
        'limit' => null,
        'offset' => null,
        'params' => []
    ];
    
    /** @var array<string, float> Previous queries with execution times */
    public array $queries = [];

    public function __construct(string $username, string $password, string $server, string $database)
    {
        $dsn = "mysql:host={$server};port=3306;dbname={$database};charset=utf8mb4";
        try {
            parent::__construct($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [DatabaseStatement::class, [$this]]);
        } catch (PDOException $e) {
            throw new DatabaseConnectionException(
                str_replace(
                    [$username, $password, $server, $database],
                    ['', '', '(server)', '(database)'],
                    $e->getMessage()
                ),
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Execute a raw query with parameters
     * @param string $query SQL query
     * @param mixed ...$params Query parameters
     * @return DatabaseStatement
     */
    public function q(string $query, ...$params): DatabaseStatement
    {
        $statement = $this->prepare($query);
        $statement->execute($params ?: null);
        return $statement;
    }

    /**
     * Get number of rows from last SELECT (MySQL-specific)
     * @return int
     * @deprecated Use COUNT() in query instead
     */
    public function numRows(): int
    {
        return (int)$this->q('SELECT FOUND_ROWS()')->fetchColumn();
    }

    /**
     * Execute built query from chainable methods
     * @return DatabaseStatement
     * @throws RuntimeException if no query built
     */
    public function exec(): DatabaseStatement
    {
        if (empty($this->sql['selects'])) {
            throw new RuntimeException('No query built before calling exec()');
        }

        $query = $this->buildSelectQuery();
        $statement = $this->prepare($query);
        $statement->execute($this->sql['params'] ?: null);
        
        $this->sql = null;
        return $statement;
    }

    private function buildSelectQuery(): string
    {
        $query = ['SELECT'];
        if ($this->sql['distinct']) {
            $query[] = 'DISTINCT';
        }
        
        $query[] = implode(', ', $this->sql['selects']);
        $query[] = 'FROM ' . implode(', ', $this->sql['froms']);
        
        if (!empty($this->sql['joins'])) {
            $query[] = implode(' ', $this->sql['joins']);
        }
        if (!empty($this->sql['wheres'])) {
            $query[] = 'WHERE ' . implode(' AND ', $this->sql['wheres']);
        }
        if (!empty($this->sql['group_by'])) {
            $query[] = 'GROUP BY ' . implode(', ', $this->sql['group_by']);
        }
        if (!empty($this->sql['order_by'])) {
            $query[] = 'ORDER BY ' . implode(', ', $this->sql['order_by']);
        }
        if ($this->sql['limit'] !== null) {
            $query[] = 'LIMIT';
            if ($this->sql['offset'] !== null) {
                $query[] = (int)$this->sql['offset'] . ',';
            }
            $query[] = (int)$this->sql['limit'];
        }
        
        return implode(' ', $query);
    }

    private function initSql(): void
    {
        $this->sql ??= self::SQL_DEFAULT;
    }

    public function select(string $fields): self
    {
        $this->initSql();
        $this->sql['selects'][] = $fields;
        return $this;
    }

    public function distinct(): self
    {
        $this->initSql();
        $this->sql['distinct'] = true;
        return $this;
    }

    public function from(string $tables): self
    {
        $this->initSql();
        $this->sql['froms'][] = $tables;
        return $this;
    }

    public function join(string $table, string $on, string $type = 'LEFT OUTER'): self
    {
        $this->initSql();
        $this->sql['joins'][] = "$type JOIN $table ON $on";
        return $this;
    }

    public function where(string $condition, ...$params): self
    {
        $this->initSql();
        $this->sql['wheres'][] = $condition;
        if ($params) {
            $this->sql['params'] = array_merge($this->sql['params'], $params);
        }
        return $this;
    }

    public function groupBy(string $fields): self
    {
        $this->initSql();
        $this->sql['group_by'][] = $fields;
        return $this;
    }

    public function orderBy(string $fields): self
    {
        $this->initSql();
        $this->sql['order_by'][] = $fields;
        return $this;
    }

    public function limit(int $limit, ?int $offset = null): self
    {
        $this->initSql();
        $this->sql['limit'] = $limit;
        $this->sql['offset'] = $offset;
        return $this;
    }

    public function queryTime(): float
    {
        return array_sum($this->queries);
    }

    public function queryCount(): int
    {
        return count($this->queries);
    }
}

/**
 * Custom PDO Statement class with execution timing
 */
class DatabaseStatement extends PDOStatement
{
    public Database $dbh;

    protected function __construct(Database $dbh)
    {
        $this->dbh = $dbh;
    }

    public function execute($params = null): bool
    {
        $start = microtime(true);
        $result = parent::execute($params);
        $this->dbh->queries[$this->queryString] = microtime(true) - $start;
        return $result;
    }
}

/**
 * Custom exception for database connection errors
 */
class DatabaseConnectionException extends Exception {}