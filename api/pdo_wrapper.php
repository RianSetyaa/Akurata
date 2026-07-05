<?php
/**
 * PDO-compatible wrapper menggunakan MySQLi.
 * File ini mendefinisikan class PDO dan PDOStatement palsu yang meniru
 * perilaku PDO asli, sehingga seluruh kode yang menggunakan PDO
 * tidak perlu diubah sama sekali.
 */

if (!class_exists('PDO', false)) {

    class PDO
    {
        public const ATTR_ERRMODE = 3;
        public const ERRMODE_EXCEPTION = 2;
        public const ATTR_DEFAULT_FETCH_MODE = 19;
        public const FETCH_ASSOC = 2;
        public const ATTR_EMULATE_PREPARES = 20;
        public const ATTR_TIMEOUT = 2;
        public const PARAM_INT = 1;
        public const PARAM_STR = 2;
        public const PARAM_NULL = 0;
        public const PARAM_BOOL = 5;

        private mysqli $mysqli;

        public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
        {
            // Parse DSN: mysql:host=...;port=...;dbname=...;charset=...
            $params = [];
            $dsnBody = substr($dsn, strpos($dsn, ':') + 1);
            foreach (explode(';', $dsnBody) as $part) {
                $part = trim($part);
                if ($part === '') continue;
                [$key, $val] = explode('=', $part, 2);
                $params[trim($key)] = trim($val);
            }

            $host = $params['host'] ?? '127.0.0.1';
            $port = (int) ($params['port'] ?? 3306);
            $dbname = $params['dbname'] ?? '';
            $charset = $params['charset'] ?? 'utf8mb4';

            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

            $this->mysqli = new mysqli($host, $username ?? '', $password ?? '', $dbname, $port);
            $this->mysqli->set_charset($charset);
        }

        public function prepare(string $sql): PDOStatement
        {
            // Konversi named parameters (:param) ke positional (?)
            $paramMap = [];
            $convertedSql = preg_replace_callback('/:([a-zA-Z_][a-zA-Z0-9_]*)/', function ($matches) use (&$paramMap) {
                $paramMap[] = ':' . $matches[1];
                return '?';
            }, $sql);

            $stmt = $this->mysqli->prepare($convertedSql);
            if ($stmt === false) {
                throw new RuntimeException('MySQLi prepare failed: ' . $this->mysqli->error);
            }

            return new PDOStatement($stmt, $paramMap);
        }

        public function query(string $sql): PDOStatement
        {
            $result = $this->mysqli->query($sql);
            if ($result === false) {
                throw new RuntimeException('MySQLi query failed: ' . $this->mysqli->error);
            }

            // SELECT queries return mysqli_result, others return true
            if ($result instanceof mysqli_result) {
                return new PDOStatement(null, [], $result);
            }

            return new PDOStatement(null, [], null, $this->mysqli->affected_rows);
        }

        public function exec(string $sql): int
        {
            $result = $this->mysqli->query($sql);
            if ($result === false) {
                throw new RuntimeException('MySQLi exec failed: ' . $this->mysqli->error);
            }
            return $this->mysqli->affected_rows;
        }

        public function lastInsertId(): string
        {
            return (string) $this->mysqli->insert_id;
        }

        public function beginTransaction(): bool
        {
            return $this->mysqli->begin_transaction();
        }

        public function commit(): bool
        {
            return $this->mysqli->commit();
        }

        public function rollBack(): bool
        {
            return $this->mysqli->rollback();
        }

        public function quote(string $string): string
        {
            return "'" . $this->mysqli->real_escape_string($string) . "'";
        }

        public function getMysqli(): mysqli
        {
            return $this->mysqli;
        }
    }

    class PDOStatement
    {
        private ?mysqli_stmt $stmt;
        private array $paramMap;
        private ?mysqli_result $result;
        private int $affectedRows;
        private array $boundValues = [];

        public function __construct(?mysqli_stmt $stmt, array $paramMap = [], ?mysqli_result $result = null, int $affectedRows = 0)
        {
            $this->stmt = $stmt;
            $this->paramMap = $paramMap;
            $this->result = $result;
            $this->affectedRows = $affectedRows;
        }

        public function bindValue(string $param, mixed $value, int $type = 2): bool
        {
            $this->boundValues[$param] = $value;
            return true;
        }

        public function execute(?array $params = null): bool
        {
            if ($this->stmt === null) {
                return false;
            }

            // Gabungkan boundValues jika params tidak diberikan
            if (($params === null || count($params) === 0) && count($this->boundValues) > 0) {
                $params = $this->boundValues;
                $this->boundValues = [];
            }

            if ($params !== null && count($params) > 0) {
                // Urutkan parameter sesuai paramMap
                $orderedValues = [];
                $types = '';

                if (count($this->paramMap) > 0) {
                    // Named parameters (:param => value)
                    foreach ($this->paramMap as $name) {
                        $value = $params[$name] ?? null;
                        $orderedValues[] = $value;
                        $types .= $this->detectType($value);
                    }
                } else {
                    // Positional parameters (? => value)
                    foreach ($params as $value) {
                        $orderedValues[] = $value;
                        $types .= $this->detectType($value);
                    }
                }

                if (count($orderedValues) > 0) {
                    $this->stmt->bind_param($types, ...$orderedValues);
                }
            }

            $success = $this->stmt->execute();
            if (!$success) {
                throw new RuntimeException('MySQLi execute failed: ' . $this->stmt->error);
            }

            $this->affectedRows = $this->stmt->affected_rows;

            // Ambil result set jika ada (SELECT queries)
            $result = $this->stmt->get_result();
            if ($result instanceof mysqli_result) {
                $this->result = $result;
            }

            return true;
        }

        public function fetch(): array|false
        {
            if ($this->result === null) {
                return false;
            }

            $row = $this->result->fetch_assoc();
            return $row ?? false;
        }

        public function fetchAll(): array
        {
            if ($this->result === null) {
                return [];
            }

            return $this->result->fetch_all(MYSQLI_ASSOC);
        }

        public function fetchColumn(int $column = 0): mixed
        {
            if ($this->result === null) {
                return false;
            }

            $row = $this->result->fetch_array(MYSQLI_NUM);
            if ($row === null) {
                return false;
            }

            return $row[$column] ?? false;
        }

        public function rowCount(): int
        {
            if ($this->result instanceof mysqli_result) {
                return $this->result->num_rows;
            }
            return $this->affectedRows;
        }

        private function detectType(mixed $value): string
        {
            if ($value === null) {
                return 's';
            }
            if (is_int($value)) {
                return 'i';
            }
            if (is_float($value)) {
                return 'd';
            }
            return 's';
        }
    }

}
