# Chandra

Chandra は、業務アプリや CMS を PHP で構築するための軽量フレームワークです。

フルスタックフレームワークのように全体を囲い込むのではなく、認証、セッション、DB、CSRF、Cookie、ロギングといった共通基盤を提供し、アプリ側が業務ロジックをシンプルに実装できることを目指しています。

## こんなプロジェクトに向いています

- 画面遷移を基本とするサーバーサイドレンダリング型のシンプルなWebアプリプロジェクト
- 認証やセッションは共通化しながら、業務ロジックを自由高く実装したいプロジェクト
- 小さく始めて、必要な機能を自分で組み立てたいプロジェクト

## 機能

- ログイン、ログアウト、認可を扱う認証基盤
- MySQL を利用したデータベース操作とトランザクション
- セッション、Cookie、CSRF 対策のヘルパー
- アプリケーションログの出力
- Composer と PSR-4 に対応したシンプルな構成

## インストール

Composer でインストールできます。

```bash
composer require studiogau/chandra
```

## はじめての Chandra

Chandra をインストールして最小構成のページを表示するところから始める場合は、[クイックスタート](https://learnchi.github.io/chandra/quickstart.html)をご覧ください。

XAMPP と Composer の準備から、HTML 出力エスケープやロガーの動作確認までを順番に試せます。

## ドキュメント

- [クイックスタート](https://learnchi.github.io/chandra/quickstart.html)

  インストールから最初の画面表示まで、短い手順で試します。
- [ハンズオン](https://learnchi.github.io/chandra/handson.html)

  サンプルプロジェクトを作りながら、DB操作、CRUD、認証、テストを試します。
- [リファレンス](https://learnchi.github.io/chandra/reference.html)
  
  クラス、メソッド、設定項目を機能別に確認します。

## 動作環境

- PHP 8.2 以上
- MySQL 8.0 以上
- Apache 2.4 以上
- Composer 2.6 系以上

## ライセンス

MIT
