# Chandra

Chandra は、業務アプリや CMS を PHP で構築するための軽量ライブラリです。  
フルスタックフレームワークのように全体を囲い込むのではなく、認証、セッション、DB、CSRF、Cookie、ロギングといった共通基盤を薄く提供し、アプリ側が業務ロジックを実装しやすい形を目指しています。

## 特徴

- HTML＋PHPによる、1画面＝1HTMLのシンプルなページ分割式
- 軽量で、責務が比較的明確
- 処理の見通しのよさと自由なカスタマイズ性を優先した、小規模開発に寄り添う構成
- Composerに対応。PSR-4に準拠。

## 想定ユースケース

- 在庫管理、販売管理、社内管理画面などの業務アプリ
- 画面遷移や DB 操作を中心とした PHP アプリ
- 認証やセッションは共通化したいが、業務ロジックは自由に持ちたいケース

## 責務分担

Chandra は以下を担当します。

- 認証フローの制御
- セッションの確立と維持
- DB 操作の共通基盤
- CSRF / Cookie / Utility の補助
- ログ出力の基盤

一方で、以下は基本的にアプリ側の責務です。

- 画面構成
- 業務ロジック
- ユーザー情報の取得方法
- ログイン試行制限の閾値や保存先
- アカウントロックの文言や運用方針

## インストール

```bash
composer require studiogau/chandra
```

必要環境:

- PHP 8.2 以上
- Composer

## 前提条件

Chandra を組み込むアプリでは、少なくとも次の前提を満たしている必要があります。

### 実行環境

- PHP 8.2 以上
- Composer で依存パッケージを導入済みであること
- Web アプリとして使う場合は PHP の Session が利用可能であること

### DB 利用時の前提

- `Database` / `PdoConnection` は MySQL 前提です
- PHP の PDO と MySQL 用ドライバ（`pdo_mysql`）が有効である必要があります
- 接続情報は `ini` ファイルまたは環境変数で与える必要があります
- `Database::insert()` / `update()` を使うテーブルは、監査列として `created_at` `created_by` `updated_at` `updated_by` を持つ前提です

### 認証・セッション利用時の前提

- `AuthService` や `SessionHelper` を使う前に、アプリ側で `session_start()` を呼ぶ前提です
- 認証本体はアプリ側の `UserRepositoryInterface` 実装に委譲されるため、ユーザー照合処理は別途実装が必要です
- `UserRepositoryInterface` の戻り値は、少なくとも `user_id` `user_name` `permissions` を含む配列である必要があります
- セッションキーと CSRF トークンの名前空間は `$_SERVER['SCRIPT_NAME']` の先頭ディレクトリ名を使うため、安定した URL 配置を前提にしています

### 機能ごとの追加前提

- `CookieHelper` を使う場合は OpenSSL 関数を利用するため、`openssl` 拡張が必要です
- `Utility` の画像縮小系メソッドを使う場合は GD 関数を利用するため、`gd` 拡張が必要です
- `MailConfig` を使う場合は、`smtp_host` と `mail_from` を設定する必要があります
- `MailConfig` で SMTP 認証を有効にする場合は、追加で `smtp_user` と `smtp_pass` も必要です

### テスト実行時の前提

- テスト実行には `require-dev` を含めた Composer install が必要です
- `vendor/bin/phpunit` の実行には PHPUnit 11 系が入っている必要があります
- MySQL 統合テストを動かす場合は、`tests/dbconfig.local.ini` を用意し、テスト用 DB を作成・削除できる権限が必要です

## クイックスタート

### 1. データベース接続を設定する

Chandra の `Database` / `PdoConnection` は、MySQL 接続情報を次のどちらかで読み込めます。

- `ini` ファイル
- 環境変数

`noblestock` では `fromConfiguredSource()` を使い、通常は `ini`、必要なら環境変数へ切り替えられるようにしています。

#### 1-1. ini ファイルで設定する

最も簡単なのは `dbconfig.ini` を置く方法です。  
`PdoConnection::fromIni()` や `Database::fromConfiguredSource()` は、次のような形式を読めます。

```ini
dbhost=127.0.0.1
dbport=3306
dbuser=root
dbpass=secret
dbname=noblestock
charset=utf8mb4
```

利用例:

```php
use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Logging\Logger;

$logger = Logger::createDefault(dirname(__DIR__, 1));
$database = Database::fromConfiguredSource(
    dirname(__DIR__, 1) . '/config/dbconfig.ini',
    $logger
);
```

#### 1-2. 環境変数で設定する

`CHANDRA_DB_SOURCE=env` を設定すると、`fromConfiguredSource()` は環境変数から接続情報を読みます。  
既定では以下の変数名が使われます。

- `DB_HOST`
- `DB_PORT`
- `DB_NAME`
- `DB_USER`
- `DB_PASS`
- `DB_CHARSET`

Apache でのざっくりした例:

```apache
SetEnv CHANDRA_DB_SOURCE env
SetEnv DB_HOST 127.0.0.1
SetEnv DB_PORT 3306
SetEnv DB_NAME noblestock
SetEnv DB_USER root
SetEnv DB_PASS secret
SetEnv DB_CHARSET utf8mb4
```

アプリコード側は `ini` と同じ呼び出しのままで構いません。

```php
$database = Database::fromConfiguredSource(
    dirname(__DIR__, 1) . '/config/dbconfig.ini',
    $logger
);
```

#### 1-3. ユーザーテーブルの例

認証に使う最小例としては、`noblestock` の `users` テーブルのように
`login_id`、`password_hash`、`user_name` を持つ構成が分かりやすいです。

```sql
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT NOT NULL,
    login_id VARCHAR(16) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    user_name VARCHAR(40) NOT NULL,
    authority VARCHAR(50),
    created_at DATETIME NOT NULL,
    created_by VARCHAR(16) NOT NULL,
    updated_at DATETIME,
    updated_by VARCHAR(16),
    PRIMARY KEY (id),
    UNIQUE KEY (login_id)
);
```

Chandra の `UserRepositoryInterface` に返す値は、このテーブル構造そのものではなく、`user_id` / `user_name` / `permissions` へ詰め替えた配列です。

#### 1-4. 監査証跡を有効にする

Chandra の `Database` は、`insert()` と `update()` のときに監査列を自動補完します。

- `created_at`
- `created_by`
- `updated_at`
- `updated_by`

ログインユーザーを監査列へ反映したい場合は、DB 操作前に `setCurrentUserId()` を呼びます。  
`noblestock` でも各 model / repository のコンストラクタでこの設定を行っています。

```php
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Database\Database;

$logger = Logger::createDefault(dirname(__DIR__, 1));
$database = Database::fromConfiguredSource(
    dirname(__DIR__, 1) . '/config/dbconfig.ini',
    $logger
);

$auth = AuthServiceFactory::create($logger);
$userId = $auth->getCurrentUser()?->getUserId();

if (!empty($userId)) {
    $database->setCurrentUserId($userId);
}
```

ユーザーIDを設定しない場合は、既定値の `SYSTEM` が監査列に入ります。

### 2. UserRepository を実装する

`AuthService` は資格情報の照合そのものを `UserRepositoryInterface` に委譲します。  
戻り値は最低限、`user_id`、`user_name`、`permissions` を含む配列です。

`noblestock` では、アプリ側の `users` テーブル構造をそのまま返すのではなく、Chandra が扱う形に変換しています。

```php
use Studiogau\Chandra\Auth\AuthException;
use Studiogau\Chandra\Auth\UserRepositoryInterface;

final class UserRepository implements UserRepositoryInterface
{
    public function findByCredentials(string $userId, string $password): ?array
    {
        $userModel = new User();

        try {
            $row = $userModel->login($userId, $password);
        } catch (\InvalidArgumentException $e) {
            throw new AuthException('Invalid user ID or password');
        }

        return [
            'user_id' => $row['login_id'],
            'user_name' => $row['user_name'],
            'permissions' => $this->buildPermissionsFromAuthority((string) ($row['authority'] ?? '')),
        ];
    }

    private function buildPermissionsFromAuthority(string $authority): array
    {
        // アプリ側の権限表現を、Chandra の permissions 配列へ変換する。
        return ['menu.php', 'product_list.php'];
    }
}
```

### 3. AuthService を組み立てる

guard を使わない最小構成なら、単純に `AuthService` を生成できます。

```php
use Studiogau\Chandra\Auth\AuthService;

$auth = new AuthService(new UserRepository());
```

一方で `noblestock` では、アプリ側の `AuthServiceFactory` を用意し、`UserRepository` と `LoginGuardInterface` 実装をまとめて差し込んでいます。

```php
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Logging\Logger;

final class AuthServiceFactory
{
    public static function create(?Logger $logger = null): AuthService
    {
        $logger = $logger ?? Logger::createDefault(dirname(__DIR__, 1));

        return new AuthService(
            new UserRepository(),
            $logger,
            [new LoginRateLimitGuard(null, $logger)]
        );
    }
}
```

### 4. ログインする

Chandra のログイン API は `login()` のみです。  
入力値は `LoginCredentials`、試行コンテキストは `LoginAttempt` で渡します。

`noblestock/public/menu.php` に沿った形だと、次のようになります。

```php
use Studiogau\Chandra\Auth\AuthException;
use Studiogau\Chandra\Auth\LoginAttempt;
use Studiogau\Chandra\Auth\LoginCredentials;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Support\Utility;

$logger = Logger::createDefault(dirname(__DIR__, 1));
$auth = AuthServiceFactory::create($logger);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfScope = (string) ($_POST[Utility::getCsrfScopeFieldName()] ?? '');
    $csrfToken = (string) ($_POST[Utility::getCsrfFieldName()] ?? '');

    if (!Utility::validatePostedCsrfToken($csrfScope, $csrfToken)) {
        SessionHelper::flushError('Invalid csrf token');
        header('Location: index.php');
        exit;
    }

    try {
        $credentials = new LoginCredentials(
            (string) ($_POST['user'] ?? ''),
            (string) ($_POST['pass'] ?? '')
        );
        $attempt = LoginAttempt::fromServer($credentials->getUserId(), $_SERVER);

        $auth->login($credentials, $attempt);
        header('Location: menu.php');
        exit;
    } catch (AuthException $e) {
        SessionHelper::flushError('ログインできませんでした。');
        header('Location: index.php');
        exit;
    }
}
```

### 5. セッション確認

```php
if (!$auth->checkUserSession()) {
    header('Location: /login.php');
    exit;
}
```

### 6. ログアウト

```php
$auth->logout();
```

## 主要コンポーネント

### Auth

- `AuthService`
- `LoginCredentials`
- `LoginAttempt`
- `LoginUser`
- `LoginGuardInterface`
- `LoginFailure`
- `LoginFailureReason`
- `UserRepositoryInterface`

### Database

- `Database`
- `PdoConnection`

### Support

- `SessionHelper`
- `CookieHelper`
- `Utility`

### Logging

- `Logger`

## 認証の考え方

Chandra の認証は、以下の2つを分離して扱います。

- `LoginCredentials`: 資格情報そのもの
- `LoginAttempt`: IP、User-Agent、画面名などの試行コンテキスト

この分離によって、ログイン試行制御や監査ログを実装するときに、パスワード文字列を guard 側へ渡さずに済みます。

## Login Guard

ログイン試行制御を入れたい場合は、`LoginGuardInterface` を実装します。

`noblestock` では、`LoginRateLimitGuard` が次の責務を持っています。

- ログイン前に、アカウント単位 / IP 単位のロック状態を確認する
- ログイン失敗時に失敗履歴を記録する
- 一定回数を超えたらロックを作成する
- ログイン成功時に失敗履歴やロックを解除する

最小形は次のようになります。

```php
use Studiogau\Chandra\Auth\AuthException;
use Studiogau\Chandra\Auth\LoginAttempt;
use Studiogau\Chandra\Auth\LoginFailure;
use Studiogau\Chandra\Auth\LoginFailureReason;
use Studiogau\Chandra\Auth\LoginGuardInterface;
use Studiogau\Chandra\Auth\LoginUser;

final class LoginRateLimitGuard implements LoginGuardInterface
{
    public function assertCanAttempt(LoginAttempt $attempt): void
    {
        // ロック中なら AuthException を投げる
    }

    public function recordSuccessfulLogin(LoginAttempt $attempt, LoginUser $user): void
    {
        // 失敗回数クリア、ロック解除など
    }

    public function recordFailedLogin(LoginAttempt $attempt, LoginFailure $failure): void
    {
        if ($failure->getReason() !== LoginFailureReason::INVALID_CREDENTIALS) {
            return;
        }

        // 通常の認証失敗だけ回数加算する
    }
}
```

guard を渡さなければ、ログイン試行制御なしのシンプルな認証として動作します。  
guard を渡せば、アプリ側が意図したログイン試行制御を同じ `login()` API の中に組み込めます。

## DB アクセス

Chandra は DB 操作のための共通基盤を提供します。  
アプリ側では model / repository を実装し、その中で `Database` を利用する構成を想定しています。

主な用途:

- 単一レコード取得
- 一覧取得
- `insert` / `update` / `delete`
- トランザクション制御

## Session / Cookie / CSRF

Chandra は画面系アプリでよく使う補助機能を提供します。

- `SessionHelper`: セッションへの値保存、ユーザー保存、補助データ管理
- `CookieHelper`: Cookie の補助処理
- `Utility`: CSRF トークン発行・検証など

これにより、認証後のユーザー維持やフォーム保護をアプリ側からシンプルに扱えます。

## ロギング

`Logger` は認証失敗やシステムエラーなどの共通ログ出力に利用できます。  
`AuthService` には任意の `Logger` を渡せるため、アプリ側でログ方針を調整できます。

```php
$auth = new AuthService(new UserRepository(), $logger);
```

`noblestock` では、アプリルートを基準にした既定ロガーを使っています。

```php
$logger = Logger::createDefault(dirname(__DIR__, 1));
```

## テスト

PHPUnit によるテストが含まれています。

```bash
vendor/bin/phpunit
```

認証、セッション、Cookie、CSRF、DB などの基本動作をテストしています。

## 設計方針

- 薄い共通基盤に徹する
- 業務ロジックはアプリ側に寄せる
- interface で差し替えやすくする
- 必要以上にフレームワーク化しない
- 現場の PHP 業務アプリに乗せやすい形を優先する

## 制約

- 2FA は標準では提供しません
- ログイン試行制限は guard 実装前提です
- ルーティングや DI コンテナのようなフルスタック機能は持ちません

## ライセンス

MIT
