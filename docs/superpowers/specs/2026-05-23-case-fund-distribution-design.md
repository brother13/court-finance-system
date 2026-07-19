# 案款分配功能设计

## 背景和目标

在案款业务中新增“案款分配”菜单，根据 `分配清单(带单据号) (3).xls` 导入、展示并生成凭证。案款分配不是新收款，也不是对外退款，而是把缴费登记中已经入账的一笔案款，按法官分配结果从原案号转移到其他案号。财务处理必须保持银行存款不变，只在案款暂存负债科目的辅助核算维度中做内部转移。

菜单和接口只对案款账套 `CASE_FUND` 开放。诉讼费账套暂不接入，因为诉讼费不存在案号和单据号辅助核算，不适合使用本功能的辅助核算转移模型。

## Excel 字段

样表 `分配清单(带单据号) (3).xls` 只有一个工作表 `分配清单(带单据号)`，共 32 条业务数据。字段如下：

1. `序号`
2. `分配单号`
3. `转入案号`
4. `转入承办法官`
5. `转入当事人`
6. `转出案号`
7. `转出承办法官`
8. `转出当事人`
9. `开票种类`
10. `单据号`
11. `分配金额`
12. `申请人`
13. `申请日期`
14. `接收日期`
15. `状态`
16. `情况说明`
17. `原开票日期`

样表中 `开票种类` 全部为 `执行、调解款`，状态全部为 `正常可操作`。

## 财务业务规则

1. 案款分配只允许在案款账套 `CASE_FUND` 中导入、查询和生成凭证。
2. `开票种类` 必须匹配案款账套允许的款项类型。当前只允许 `执行、调解款`，不兼容历史别字 `执行、调节款`。
3. 清单提供 `单据号` 时，它表示原收据号，分配后仍保持不变；相关科目挂载 `receipt_no` 时，生成凭证辅助核算继续写入该值。
4. `分配单号` 是分配业务单据号，只用于列表、导入去重、凭证分组和审计追踪，不替代收据号。
5. 一条缴费登记可能被分配到多条转入案号，系统不要求分配清单与原缴费登记一一对应；必须保留 `转出案号 + 分配金额`，存在单据号时一并保留，以便后续核销追踪。
6. 有分配单号时按 `分配单号` 合并生成凭证；分配单号为空时每条分配记录单独生成一张凭证。
7. 分录方向为同一案款暂存负债科目的辅助核算内部转移：

```text
借：案款暂存/执行、调解款    辅助：转出案号 + 按科目配置决定是否带Excel单据号    金额
贷：案款暂存/执行、调解款    辅助：转入案号 + 按科目配置决定是否带Excel单据号    金额
```

8. 该凭证不影响银行存款，不新增收款，不新增退款。
9. 借贷科目通常为同一个负债类末级科目，例如 `220101 案款暂存`。系统通过科目配置保存借方科目和贷方科目，允许配置为同一科目。
10. 参与自动生成凭证的科目必须是启用、末级、允许录入凭证的科目。
11. 借方和贷方科目必须挂载案号 `case_no`；`receipt_no` 是否挂载由当前账套科目配置决定。凭证只写入科目实际配置的辅助核算维度。
12. 生成凭证后，分配记录状态改为 `GENERATED`，保存 `voucher_id`、`voucher_no`、`voucher_period`、`voucher_generated_time`。
13. 已制证记录如果原凭证仍有效，禁止重复生成；如果原凭证被删除或缺失，允许重新生成。
14. 只能删除未制证且未对账的分配记录，后端强制校验，前端只做体验提示。
15. 单据号是否必填以后端当前账套的案款分配借、贷科目配置为准：任一相关科目挂载 `receipt_no` 时，继续要求带单据号完整格式；只有已取得有效科目配置且借、贷科目均未挂载 `receipt_no` 时，才允许导入无单据号格式。无单据号格式最少要求转入案号、转出案号、开票种类、分配金额和接收日期；`承办法官` 按转入承办法官读取，分配单号允许为空且制证时按行分别生成凭证。
16. 导入、删除、生成凭证、保存科目配置都必须写审计日志。

## 凭证摘要

推荐摘要使用分配方向、案号和当事人，保证总账和明细账可读：

```text
借方：分配 分配单号 转出 转出案号 转出当事人
贷方：分配 分配单号 转入 转入案号 转入当事人
凭证头：案款分配 分配单号xxx
```

若同一分配单号内有多条分配明细，凭证头摘要仍取 `案款分配 分配单号xxx`。

## 数据库设计

新增业务表 `fin_case_fund_distribution`，主键 UUID：

| 字段 | 类型 | 说明 |
|---|---|---|
| `distribution_id` | varchar(36) | 主键 |
| `account_set_id` | varchar(36) | 账套 ID |
| `fiscal_year` | int | 年度，按接收日期所属年度 |
| `period` | varchar(7) | 会计期间，按接收日期所属期间 |
| `source_row_no` | int | Excel 来源行号 |
| `source_file_name` | varchar(255) | 来源文件名 |
| `source_fingerprint` | varchar(64) | 去重指纹 |
| `distribution_no` | varchar(80) | 分配单号 |
| `in_case_no` | varchar(120) | 转入案号 |
| `in_judge_name` | varchar(80) | 转入承办法官 |
| `in_party_name` | varchar(200) | 转入当事人 |
| `out_case_no` | varchar(120) | 转出案号 |
| `out_judge_name` | varchar(80) | 转出承办法官 |
| `out_party_name` | varchar(200) | 转出当事人 |
| `invoice_type` | varchar(100) | 开票种类 |
| `receipt_no` | varchar(80) | Excel 单据号，作为辅助核算收据号 |
| `distribution_amount` | decimal(18,2) | 分配金额 |
| `applicant_name` | varchar(80) | 申请人 |
| `apply_date` | date | 申请日期 |
| `receive_date` | date | 接收日期 |
| `business_status` | varchar(50) | 状态 |
| `description` | varchar(255) | 情况说明 |
| `original_invoice_date` | date | 原开票日期 |
| `voucher_status` | varchar(30) | `UNGENERATED` / `GENERATED` / `VOIDED` |
| `voucher_id` | varchar(36) | 生成凭证 ID |
| `voucher_no` | int | 凭证号 |
| `voucher_period` | varchar(7) | 凭证期间 |
| `voucher_generated_time` | datetime | 制证时间 |
| `source_raw_json` | text | 原始行 JSON |
| `created_by` / `created_time` | | 创建审计 |
| `updated_by` / `updated_time` | | 更新审计 |
| `del_flag` | tinyint | 软删除标记 |
| `version` | int | 乐观锁版本 |
| `remark` | varchar(255) | 备注 |

索引：

1. 主键：`distribution_id`
2. 去重唯一索引：`account_set_id + source_fingerprint + del_flag`
3. 查询索引：`account_set_id + period + receive_date`
4. 分配单索引：`account_set_id + distribution_no`
5. 转入案号索引：`account_set_id + in_case_no`
6. 转出案号索引：`account_set_id + out_case_no`
7. 凭证状态索引：`account_set_id + voucher_status + voucher_period + voucher_id`

`source_fingerprint` 使用 `分配单号 + 转入案号 + 转出案号 + 开票种类 + 单据号 + 分配金额 + 接收日期` 生成，避免重复导入同一行。

## 科目配置

复用 `fin_case_fund_subject_config`：

1. `biz_type = CASE_FUND`
2. `voucher_biz_type = DISTRIBUTION`
3. `business_item_type = 开票种类`
4. 样表中业务项为 `执行、调解款`

前端科目配置页显示“案款分配科目配置”。配置项按当前账套允许的分配开票种类生成。借方科目和贷方科目都可选择同一个案款暂存科目。

保存时校验：

1. 业务项必须属于案款分配允许的 `开票种类`。
2. 借方/贷方科目不能为空。
3. 借方/贷方科目必须存在、启用、末级、允许录入凭证。
4. 生成凭证前再次校验科目挂载的辅助核算，至少必须支持 `case_no` 和 `receipt_no`。

## 后端接口

模块仍为 `caseFund`：

| 接口 | 权限 | 说明 |
|---|---|---|
| `/caseFund/distributionList` | `case_fund:view` | 分页查询案款分配 |
| `/caseFund/distributionImport` | `case_fund:import` | 导入 Excel |
| `/caseFund/distributionDelete` | `case_fund:delete` | 删除未制证且未对账数据 |
| `/caseFund/distributionGenerateVoucher` | `case_fund:generate_voucher` | 按分配单号生成凭证 |
| `/caseFund/subjectConfigList` | `case_fund:subject_config` | 扩展支持 `DISTRIBUTION` |
| `/caseFund/subjectConfigSave` | `case_fund:subject_config` | 保存分配科目配置 |

所有接口必须：

1. 校验当前账套 `biz_type = CASE_FUND`。
2. 查询条件带 `account_set_id`、`fiscal_year`、`del_flag = 0`。
3. 写操作使用事务。
4. 写操作记录 `sys_audit_log`。

## 列表页面

新增页面 `frontend/src/views/case-fund/DistributionView.vue`，菜单文案为“案款分配”。

筛选项：

1. 快捷日期
2. 接收日期开始/结束
3. 制证状态
4. 开票种类
5. 关键词：分配单号、转入案号、转出案号、当事人、单据号

表格列：

1. 接收日期
2. 分配单号
3. 单据号
4. 开票种类
5. 转出案号
6. 转出当事人
7. 转入案号
8. 转入当事人
9. 分配金额
10. 状态
11. 情况说明
12. 制证状态
13. 凭证号

操作：

1. 导入案款分配
2. 生成凭证
3. 科目配置
4. 删除

行选择规则：

1. 生成凭证可选择 `UNGENERATED`，以及原凭证被删除或缺失的 `GENERATED` 记录。
2. 删除只能选择 `UNGENERATED` 记录。

## 菜单和权限

新增菜单权限：

1. `menu:case_fund:distribution`
2. 菜单标题：`案款分配`
3. 路由：`/case-fund/distributions`
4. 只在 `context.bizType === 'CASE_FUND'` 时显示。

操作权限复用案款业务已有权限：

1. `case_fund:view`
2. `case_fund:import`
3. `case_fund:generate_voucher`
4. `case_fund:subject_config`
5. `case_fund:delete`

## 业务流程

### 导入流程

1. 用户进入“案款分配”页面。
2. 选择 `.xls` 文件导入。
3. 后端解析 Excel。
4. 校验固定必填字段：转入案号、转出案号、开票种类、分配金额、接收日期；仅在相关科目挂载 `receipt_no` 时额外要求分配单号和单据号。
5. 校验金额大于 0。
6. 校验接收日期合法，并换算年度和会计期间。
7. 校验开票种类属于案款账套允许类型。
8. 生成去重指纹，跳过已存在记录。
9. 保存到 `fin_case_fund_distribution`，初始 `voucher_status = UNGENERATED`。
10. 写导入审计日志。

### 生成凭证流程

1. 用户勾选分配记录并点击生成凭证。
2. 后端校验权限和账套类型。
3. 事务内锁定所选分配记录。
4. 若查回数量与选择数量不一致，整体失败。
5. 校验记录未制证，或已制证但原凭证已删除/缺失。
6. 有 `distribution_no` 时按单号分组；无单号时按记录分组，一组一张凭证。
7. 每组内按 `receive_date asc, source_row_no asc` 排序生成分录。
8. 确保转入案号、转出案号辅助档案存在；科目配置单据号辅助核算时再确保单据号档案存在。
9. 生成凭证头，状态 `SUBMITTED`，来源 `BUSINESS`。
10. 每行生成两条凭证明细：借方转出、贷方转入。
11. 写 `fin_voucher_aux_value`：借方明细写 `out_case_no`，贷方明细写 `in_case_no`；科目配置 `receipt_no` 时再分别追加该辅助值。
12. 更新分配记录为 `GENERATED`。
13. 写生成凭证审计日志。

### 删除流程

1. 用户勾选未制证分配记录。
2. 后端校验记录属于当前账套、当前年度、未制证、未对账。
3. 执行软删除 `del_flag = 1`。
4. 写删除审计日志。

## 测试计划

后端测试：

1. `CaseFundDistributionImportTest.php`
   - 解析样表 32 行。
   - 验证字段映射、金额合计、开票种类。
   - 验证必填、金额、日期、账套类型校验。
   - 验证去重指纹包含分配单号、转入案号、转出案号、单据号和金额。
2. `CaseFundDistributionGenerateVoucherTest.php`
   - 验证接口路由、权限、`DISTRIBUTION` 科目配置。
   - 验证摘要格式。
   - 验证按分配单号合并。
   - 验证借方为转出案号、贷方为转入案号，辅助核算都使用 Excel 单据号。
   - 验证已制证有效凭证阻断、原凭证删除可重制。
   - 验证缺失 ID 不静默成功。
3. 更新 `CaseFundSubjectConfigTest.php`
   - 覆盖 `DISTRIBUTION` 和 `执行、调解款`。
4. 更新 `CaseFundDeleteGuardTest.php`
   - 覆盖分配删除。

前端测试：

1. 更新 `CaseFundMenuTest.mjs`
   - 菜单、路由、标题、RBAC、`CASE_FUND` 显示限制。
2. 更新 `CaseFundDeleteActionsTest.mjs`
   - 分配删除按钮、权限、确认、未制证限制。
3. 新增或扩展分配页面测试
   - API wrapper、导入、生成凭证、科目配置调用。

构建验证：

1. PHP 语法检查新增/修改测试和模型。
2. 运行相关 PHP 测试。
3. 运行相关前端 Node 测试。
4. `cd frontend && npm run build`。

## 方案取舍

考虑过三种凭证策略：

1. 走“缴费登记”同样的借银行、贷案款暂存。
   - 不采用。案款分配没有真实银行流水，会虚增银行发生额。
2. 走“退付”同样的借案款暂存、贷银行。
   - 不采用。案款分配不是对外付款，会虚减银行存款。
3. 同一案款暂存科目的辅助核算内部转移。
   - 采用。它准确表达转出案号减少、转入案号增加，银行余额不变，也保留 `案号 + 单据号` 的核销链路。

## 明确不做

1. 不把案款分配开放给诉讼费账套。
2. 不用分配单号替代 Excel 单据号。
3. 不把分配记录混入缴费登记表。
4. 不自动修改原缴费登记记录金额。
5. 不在通用凭证明细表新增案号、单据号等业务字段，仍通过辅助核算值表表达。
