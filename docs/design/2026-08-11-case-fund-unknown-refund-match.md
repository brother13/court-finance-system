# 不明款退汇识别设计

## 1. 财务业务规则

1. 退汇识别是对不明款与原出账记录的业务关联，不修改不明款认领状态、原出账状态或凭证状态。
2. 自动识别范围限定为同一账套、同一会计年度、同一自然月份。
3. 不明款的缴款人或付款账号任一为空时，不参与匹配，状态为 `INELIGIBLE`。
4. 两要素严格对应：来账金额=出账金额、付款账号=收款账号；缴款人和实际收款人仅作为人工核对证据，不参与候选判断。
5. 账号只去除空白和半角/全角横线；金额以整数分比较。
6. 原出账日期不得晚于不明款到账日期。
7. 只有不明款和出账记录在候选图中双方都唯一时，才能成为 `AUTO_CANDIDATE`；任一侧出现多个候选时均为 `AMBIGUOUS`。
8. 系统识别只生成候选，最终 `CONFIRMED` 必须由有权限的财务人员确认并记录审计日志。
9. 已确认退汇关联的业务数据不允许删除，必须先取消确认。
10. 出账科目配置可启用“已确认退汇的不生成凭证”；启用后，只有关联表状态为 `CONFIRMED` 的出账记录会被排除，疑似、歧义和已排除记录仍按原规则制证。

## 2. 数据结构

新增 `fin_case_fund_unknown_refund_match`，一行表示一组不明款与出账候选关系。主要字段：

- `unknown_payment_id`、`refund_id`：双方业务主键。
- `match_status`、`match_rule`、`candidate_count`：识别结果与规则。
- `match_evidence_json`：匹配时双方姓名、金额、账号、日期、案号和出账单号快照。
- `decision_by`、`decision_time`：人工确认或排除信息。
- 账套、年度、期间、创建更新、软删除和版本字段。

唯一索引为 `account_set_id + unknown_payment_id + refund_id`；另按不明款、出账和期间建立状态查询索引。

账套表 `fin_account_set` 增加 `skip_confirmed_return_voucher_flag`，默认 `0`，作为出账凭证生成的账套级开关。

## 3. 流程

1. 财务人员在不明款页面选择当前期间并点击“识别退汇”。
2. 系统先预览跳过、未匹配、唯一候选、歧义、已确认和已排除数量。
3. 用户确认后，系统在事务内重算并保存候选关系，人工决策不会被重复扫描覆盖。
4. 不明款和出账查询列表从关联表派生“退汇状态”，支持状态筛选。
5. 用户打开匹配详情，对照双方字段后执行确认、排除或取消确认。
6. 所有扫描和人工决策写入 `sys_audit_log`。
7. 出账制证在选择范围、准备阶段和事务执行阶段检查开关及已确认关联；查询生成和勾选生成均排除已确认退汇，事务期间新确认的记录会被阻止制证。

## 4. 后端接口

- `/caseFund/unknownRefundMatchPreview`
- `/caseFund/unknownRefundMatchScan`
- `/caseFund/unknownRefundMatchCandidates`
- `/caseFund/unknownRefundMatchConfirm`
- `/caseFund/unknownRefundMatchReject`
- `/caseFund/unknownRefundMatchCancel`

预览和详情使用 `case_fund:view`；扫描及人工决策使用独立权限 `case_fund:return_match`。

出账科目配置继续使用 `/caseFund/subjectConfigList` 与 `/caseFund/subjectConfigSave`，字段为 `skip_confirmed_return_voucher_flag`。

## 5. 状态字典

- `INELIGIBLE`：资料不完整，不参与匹配。
- `UNMATCHED`：未找到候选。
- `AUTO_CANDIDATE`：双方唯一的疑似退汇。
- `AMBIGUOUS`：任一侧存在多个候选。
- `CONFIRMED`：人工确认退汇。
- `REJECTED`：人工排除。
