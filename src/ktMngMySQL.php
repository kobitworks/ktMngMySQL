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
}