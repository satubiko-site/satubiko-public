<?php
// /member/trip/line_config.php
declare(strict_types=1);

// ▼▼▼ ここを設定してください ▼▼▼
// LINE Developers の Messaging API から取得
const LINE_CHANNEL_ACCESS_TOKEN = 'aesbG/SaPPDFAUU7EVfeKyNOSPcvyqKp3EiPURtaWmDUYsd5W83kAkzIJbs8TDiA3u5VLA4uxMTzm32IOP/HQd+pDLBCWqgQNxz9kdGl1vT8ByiJwPZ8I4u5FTe3GOyvc2a43qaGH8dIO5h9csjicgdB04t89/1O/w1cDnyilFU=';
const LINE_CHANNEL_SECRET       = '3787f889c8cd10d3bc30e1ecfc9815e2';

// 下山ボタンの改ざん防止用（任意の長い文字列に置き換え）
const LINE_APP_SECRET           = 'p9Jd!3kQz2#Va8mL0@rT7sX1uW5eY6';
// ▲▲▲ ここまで ▲▲▲

// 通知先グループID（テスト中は同じでOK）
const LINE_SUBMIT_GROUP_ID  = 'Cc6038aef105ca578117f9fb74cc55e39'; // 提出通知
const LINE_SAFETY_GROUP_ID  = 'Cc6038aef105ca578117f9fb74cc55e39'; // 安全確認通知
const LINE_RECRUIT_GROUP_ID = 'Cc6038aef105ca578117f9fb74cc55e39'; // 募集通知
