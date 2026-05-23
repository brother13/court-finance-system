# 法院专项账务记账系统后端

## 技术栈

- PHP 7.4.33
- ThinkPHP 5.0.24 风格
- MySQL 8.0
- MAMP 本地环境

## 架构说明

本项目参照 `/Applications/MAMP/htdocs/cccf`：

- `public/index.php` 为统一入口。
- `app/finance/controller/Index.php` 按 `action` 分发。
- `app/finance/model/*.php` 承载业务逻辑。
- 返回格式沿用 `code=20000` 成功、`code=0` 失败。

每个法院单独部署，不做多单位模式。系统只保留账套隔离：

- `account_set_id`
- `period`
- 年度分表，如 `fin_voucher_2026`

## 初始化数据库

先创建数据库：

```sql
create database court_finance default character set utf8 collate utf8_general_ci;
```

再依次执行：

```text
backend/database/court_finance_ddl.sql
backend/database/preset_case_fund_template.sql
```

数据库连接配置：

```text
backend/app/database.php
```

默认账套配置：

```text
backend/app/finance/config.php
```

## 本地启动

使用 MAMP PHP 7.4.33：

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php -S 127.0.0.1:8080 -t backend/public
```

烟测：

```bash
curl "http://127.0.0.1:8080/?action=/sys/info"
```

## API 调用示例

```bash
curl -X POST "http://127.0.0.1:8080/" \
  -d "action=/voucher/list" \
  -d "account_set_id=00000000-0000-0000-0000-000000000101" \
  -d 'data={"period":"2026-05"}'
```

## 一期已实现模型

- `Subject.php`：科目维护
- `Auxiliary.php`：辅助核算类型、辅助档案、科目辅助配置查询
- `Voucher.php`：草稿、提交、审核、反审核、作废、打印标记
- `Book.php`：明细账、科目余额表
- `Period.php`：会计期间
- `Log.php`：审计日志
