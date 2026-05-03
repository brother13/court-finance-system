# 一期实现说明

## 已确认约束

1. 后端使用 PHP 7.4.33，参照 `/Applications/MAMP/htdocs/cccf` 的 ThinkPHP 5 单入口架构。
2. 每个法院单独部署，不支持多单位模式，业务表不再保存 `customer_id`。
3. 系统仍支持多账套，使用 `account_set_id` 做账套隔离。
4. 所有主键坚持使用 UUID 字符串。
5. 凭证、凭证明细、凭证辅助核算值采用年度分表，例如 `fin_voucher_2026`。
6. 一期先完成凭证和账簿闭环。
7. 系统预置案款账套模板。
8. 已审核凭证允许重排，已打印凭证不允许重排；重排功能预留到后续实现。
9. 拥有审核权限即可反审核，一期不引入审批流。
10. 外部业务流水按 Excel 格式导入，接口预留到二期。
11. 往来核销按“案号 + 收据号”匹配，收据号允许为空，完整核销闭环放到二期。
12. 一期 SQL 先保证风格可迁移，不使用强绑定数据库方言。

## 架构风格

后端采用 ThinkPHP 5 风格：

```text
backend/
  public/index.php
  app/config.php
  app/database.php
  app/finance/controller/Index.php
  app/finance/model/Common.php
  app/finance/model/Voucher.php
  app/finance/model/Book.php
  app/finance/model/Subject.php
  app/finance/model/Aux.php
  app/finance/model/Log.php
```

API 保持 `cccf` 项目的单入口分发方式：

```text
POST /?action=/voucher/list&account_set_id=...
POST /?action=/voucher/submit&account_set_id=...
```

请求业务参数放在 `data` 中，JSON 字符串或表单字段都可兼容。

## 一期闭环

```text
科目/辅助核算配置 -> 凭证录入 -> 借贷平衡校验 -> 辅助核算校验
-> 提交审核 -> 审计留痕 -> 明细账/余额表
```

## 关键规则

- 请求不需要 `customer_id`。
- 请求需要携带 `account_set_id`，默认可使用 `app/finance/config.php` 中的案款账套。
- 凭证保存前强校验借贷平衡。
- 金额比较使用“分”为单位的整数换算，避免 PHP 浮点误差。
- 科目启用的必填辅助核算缺失时拒绝保存。
- 凭证明细表不包含案号、收据号等业务字段。
- 辅助核算值统一写入 `fin_voucher_aux_value_YYYY`。
- 保存凭证明细时同步写入 `aux_desc`，用于列表和账簿查询。
- 凭证号通过 `fin_voucher_no_sequence` 加行锁生成，避免并发重号。
- 写操作记录 `sys_audit_log`。

## 一期 Action 清单

| Action | 说明 |
|---|---|
| `/subject/list` | 科目列表 |
| `/subject/add` | 新增科目 |
| `/subject/save` | 修改科目 |
| `/subject/del` | 逻辑删除科目 |
| `/aux/typeList` | 辅助核算类型 |
| `/aux/archiveList` | 辅助档案列表 |
| `/aux/archiveAdd` | 新增辅助档案 |
| `/aux/subjectConfig` | 科目辅助核算配置 |
| `/voucher/list` | 凭证分页 |
| `/voucher/info` | 凭证详情 |
| `/voucher/draft` | 保存草稿 |
| `/voucher/submit` | 提交审核 |
| `/voucher/audit` | 审核 |
| `/voucher/unaudit` | 反审核 |
| `/voucher/void` | 作废 |
| `/voucher/printMark` | 标记打印 |
| `/book/detailLedger` | 明细账 |
| `/book/subjectBalance` | 科目余额表 |
| `/period/list` | 会计期间 |
| `/log/list` | 审计日志 |

## 数据库脚本

- `backend/database/court_finance_ddl.sql`
- `backend/database/preset_case_fund_template.sql`

先执行 DDL，再执行预置模板。
