<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Kobit\Ktmngmysql\ktMngMySQL;

try {
    // 接続設定（環境に合わせて変更してください）
    $config = [
        'host' => 'localhost',
        'user' => 'testuser',
        'password' => 'Test1234',        // 適切なパスワードをセット
        'port' => 3306,
        'charset' => 'utf8mb4',
    ];

    // ktMngMySQL インスタンス生成
    $ktMng = new ktMngMySQL($config);

    // テスト対象スキーマ名（存在するDB名に変更ください）
    $schemaName = 'test';

    echo "=== スキーマ一覧取得テスト ===\n";
    $smlist = $ktMng->getSchemaList();
    echo $smlist . "\n";

    echo "=== スキーマ情報取得テスト ===\n";
    $dbInfo = $ktMng->getDBInfo($schemaName);
    echo $dbInfo . "\n";


    // スキーマ内のテーブル名を1つ指定（存在するテーブル名に変更ください）
    $tableName = 'expense';

    echo "=== テーブル情報取得テスト ===\n";
    $tableInfo = $ktMng->getTableInfo($tableName);
    echo $tableInfo . "\n";

} catch (Exception $e) {
    echo "エラー: " . $e->getMessage() . "\n";
}