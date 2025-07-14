# ktMngMySQL

## 概要

`ktMngMySQL` は、MySQLデータベースのスキーマ、テーブル、ビュー、ストアドプロシージャ、ストアドファンクションに関する情報を取得し、それらのDDL（CREATE文）をテキスト形式で生成するPHPライブラリです。  
主にスキーマ構造の確認やバックアップ、マイグレーション用のSQL生成に利用できます。

---

## 機能

- MySQLサーバ上のスキーマ一覧取得
- 指定スキーマのテーブル一覧およびテーブル詳細情報の取得
- 指定テーブルのカラムやインデックスなど詳細情報の取得
- 指定スキーマのCREATE DATABASE文（スキーマ作成SQL）の生成
- 指定スキーマ内の全テーブルのDROP + CREATE TABLE文生成
- 指定スキーマ内の全ビューのDROP + CREATE VIEW文生成
- 指定スキーマ内の全ストアドファンクションのDROP + CREATE FUNCTION文生成
- 指定スキーマ内の全ストアドプロシージャのDROP + CREATE PROCEDURE文生成
- 上記すべてをまとめたCREATE文一括生成

---

## 動作環境

- PHP 7.1以上（PDO拡張が有効であること）
- MySQLサーバ（情報スキーマへアクセス可能であること）

---

## インストール

Composerでのインストール例（パッケージ名は仮）:

```
composer require kobit/ktmngmysql
```

※実際のパッケージ名は適宜調整してください。

---

## 使い方

以下のコード例は、`test.php` に記載されている基本的な使用例です。

```php:c:\shino\SourceTree\ktMngMySQL\test\test.php
<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Kobit\Ktmngmysql\ktMngMySQL;

try {
    // MySQL接続設定
    $config = [
        'host' => 'localhost',
        'user' => 'testuser',
        'password' => 'Test1234',
        'port' => 3306,
        'charset' => 'utf8mb4',
    ];

    // インスタンス生成
    $ktMng = new ktMngMySQL($config);

    // 使用するスキーマ名（存在するデータベース名）
    $schemaName = 'test';  

    echo "<pre>\n";

    // スキーマ一覧取得
    echo "=== スキーマ一覧取得テスト ===\n";
    echo $ktMng->getSchemaList() . "\n";

    // スキーマ情報取得（テーブル一覧や詳細）
    echo "=== スキーマ情報取得テスト ===\n";
    echo $ktMng->getDBInfo($schemaName) . "\n";

    // テーブル情報取得（スキーマを指定した後）
    $tableName = 'customers';  // 存在するテーブル名に変更してください
    echo "=== テーブル情報取得テスト ===\n";
    echo $ktMng->getTableInfo($tableName) . "\n";

    echo "<hr>\n";

    // スキーマ作成SQLの取得（DROP + CREATE DATABASE文）
    echo "=== スキーマ作成コマンド取得テスト ===\n";
    echo $ktMng->getCreateSchemaSQL($schemaName) . "\n";

    // スキーマ内のテーブルCREATE文取得
    echo "=== テーブル作成コマンド取得テスト ===\n";
    echo $ktMng->getCreateTablesSQL($schemaName) . "\n";

    // ビューCREATE文取得
    echo "=== ビュー作成コマンド取得テスト ===\n";
    echo $ktMng->getCreateViewsSQL($schemaName) . "\n";

    // ストアドプロシージャCREATE文取得
    echo "=== プロシージャ作成コマンド取得テスト ===\n";
    echo $ktMng->getCreateProceduresSQL($schemaName) . "\n";

    // ストアドファンクションCREATE文取得
    echo "=== ファンクション作成コマンド取得テスト ===\n";
    echo $ktMng->getCreateFunctionsSQL($schemaName) . "\n";

    // スキーマ内すべてのCREATE文一括取得
    echo "=== 全CREATEコマンド取得テスト ===\n";
    echo $ktMng->getCreateAllSQL($schemaName) . "\n";

    echo "</pre>\n";

} catch (Exception $e) {
    echo "エラー: " . $e->getMessage() . "\n";
}
```

---

## クラス概要

### `Kobit\Ktmngmysql\ktMngMySQL`

| メソッド名               | 説明                                                                                      |
|-------------------------|-------------------------------------------------------------------------------------------|
| `__construct(array $config)` | MySQL接続設定からPDO接続を行う。`host`, `user`, `password`, `port`, `charset`を設定可能。     |
| `getSchemaList(): string`       | MySQLサーバに存在するスキーマ一覧をテキストで返す。                                           |
| `getDBInfo(string $schemaName): string`  | 指定スキーマ内のテーブル一覧とテーブル詳細（カラム等）情報をテキストで返す。                  |
| `getTableInfo(string $tableName): string`  | 現在指定中スキーマの特定テーブルの詳細情報（カラム・インデックス等）をテキストで返す。       |
| `getCreateSchemaSQL(string $schemaName): string`  | 指定スキーマのDROP + CREATE DATABASE文を返す。                                             |
| `getCreateTablesSQL(string $schemaName): string`  | 指定スキーマ内すべてのテーブルのDROP + CREATE TABLE文を返す。                               |
| `getCreateViewsSQL(string $schemaName): string`  | 指定スキーマ内すべてのビューのDROP + CREATE VIEW文を返す。                                 |
| `getCreateFunctionsSQL(string $schemaName): string`  | 指定スキーマ内すべてのストアドファンクションのDROP + CREATE FUNCTION文を返す。              |
| `getCreateProceduresSQL(string $schemaName): string`  | 指定スキーマ内すべてのストアドプロシージャのDROP + CREATE PROCEDURE文を返す。               |
| `getCreateAllSQL(string $schemaName): string`  | スキーマ、テーブル、ビュー、ファンクション、プロシージャのすべてのCREATE文をまとめて取得する。 |

---

## 注意事項

- 本ライブラリは、MySQLの情報スキーマに依存しています。MySQLサーバの権限設定によっては、情報取得に制限がある場合があります。  
- `getTableInfo()` は事前に対象スキーマを `getDBInfo()` でセットしておく必要があります。  
- ストアドプロシージャやファンクションのCREATE文取得はMySQLのバージョンや設定に依存し、一部動作しないケースもあるため、環境にて十分テストしてください。  
- 大量のテーブルやオブジェクトがあるスキーマでは処理に時間がかかる可能性があります。

---

## ライセンス

本ライブラリのライセンス情報は特に明記されていません。利用時はソースコードをよく確認してください。

---

## 作者情報

- Kobit (名前等はファイルに記載なし)
- 質問者の環境に合わせて修正・拡張可能です。
