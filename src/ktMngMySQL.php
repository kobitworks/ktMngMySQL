<?php
/**
 * ktMngMySQL
 * 
 * MySQLデータベースからスキーマ・テーブル情報を取得し、
 * テキスト形式で返すライブラリ。
 * 
 * 接続情報はコンストラクタの引数で受け取る。
 */

namespace Kobit\Ktmngmysql;
use PDO;

class ktMngMySQL
{
    /** @var \PDO */
    private $pdo;

    /** @var string 現在接続中のスキーマ名 */
    private $currentSchema;

    /**
     * コンストラクタ
     * 
     * @param array $config {
     *      @type string 'host' ホスト名
     *      @type string 'user' ユーザ名
     *      @type string 'password' パスワード
     *      @type int    'port' ポート番号（省略可、デフォルト3306）
     *      @type string 'charset' 文字コード（省略可、デフォルト utf8mb4）
     * }
     * @throws \PDOException 接続失敗時に投げられる
     */
    public function __construct(array $config)
    {
        $host = $config['host'] ?? 'localhost';
        $user = $config['user'] ?? '';
        $password = $config['password'] ?? '';
        $port = $config['port'] ?? 3306;
        $charset = $config['charset'] ?? 'utf8mb4';

        // DSN組み立て
        $dsn = "mysql:host={$host};port={$port};charset={$charset}";

        // PDOオプション
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        // MYSQL_ATTR_INIT_COMMANDはMySQL PDO拡張が有効でないと未定義なので定数チェック
        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES {$charset}";
        }

        // 接続
        $this->pdo = new \PDO($dsn, $user, $password, $options);
        $this->currentSchema = null;
    }


    /**
     * スキーマ一覧取得
     * 
     * 現在のMySQLサーバに存在するスキーマ（データベース）名の一覧を
     * テキスト形式で返す。
     * 
     * @return string スキーマ名一覧（1行に1スキーマ名）
     */
    public function getSchemaList(): string
    {
        $sql = "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA ORDER BY SCHEMA_NAME";
        $stmt = $this->pdo->query($sql);
        $schemas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($schemas)) {
            return "スキーマは存在しません。\n";
        }

        $text = "スキーマ一覧:\n";
        foreach ($schemas as $schemaName) {
            $text .= "  - {$schemaName}\n";
        }

        return $text;
    }

    /**
     * スキーマ（データベース）情報取得
     *
     * 指定したスキーマ名のテーブル一覧とテーブル詳細情報をテキストで返す
     *
     * @param string $schemaName データベース名（スキーマ名）
     * @return string テキスト形式の情報
     * @throws \Exception 指定スキーマが存在しない場合
     */
    public function getDBInfo(string $schemaName): string
    {
        // スキーマ存在確認
        $stmt = $this->pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$schemaName]);
        $schema = $stmt->fetch();
        if (!$schema) {
            throw new \Exception("スキーマ '{$schemaName}' は存在しません。");
        }

        $this->currentSchema = $schemaName;

        $text = "スキーマ名: {$schemaName}\n\n";

        // テーブル一覧取得
        $sqlTables = "
            SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_COMMENT 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = ?
            ORDER BY TABLE_NAME";

        $stmtTables = $this->pdo->prepare($sqlTables);
        $stmtTables->execute([$schemaName]);
        $tables = $stmtTables->fetchAll();

        if (empty($tables)) {
            $text .= "テーブルは存在しません。\n";
            return $text;
        }

        foreach ($tables as $table) {
            $tableName = $table['TABLE_NAME'];
            $engine = $table['ENGINE'];
            $collation = $table['TABLE_COLLATION'];
            $comment = $table['TABLE_COMMENT'];

            $text .= "テーブル名: {$tableName}\n";
            $text .= "  エンジン: {$engine}\n";
            $text .= "  照合順序: {$collation}\n";
            $text .= "  コメント: ".($comment ?: 'なし')."\n";

            // カラム情報取得
            $sqlColumns = "
                SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
                ORDER BY ORDINAL_POSITION";

            $stmtColumns = $this->pdo->prepare($sqlColumns);
            $stmtColumns->execute([$schemaName, $tableName]);
            $columns = $stmtColumns->fetchAll();

            $text .= "  カラム:\n";
            foreach ($columns as $col) {
                $colName = $col['COLUMN_NAME'];
                $colType = $col['COLUMN_TYPE'];
                $nullable = $col['IS_NULLABLE'];
                $key = $col['COLUMN_KEY'];
                $default = $col['COLUMN_DEFAULT'];
                $extra = $col['EXTRA'];
                $colComment = $col['COLUMN_COMMENT'];

                $info = "    - {$colName} : {$colType}, NULL={$nullable}, KEY={$key}";
                if ($default !== null) {
                    $info .= ", DEFAULT={$default}";
                }
                if ($extra !== '') {
                    $info .= ", {$extra}";
                }
                if ($colComment !== '') {
                    $info .= ", コメント={$colComment}";
    }

                $text .= "{$info}\n";
            }

            $text .= "\n";
        }

        return $text;
    }


    /**
     * テーブル情報取得
     *
     * 現在のスキーマにある指定テーブルの詳細情報をテキストで返す
     *
     * @param string $tableName テーブル名
     * @return string テキスト形式の情報
     * @throws \Exception スキーマ未指定またはテーブルが存在しない場合
     */
    public function getTableInfo(string $tableName): string
    {
        if (!$this->currentSchema) {
            throw new \Exception("スキーマが指定されていません。まずgetDBInfo()でスキーマ名を指定してください。");
        }

        // テーブル存在確認
        $stmt = $this->pdo->prepare("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_COMMENT FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?");
        $stmt->execute([$this->currentSchema, $tableName]);
        $table = $stmt->fetch();

        if (!$table) {
            throw new \Exception("テーブル '{$tableName}' はスキーマ '{$this->currentSchema}' に存在しません。");
        }

        $text = "テーブル名: {$tableName}\n";
        $text .= "  エンジン: {$table['ENGINE']}\n";
        $text .= "  照合順序: {$table['TABLE_COLLATION']}\n";
        $text .= "  コメント: ".($table['TABLE_COMMENT'] ?: 'なし')."\n";

        // カラム情報
        $sqlColumns = "
            SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY ORDINAL_POSITION";

        $stmtColumns = $this->pdo->prepare($sqlColumns);
        $stmtColumns->execute([$this->currentSchema, $tableName]);
        $columns = $stmtColumns->fetchAll();

        $text .= "  カラム:\n";
        foreach ($columns as $col) {
            $colName = $col['COLUMN_NAME'];
            $colType = $col['COLUMN_TYPE'];
            $nullable = $col['IS_NULLABLE'];
            $key = $col['COLUMN_KEY'];
            $default = $col['COLUMN_DEFAULT'];
            $extra = $col['EXTRA'];
            $colComment = $col['COLUMN_COMMENT'];

            $info = "    - {$colName} : {$colType}, NULL={$nullable}, KEY={$key}";
            if ($default !== null) {
                $info .= ", DEFAULT={$default}";
            }
            if ($extra !== '') {
                $info .= ", {$extra}";
            }
            if ($colComment !== '') {
                $info .= ", コメント={$colComment}";
            }

            $text .= "{$info}\n";
        }

        // インデックス情報の取得
        $sqlIndexes = "
            SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, CARDINALITY, SUB_PART, PACKED, NULLABLE, INDEX_TYPE, COMMENT, INDEX_COMMENT
            FROM INFORMATION_SCHEMA.STATISTICS
            WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
            ORDER BY INDEX_NAME, SEQ_IN_INDEX";

        $stmtIndexes = $this->pdo->prepare($sqlIndexes);
        $stmtIndexes->execute([$this->currentSchema, $tableName]);
        $indexesRaw = $stmtIndexes->fetchAll();

        // インデックスごとにまとめる
        $indexes = [];
        foreach ($indexesRaw as $idx) {
            $indexName = $idx['INDEX_NAME'];
            if (!isset($indexes[$indexName])) {
                $indexes[$indexName] = [
                    'non_unique' => $idx['NON_UNIQUE'],
                    'columns' => []
                ];
            }
            $indexes[$indexName]['columns'][] = $idx['COLUMN_NAME'];
        }

        $text .= "  インデックス:\n";
        foreach ($indexes as $name => $info) {
            $type = $info['non_unique'] == 0 ? 'ユニーク' : '非ユニーク';
            $cols = implode(', ', $info['columns']);

            $text .= "    - {$name}: 種類={$type}, カラム=[{$cols}]\n";
        }

        return $text;
    }

    /**
     * 指定されたスキーマのCREATEステートメント取得
     * 
     * @param string $schemaName スキーマ名
     * @return string DROP + CREATE DATABASE 文
     * @throws \Exception スキーマが存在しない場合はCREATEだけ返す（存在確認は一旦スキップ）
     */
    public function getCreateSchemaSQL(string $schemaName): string
    {
        // スキーマ存在確認
        $stmt = $this->pdo->prepare("SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$schemaName]);
        $schema = $stmt->fetch();

        $charset = 'utf8mb4';
        $collation = 'utf8mb4_general_ci';

        if ($schema) {
            $charset = $schema['DEFAULT_CHARACTER_SET_NAME'] ?: $charset;
            $collation = $schema['DEFAULT_COLLATION_NAME'] ?: $collation;

            // 既存スキーマ削除DROP文
            $dropSQL = "DROP DATABASE IF EXISTS `{$schemaName}`;\n";
        } else {
            // 存在しなくてもDROP文は安全（DROP DATABASE IF EXISTS）
            $dropSQL = "DROP DATABASE IF EXISTS `{$schemaName}`;\n";
        }

        $createSQL = "CREATE DATABASE `{$schemaName}` CHARACTER SET {$charset} COLLATE {$collation};\n";

        return $dropSQL . $createSQL;
    }

    /**
     * 指定スキーマのすべてのテーブルに対してDROP文 + CREATE TABLE文を返す
     * 
     * @param string $schemaName スキーマ名
     * @return string DROP + CREATE TABLE 文一覧
     * @throws \Exception スキーマが存在しない場合
     */
    public function getCreateTablesSQL(string $schemaName): string
    {
        // スキーマ存在確認
        $stmt = $this->pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$schemaName]);
        if (!$stmt->fetch()) {
            throw new \Exception("スキーマ '{$schemaName}' は存在しません。");
        }

        $sql = "";
        // テーブル一覧（BASE TABLEのみ）
        $stmtTables = $this->pdo->prepare("
            SELECT TABLE_NAME 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME");
        $stmtTables->execute([$schemaName]);
        $tables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            // DROP TABLE 文（必ず存在しなくても対応）
            $sql .= "DROP TABLE IF EXISTS `{$schemaName}`.`{$table}`;\n";

            // CREATE TABLE 文取得
            $stmtCreate = $this->pdo->query("SHOW CREATE TABLE `{$schemaName}`.`{$table}`");
            $row = $stmtCreate->fetch(PDO::FETCH_ASSOC);
            if (isset($row['Create Table'])) {
                $sql .= $row['Create Table'] . ";\n\n";
            }
        }

        if ($sql === "") {
            $sql = "-- テーブルは存在しません。\n";
        }

        return $sql;
    }

    /**
     * 指定スキーマのすべてのビューに対してDROP文 + CREATE VIEW文を返す
     * 
     * @param string $schemaName スキーマ名
     * @return string DROP + CREATE VIEW 文一覧
     * @throws \Exception スキーマが存在しない場合
     */
    public function getCreateViewsSQL(string $schemaName): string
    {
        // スキーマ存在確認
        $stmt = $this->pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$schemaName]);
        if (!$stmt->fetch()) {
            throw new \Exception("スキーマ '{$schemaName}' は存在しません。");
        }

        $sql = "";
        // ビュー一覧
        $stmtViews = $this->pdo->prepare("
            SELECT TABLE_NAME 
            FROM INFORMATION_SCHEMA.VIEWS 
            WHERE TABLE_SCHEMA = ? 
            ORDER BY TABLE_NAME");
        $stmtViews->execute([$schemaName]);
        $views = $stmtViews->fetchAll(PDO::FETCH_COLUMN);

        foreach ($views as $view) {
            // DROP VIEW 文（IF EXISTS付き）
            $sql .= "DROP VIEW IF EXISTS `{$schemaName}`.`{$view}`;\n";

            // CREATE VIEW 文取得
            $stmtCreate = $this->pdo->query("SHOW CREATE VIEW `{$schemaName}`.`{$view}`");
            $row = $stmtCreate->fetch(PDO::FETCH_ASSOC);
            if (isset($row['Create View'])) {
                $sql .= $row['Create View'] . ";\n\n";
            }
        }

        if ($sql === "") {
            $sql = "-- ビューは存在しません。\n";
        }

        return $sql;
    }

    /**
     * 指定スキーマのすべてのストアドファンクションに対してDROP文 + CREATE FUNCTION文を返す
     * 
     * @param string $schemaName スキーマ名
     * @return string DROP + CREATE FUNCTION 文一覧
     * @throws \Exception スキーマが存在しない場合
     */
    public function getCreateFunctionsSQL(string $schemaName): string
    {
        // スキーマ存在確認
        $stmt = $this->pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$schemaName]);
        if (!$stmt->fetch()) {
            throw new \Exception("スキーマ '{$schemaName}' は存在しません。");
        }

        $sql = "";
        // ストアドファンクション一覧
        $stmtFuncs = $this->pdo->prepare("
            SELECT ROUTINE_NAME 
            FROM INFORMATION_SCHEMA.ROUTINES 
            WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = 'FUNCTION'
            ORDER BY ROUTINE_NAME");
        $stmtFuncs->execute([$schemaName]);
        $functions = $stmtFuncs->fetchAll(PDO::FETCH_COLUMN);

        foreach ($functions as $func) {
            // DROP FUNCTION 文
            $sql .= "DROP FUNCTION IF EXISTS `{$schemaName}`.`{$func}`;\n";

            // CREATE FUNCTION 文取得
            $stmtCreate = $this->pdo->query("SHOW CREATE FUNCTION `{$schemaName}`.`{$func}`");
            $row = $stmtCreate->fetch(PDO::FETCH_ASSOC);
            if (isset($row['Create Function'])) {
                $sql .= $row['Create Function'] . ";\n\n";
            }
        }

        if ($sql === "") {
            $sql = "-- ファンクションは存在しません。\n";
        }

        return $sql;
    }

    /**
     * 指定スキーマのすべてのストアドプロシージャに対してDROP文 + CREATE PROCEDURE文を返す
     * 
     * @param string $schemaName スキーマ名
     * @return string DROP + CREATE PROCEDURE 文一覧
     * @throws \Exception スキーマが存在しない場合
     */
    public function getCreateProceduresSQL(string $schemaName): string
    {
        // スキーマ存在確認
        $stmt = $this->pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$schemaName]);
        if (!$stmt->fetch()) {
            throw new \Exception("スキーマ '{$schemaName}' は存在しません。");
        }

        $sql = "";
        // ストアドプロシージャ一覧
        $stmtProcs = $this->pdo->prepare("
            SELECT ROUTINE_NAME 
            FROM INFORMATION_SCHEMA.ROUTINES 
            WHERE ROUTINE_SCHEMA = ? AND ROUTINE_TYPE = 'PROCEDURE'
            ORDER BY ROUTINE_NAME");
        $stmtProcs->execute([$schemaName]);
        $procedures = $stmtProcs->fetchAll(PDO::FETCH_COLUMN);

        foreach ($procedures as $proc) {
            // DROP PROCEDURE 文
            $sql .= "DROP PROCEDURE IF EXISTS `{$schemaName}`.`{$proc}`;\n";

            // CREATE PROCEDURE 文取得
            $stmtCreate = $this->pdo->query("SHOW CREATE PROCEDURE `{$schemaName}`.`{$proc}`");
            $row = $stmtCreate->fetch(PDO::FETCH_ASSOC);
            if (isset($row['Create Procedure'])) {
                $sql .= $row['Create Procedure'] . ";\n\n";
            }
        }

        if ($sql === "") {
            $sql = "-- プロシージャは存在しません。\n";
        }

        return $sql;
    }
    /*
     * 指定スキーマのすべてのCREATE文（スキーマ・テーブル・ビュー・関数・プロシージャ）をまとめて取得する
     * 
     * @param string $schemaName スキーマ名
     * @return string DROP + CREATE 文一覧
     * @throws \Exception スキーマが存在しない場合
     */
    public function getCreateAllSQL(string $schemaName): string
    {
        // スキーマ存在確認（全体の最初だけでOK）
        $stmt = $this->pdo->prepare("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?");
        $stmt->execute([$schemaName]);
        if (!$stmt->fetch()) {
            throw new \Exception("スキーマ '{$schemaName}' は存在しません。");
        }

        $sql = "";

        // スキーマ（データベース）作成
        $sql .= "-- スキーマ（データベース）作成\n";
        $sql .= $this->getCreateSchemaSQL($schemaName) . "\n";

        // テーブル作成
        $sql .= "-- テーブル作成\n";
        $sql .= $this->getCreateTablesSQL($schemaName) . "\n";

        // ビュー作成
        $sql .= "-- ビュー作成\n";
        $sql .= $this->getCreateViewsSQL($schemaName) . "\n";

        // ストアドファンクション作成
        $sql .= "-- ストアドファンクション作成\n";
        $sql .= $this->getCreateFunctionsSQL($schemaName) . "\n";

        // ストアドプロシージャ作成
        $sql .= "-- ストアドプロシージャ作成\n";
        $sql .= $this->getCreateProceduresSQL($schemaName) . "\n";

        return $sql;
    }

}