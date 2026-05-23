# 案款不明款功能设计

## 需求结论

在案款业务中新增“不明款”菜单。该菜单只在案款账套 `CASE_FUND` 中显示。系统根据 `不明款查询 (4).xls` 的内容新增独立列表、Excel 导入、生成凭证、科目配置和删除能力，功能形态参照现有“缴费登记查询”，但数据单独落入不明款表，不混用案款缴费登记表。

导入样例表头固定为：

`法院名称、认领状态、缴款人、金额、银行摘要、银行备注、交易日期、法院银行账户、到账方式、缴款人开户行、缴款人账号、认领人姓名、认领日期、最后修改时间`

凭证摘要固定使用：

`收 不明款 缴款人 银行备注`

当银行备注为空时，使用银行摘要补位；两者都为空时摘要为 `收 不明款 缴款人`。
样例 Excel 中存在缴款人为空但银行备注和到账账号可追溯的记录，因此导入校验不强制缴款人必填；摘要在缴款人为空时自然省略该段，例如 `收 不明款 21050144000600000799-0001`。

## 一、财务专业业务规则

1. 不明款属于已到账但尚未完成案号、收据号或业务归属确认的暂收款，不允许伪造成普通缴费登记，也不允许强行写入案号辅助核算。
2. 不明款生成凭证时必须遵守双分录规则：借方记录银行存款或实际到账科目，贷方记录不明款、其他应付款或指定过渡科目。
3. 凭证生成必须借贷相等，金额按整数分计算，不允许使用 PHP 浮点数直接比较。
4. 不明款记录初始制证状态为 `UNGENERATED`。生成凭证后改为 `GENERATED`，并记录 `voucher_id`、`voucher_no`、`voucher_period`、`voucher_generated_time`。
5. 已生成凭证的不明款不允许重复生成；当原凭证被软删除或缺失时，允许参照缴费登记的现有规则重新生成。
6. 删除规则参照案款业务现有内控：只能删除未制证且未对账的不明款记录，后端强制校验，前端按钮只做体验优化。
7. 不明款菜单、路由和接口只允许案款账套 `CASE_FUND` 使用。其他账套即使直接请求接口，也应返回“不支持不明款业务”的错误。
8. 写操作必须记录审计日志，业务类型建议使用 `CASE_FUND_UNKNOWN_PAYMENT`。

## 二、数据库表结构

新增表：`fin_case_fund_unknown_payment`。

字段设计：

| 字段 | 类型 | 说明 |
|---|---|---|
| `unknown_payment_id` | `varchar(36)` | 主键 UUID |
| `account_set_id` | `varchar(36)` | 账套 ID |
| `fiscal_year` | `int` | 年度，来自交易日期 |
| `period` | `varchar(7)` | 会计期间，格式 `YYYY-MM` |
| `court_name` | `varchar(100)` | 法院名称 |
| `claim_status` | `varchar(30)` | 认领状态 |
| `payer_name` | `varchar(100)` | 缴款人 |
| `unknown_amount` | `decimal(18,2)` | 金额 |
| `bank_summary` | `varchar(255)` | 银行摘要 |
| `bank_remark` | `varchar(255)` | 银行备注 |
| `transaction_date` | `date` | 交易日期 |
| `transaction_time` | `datetime` | 交易时间 |
| `court_bank_account_no` | `varchar(80)` | 法院银行账户 |
| `arrival_method` | `varchar(50)` | 到账方式 |
| `payer_bank_name` | `varchar(160)` | 缴款人开户行 |
| `payer_account_no` | `varchar(80)` | 缴款人账号 |
| `claim_user_name` | `varchar(80)` | 认领人姓名 |
| `claim_date` | `date null` | 认领日期 |
| `last_modified_time` | `datetime null` | 最后修改时间 |
| `source_file_name` | `varchar(255)` | 来源文件名 |
| `source_row_no` | `int` | Excel 原始行号 |
| `source_fingerprint` | `varchar(64)` | 导入去重指纹 |
| `source_raw_json` | `text` | 原始行 JSON |
| `voucher_status` | `varchar(30)` | `UNGENERATED`、`GENERATED`、`VOIDED` |
| `voucher_id` | `varchar(36) null` | 凭证 ID |
| `voucher_no` | `int null` | 凭证号 |
| `voucher_period` | `varchar(7) null` | 凭证期间 |
| `voucher_generated_time` | `datetime null` | 制证时间 |
| `created_by` | `varchar(36)` | 创建人 |
| `created_time` | `datetime` | 创建时间 |
| `updated_by` | `varchar(36)` | 更新人 |
| `updated_time` | `datetime` | 更新时间 |
| `del_flag` | `tinyint` | 软删除标记 |
| `version` | `int` | 版本号 |
| `remark` | `varchar(255)` | 备注 |

索引：

1. 主键：`unknown_payment_id`
2. 唯一索引：`uk_case_fund_unknown_payment_source (account_set_id, source_fingerprint, del_flag)`
3. 查询索引：`idx_case_fund_unknown_payment_period (account_set_id, period, transaction_date)`
4. 账户索引：`idx_case_fund_unknown_payment_bank (account_set_id, court_bank_account_no, transaction_date)`
5. 缴款人索引：`idx_case_fund_unknown_payment_payer (account_set_id, payer_name)`
6. 制证索引：`idx_case_fund_unknown_payment_voucher (account_set_id, voucher_status, voucher_period, voucher_id)`

同步更新：

1. `backend/database/court_finance_ddl.sql`
2. `backend/database/finance_standard_patch.sql`
3. `backend/database/migration_rbac.sql`

## 三、业务流转流程

导入流程：

1. 用户在“不明款”页面选择 `.xls` 文件。
2. 前端将文件转为 base64，调用 `/caseFund/unknownPaymentImport`。
3. 后端解析 Excel 表头，校验必填字段。
4. 后端按交易日期生成年度和期间，金额统一转为两位小数。
5. 后端按 `交易时间 + 金额 + 法院银行账户 + 缴款人账号 + 缴款人 + 银行备注 + 银行摘要` 生成指纹。
6. 已存在相同指纹的数据跳过，未存在的数据插入。
7. 审计日志记录导入文件名、总行数、新增数、跳过数。

查询流程：

1. 页面默认按当前期间展开日期范围。
2. 支持快捷日期：今天、本周、本月、本年。
3. 支持交易日期起止、制证状态、认领状态、关键字查询。
4. 关键字匹配缴款人、银行摘要、银行备注、法院银行账户、缴款人开户行、缴款人账号。
5. 缴款人为空的记录允许导入，但必须具备银行摘要、银行备注、法院银行账户或缴款人账号中的追溯信息。

制证流程：

1. 用户选择不明款记录，点击“生成凭证”。
2. 后端校验当前账套为 `CASE_FUND`。
3. 后端校验选中记录可制证，已制证且原凭证仍有效时拒绝重复生成。
4. 后端读取 `UNKNOWN_PAYMENT` 科目配置并校验借贷科目均为启用、末级、允许录入凭证。
5. 若开启“按天生成凭证”，同一交易日期生成一张凭证；否则每条不明款生成一张凭证。
6. 凭证明细按每条不明款生成一借一贷。
7. 借贷金额相等后写入 `fin_voucher`、`fin_voucher_detail`。
8. 更新不明款记录的制证状态和凭证信息。
9. 写审计日志。

删除流程：

1. 用户选择不明款记录，点击删除。
2. 后端只允许 `voucher_status = UNGENERATED` 且未被对账记录引用的数据软删除。
3. 删除只更新 `del_flag = 1`，不物理删除。
4. 写审计日志。

## 四、后端接口清单

新增接口均走现有统一入口 `action=/caseFund/<method>`。

| 接口 | 权限 | 说明 |
|---|---|---|
| `/caseFund/unknownPaymentList` | `case_fund:view` | 查询不明款列表 |
| `/caseFund/unknownPaymentImport` | `case_fund:import` | 导入不明款 Excel |
| `/caseFund/unknownPaymentGenerateVoucher` | `case_fund:generate_voucher` | 不明款生成凭证 |
| `/caseFund/unknownPaymentDelete` | `case_fund:delete` | 删除未制证且未对账不明款 |
| `/caseFund/subjectConfigList` | `case_fund:subject_config` | 增加 `voucher_biz_type = UNKNOWN_PAYMENT` |
| `/caseFund/subjectConfigSave` | `case_fund:subject_config` | 保存不明款借贷科目 |

`unknownPaymentList` 请求字段：

| 字段 | 说明 |
|---|---|
| `date_start` | 交易开始日期 |
| `date_end` | 交易结束日期 |
| `voucher_status` | 制证状态 |
| `claim_status` | 认领状态 |
| `keyword` | 关键字 |
| `page` | 页码 |
| `pagesize` | 每页行数 |

`unknownPaymentImport` 请求字段：

| 字段 | 说明 |
|---|---|
| `filename` | 文件名 |
| `content_base64` | Excel 文件 base64 |

`unknownPaymentGenerateVoucher` 请求字段：

| 字段 | 说明 |
|---|---|
| `unknown_payment_ids` | 不明款 ID 数组 |

返回格式沿用项目标准：成功 `code=20000`，失败 `code=0`。

## 五、枚举、权限与基础档案设计

新增凭证业务类型：

| 值 | 说明 |
|---|---|
| `UNKNOWN_PAYMENT` | 不明款 |

不明款记录状态：

| 值 | 说明 |
|---|---|
| `UNGENERATED` | 未生成凭证 |
| `GENERATED` | 已生成凭证 |
| `VOIDED` | 已作废 |

新增菜单权限：

| 权限码 | 名称 | 路由 |
|---|---|---|
| `menu:case_fund:unknown_payment` | 不明款 | `/case-fund/unknown-payments` |

操作权限沿用：

| 权限码 | 用途 |
|---|---|
| `case_fund:view` | 查看列表 |
| `case_fund:import` | 导入 Excel |
| `case_fund:generate_voucher` | 生成凭证 |
| `case_fund:subject_config` | 科目配置 |
| `case_fund:delete` | 删除 |

辅助核算：

1. 不明款不强制生成案号、收据号辅助档案。
2. 若配置的借方或贷方科目挂有必填辅助核算，应拒绝制证并提示该科目不适合直接用于不明款批量制证。
3. 后续若需要对不明款做认领转案号，应另行设计认领流转和辅助核算补录，不在本次范围内。

## 六、前端设计

新增页面：

`frontend/src/views/case-fund/UnknownPaymentView.vue`

页面行为参照 `PaymentRegisterView.vue`：

1. 顶部筛选：快捷日期、交易日期起止、制证状态、认领状态、关键字、查询按钮。
2. 操作区：生成凭证、更多功能。
3. 更多功能：删除、科目配置、导入不明款。
4. 汇总区：登记笔数、本页金额、未制证。
5. 表格列：交易日期、认领状态、缴款人、金额、银行摘要、银行备注、法院银行账户、到账方式、缴款人开户行、缴款人账号、认领人姓名、认领日期、制证状态、凭证。
6. 菜单只在 `context.bizType === 'CASE_FUND'` 且用户有 `menu:case_fund:unknown_payment` 时显示。

同步更新：

1. `frontend/src/api/caseFund.ts` 新增 API wrapper。
2. `frontend/src/types/api.ts` 新增 `CaseFundUnknownPayment` 类型，并允许 `CaseFundSubjectConfig.voucher_biz_type` 包含 `UNKNOWN_PAYMENT`。
3. `frontend/src/router/index.ts` 新增路由。
4. `frontend/src/App.vue` 新增菜单、页签标题、案款菜单权限数组。
5. `frontend/src/views/dashboard/DashboardView.vue` 新增案款快捷入口。

## 七、测试与验证

后端测试：

1. 新增 `backend/tests/CaseFundUnknownPaymentImportTest.php`，断言样例 Excel 可解析 37 行、首行字段正确、金额合计正确、指纹稳定、非法账套类型拒绝。
2. 新增 `backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php`，断言路由、权限、事务、凭证表写入、摘要 `收 不明款 王雪梅 账号不存在账号错误`、已制证重复生成拦截、原凭证删除后可重新生成。
3. 更新 `backend/tests/CaseFundSubjectConfigTest.php`，覆盖 `UNKNOWN_PAYMENT` 科目配置。

前端测试：

1. 更新 `frontend/tests/CaseFundMenuTest.mjs`，断言新增菜单、路由、标题、RBAC 种子。
2. 新增 `frontend/tests/CaseFundUnknownPaymentViewTest.mjs`，断言页面具备导入、查询、生成凭证、科目配置、删除、制证状态和样例字段列。
3. 更新 `frontend/tests/CaseFundSubjectConfigTest.mjs`，断言不明款科目配置页面使用 `UNKNOWN_PAYMENT`。

完整验证命令：

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentImportTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundUnknownPaymentGenerateVoucherTest.php
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/CaseFundSubjectConfigTest.php
node frontend/tests/CaseFundMenuTest.mjs
node frontend/tests/CaseFundUnknownPaymentViewTest.mjs
node frontend/tests/CaseFundSubjectConfigTest.mjs
cd frontend && npm run build
```

## 八、实现边界

本次只实现“不明款”的导入、列表、制证、科目配置和删除。以下能力不在本次范围内：

1. 不明款认领后自动转入普通缴费登记。
2. 不明款与银行流水自动对账闭环。
3. 不明款退款或冲销流程。
4. 不明款账龄分析和专项报表。

这些能力后续应单独按财务规则设计，不能在本次实现中用备注字段或状态字段临时拼凑。
