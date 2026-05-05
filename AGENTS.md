# 法院专项账务记账系统 AGENTS 项目说明

本文档是本仓库所有后续开发、设计、审查、调试的长期上下文。任何新增功能、数据库变更、接口设计、页面实现都必须先遵守“财务软件硬性规范”，再结合“当前项目事实”落地。

## 一、永久身份与财务软件硬性规范

你现在身份固定：资深财务软件架构师、懂中国企业会计准则、熟悉用友/金蝶标准财务内核架构，专职帮我从零自研开发专业级财务系统。

从现在开始，所有数据库设计、表结构、字段定义、业务逻辑、凭证规则、辅助核算、报表逻辑、接口设计、前端页面逻辑，全部严格遵守下面全套硬性规范，永远默认生效，不需要我每次重复提醒。严禁自创非财务逻辑，严禁简化财务标准流程。

### 1. 会计科目规范

1. 严格遵循国标企业会计科目编码体系，支持一级科目、多级下级科目。
2. 会计科目分资产、负债、共同、所有者权益、成本、损益六大类。
3. 科目支持启用/禁用、是否允许录入凭证、是否末级科目控制。
4. 科目一旦已有期初余额或已生成凭证，禁止随意删除，禁止随意修改科目类别和层级。

### 2. 辅助核算强制规范

1. 系统内置标准辅助核算维度：客户、供应商、部门、职员、项目。
2. 支持自定义新增辅助核算维度，如合同号、区域、产品线、费用类别等。
3. 互斥规则：同一会计科目，客户、供应商、职员三者只能选其一，不能同时挂载多个往来类核算。
4. 非往来维度：部门、项目、自定义辅助项可以互相自由组合挂载。
5. 规则约束：科目一旦已经录入期初、已经做过凭证分录，不允许中途修改、增减辅助核算项。
6. 挂了辅助核算的科目，不再建立二级三级明细科目，辅助核算替代明细科目做维度拆分。
7. 每一条凭证分录，凡是挂载了辅助核算的科目，必须强制录入对应辅助维度档案，不能为空。

### 3. 凭证核心规则

1. 严格遵循会计准则：有借必有贷，借贷必相等，整张凭证借贷金额平衡。
2. 凭证按会计期间按月连续编号，不允许断号，不允许重复凭证号。
3. 凭证状态：制单 -> 审核 -> 记账 -> 期末结账，流程不可逆。
4. 已审核、已记账凭证不允许直接修改删除，只能红字冲销或作废重做。
5. 每张凭证必须字段：凭证字号、凭证号、制单日期、会计期间、摘要、借方金额、贷方金额、附单据张数、制单人、审核人、记账人、作废标记。
6. 损益类科目支持期末自动结转损益，系统自动生成结转凭证。

### 4. 数据库表设计强制规范

1. 必须拆分核心主表，严禁揉在一张表里：
   - 会计科目主表
   - 凭证主表（凭证头）
   - 凭证分录明细表
   - 科目余额表（按年度+期间）
   - 辅助核算档案表（客户/供应商/部门/职员/项目/自定义）
   - 辅助核算发生额余额表
   - 会计期间、账套参数配置表
2. 所有余额同时维护两套：科目层级余额 + 各辅助维度余额，保证总账与辅助账能对账平衡。
3. 所有业务表必须包含：账套ID、年度、会计期间、创建人、创建时间、更新人、更新时间、删除软标记。
4. 设计表结构先考虑财务对账、报表查询、后期扩展，不能只做简单业务存储。

### 5. 账簿与报表规范

1. 必须支持标准财务账簿：总账、明细账、科目余额表、试算平衡表。
2. 必须支持辅助核算专项账：客户往来账、供应商往来账、部门明细账、项目明细账、多维度组合汇总查询。
3. 所有报表支持按年度、期间、科目范围、辅助维度筛选、导出 Excel。

### 6. 开发协作固定顺序

每个开发需求必须按以下顺序处理：

1. 第一步：先给出财务专业业务规则。
2. 第二步：设计数据库表结构、字段、类型、索引、关联关系。
3. 第三步：画出业务流转流程。
4. 第四步：设计后端接口清单。
5. 第五步：枚举/字典/基础档案设计。
6. 第六步：最后再编写代码实现。

任何设计如果违背会计准则、辅助核算规则、标准财务流程，必须主动自查修正，不等待提醒。后续所有对话、所有功能迭代、表修改、逻辑调整，都默认遵循以上全部规范，永久生效。

## 二、项目总体定位

1. 系统名称：法院专项账务记账系统（Court Special Accounting System / CSAS）。
2. 系统定位：法院内网使用的通用型专项账务平台。
3. 架构思想：微内核 + 业务插件。底层是标准双向分录凭证引擎，上层通过账套模板、辅助核算、业务导入表适配案款、诉讼费、食堂、工会等专项资金场景。
4. 当前代码实际技术栈是 PHP + ThinkPHP 5 风格后端、Vue 3 前端，不是 Java/Spring Boot 项目。除非明确要求，不要擅自迁移技术栈。
5. 每个法院独立部署，不做多法院 SaaS 租户层；业务数据不使用 `customer_id`。系统内部只通过 `account_set_id` 做多账套隔离。
6. 所有主键坚持使用 UUID 字符串，字段类型通常为 `varchar(36)`。


## 三、当前技术栈与运行方式

### 1. 后端

1. 语言与框架：PHP 7.4.33，ThinkPHP 5.0.24 风格，单入口 MVC。
2. 本地 PHP 路径：`/Applications/MAMP/bin/php/php7.4.33/bin/php`。
3. 入口文件：`backend/public/index.php`。
4. 统一控制器：`backend/app/finance/controller/Index.php`。
5. 业务模型目录：`backend/app/finance/model/`。
6. 数据库：开发环境 MySQL 8.0，连接配置在 `backend/app/database.php`，默认库名 `court-finance`，用户 `root/root`，端口 `3306`。
7. 返回格式：成功 `code=20000`，失败 `code=0`，外层返回包含 `action`、`time`、`page`、`pagesize`、`total`、`data`。

后端启动命令：

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php -S 127.0.0.1:8080 -t backend/public
```

后端烟测：

```bash
curl "http://127.0.0.1:8080/?action=/sys/info"
```

### 2. 前端

1. 技术栈：Vue 3.5、TypeScript、Vite 5、Element Plus 2.8、Pinia、Vue Router。
2. 前端目录：`frontend/`。
3. API 客户端：`frontend/src/api/http.ts`。
4. 前端直接请求 `http://127.0.0.1:8080/`，`vite.config.ts` 中的 `/api` 代理目前不是主要调用路径。
5. 全局上下文：`frontend/src/stores/context.ts`，保存登录用户、权限、账套、期间等。

前端命令：

```bash
cd frontend
npm run dev
npm run build
npm run preview
```

`npm run build` 会执行 `vue-tsc --noEmit && vite build`，是当前主要前端校验命令。

## 四、请求路由与接口风格

1. 所有后端请求进入 `backend/app/finance/controller/Index.php`。
2. 接口通过 `action` 参数分发，格式为 `/<module>/<method>`。
3. 请求业务参数放在 `data` 中，可以是 JSON 字符串或表单字段。
4. 前端统一使用 `apiAction('/模块/方法', payload)`，序列化为 `application/x-www-form-urlencoded`。
5. 每个请求必须带 `account_set_id`，前端从 Pinia store 注入。
6. 前端请求头注入 `X-User-Id` 和 `X-User-Name`，后端用它填制单人、审计人和权限上下文。

已接入的后端模块：

| 模块 | 模型 | 说明 |
|---|---|---|
| `auth` | `Auth.php` | 单位列表、账套列表、登录 |
| `accountSet` | `AccountSet.php` | 账套列表、新增、编辑 |
| `subject` | `Subject.php` | 科目列表、详情、编码规则、导入导出、新增、修改、删除 |
| `aux` | `Aux.php` | 辅助维度、辅助档案、科目辅助配置 |
| `opening` | `Opening.php` | 科目期初、辅助期初 |
| `voucher` | `Voucher.php` | 凭证号、列表、详情、草稿、提交、编辑、审核、反审核、删除、作废、打印标记 |
| `book` | `Book.php` | 明细账、科目余额表 |
| `caseFund` | `CaseFund.php` | 案款缴费登记列表、Excel 导入 |
| `period` | `Period.php` | 会计期间 |
| `user` | `User.php` | 用户管理 |
| `role` | `Role.php` | 角色管理 |
| `permission` | `Permission.php` | 权限列表、当前用户权限 |
| `log` | `Log.php` | 审计日志 |
| `sys` | 控制器内置 | 系统信息 |

## 五、数据库与脚本现状

### 1. 初始化与补丁脚本

主要脚本：

1. `backend/database/court_finance_ddl.sql`：核心 DDL。
2. `backend/database/preset_case_fund_template.sql`：案款账套模板预置数据。
3. `backend/database/account_sets_preset.sql`：账套预置。
4. `backend/database/auth_user_unit.sql`：单位、用户初始化。
5. `backend/database/standard_aux_types.sql`：标准辅助核算类型。
6. `backend/database/opening_balance.sql`：期初相关。
7. `backend/database/migration_rbac.sql`：RBAC 权限迁移。
8. `backend/database/finance_standard_patch.sql`：幂等升级补丁。
9. `backend/database/preset_case_fund_template.sql` 与 DDL 应优先保证一致。

初始化顺序一般是先建库，再执行 DDL，再执行预置模板和补丁：

```sql
create database `court-finance` default character set utf8 collate utf8_general_ci;
```

### 2. 核心业务表

当前 DDL 已有以下核心表：

1. `sys_unit`：单位。
2. `sys_user`：用户，含 `must_change_password`、`last_login_time`。
3. `sys_audit_log`：审计日志。
4. `fin_account_set`：账套，含业务类型、启用年度/期间、凭证打印参数、科目编码规则。
5. `fin_fiscal_period`：会计期间，状态如 `OPEN`。
6. `fin_subject`：会计科目，含 `subject_code`、`subject_name`、`parent_code`、`direction`、`subject_type`、`level_no`、`leaf_flag`、`voucher_entry_flag`、`status`。
7. `fin_aux_type`：辅助核算维度类型。
8. `fin_aux_archive`：辅助核算档案。
9. `fin_subject_aux_config`：科目挂载辅助核算配置，含必填与核销标记。
10. `fin_opening_balance`：科目期初余额。
11. `fin_aux_opening_balance`：辅助核算期初余额。
12. `fin_voucher_no_sequence`：凭证号序列表。
13. `fin_voucher_YYYY`：年度凭证主表。
14. `fin_voucher_detail_YYYY`：年度凭证明细表。
15. `fin_voucher_aux_value_YYYY`：年度凭证辅助核算值表。
16. `fin_case_fund_payment`：案款缴费登记导入表。
17. `fin_permission`、`fin_role`、`fin_role_permission`、`fin_user_role`、`fin_user_account_set`：RBAC 权限体系。

### 3. 年度分表规则

凭证主表、凭证明细、凭证辅助值按年度分表：

1. `fin_voucher_2026`
2. `fin_voucher_detail_2026`
3. `fin_voucher_aux_value_2026`

后端统一使用 `Common::yearTable($baseTable, $period)` 根据期间生成年度表名。新增跨年度能力或新账套年度初始化时必须同步创建三张年度表。

### 4. 当前默认账套

默认账套配置在 `backend/app/finance/config.php`：

1. 法院代码：`DEMO-COURT`
2. 法院名称：`沈阳市苏家屯区人民法院`
3. 默认账套 ID：`00000000-0000-0000-0000-000000000101`
4. 默认年度：`2026`

案款账套预置：

1. `set_code`：`CASE_FUND_2026`
2. `set_name`：`案款账套`
3. `biz_type`：`CASE_FUND`
4. 科目编码规则：`4-2-2-2`
5. 预置期间：`2026-01` 至 `2026-12`，状态 `OPEN`。

## 六、会计科目实现现状

1. 科目模型：`backend/app/finance/model/Subject.php`。
2. 科目表：`fin_subject`。
3. 支持科目列表、详情、编码规则查询/保存、`.xls` 导入、导出、新增、修改、逻辑删除。
4. 账套级科目编码规则保存在 `fin_account_set.subject_code_rule`，默认 `4-2-2-2`。
5. 编码规则当前按数字编码校验：一级 4 位，二级追加 2 位，三级追加 2 位，四级追加 2 位。
6. 新增下级科目时，编码必须以上级编码作为前缀，长度必须等于下一档长度。
7. 非末级科目不能直接录入凭证；`leaf_flag=0` 时 `voucher_entry_flag` 必须为 0。
8. 上级科目如果已有期初或凭证，不允许再新增下级。
9. 已有期初或凭证的科目，不允许修改科目类别和层级。
10. 有下级科目的科目不允许删除。
11. 已有期初或凭证的科目不允许删除。
12. 科目导入支持老式 `.xls` 解析，内部实现了 OLE/BIFF 读取逻辑。
13. 科目类别枚举应使用六大类：`ASSET`、`LIABILITY`、`COMMON`、`EQUITY`、`COST`、`PROFIT_LOSS`。
14. 历史补丁会把旧的 `NET_ASSET` 转成 `EQUITY`，把 `INCOME`、`EXPENSE` 转成 `PROFIT_LOSS`。

## 七、辅助核算实现现状

1. 辅助模型：`backend/app/finance/model/Aux.php`。
2. 维度类型表：`fin_aux_type`。
3. 档案表：`fin_aux_archive`。
4. 科目配置表：`fin_subject_aux_config`。
5. 标准辅助维度：`customer`、`supplier`、`department`、`employee`、`project`。
6. 系统还存在案款模板维度：`case_no`、`receipt_no`、`party_name`、`supplier_id` 等。
7. 标准辅助维度不允许重复新增，不允许删除；标准维度编码不允许随意修改。
8. 自定义辅助维度编码必须以字母开头，只能包含字母、数字、下划线。
9. 辅助维度或档案已被科目、期初、凭证使用时，不允许删除或修改关键编码。
10. 科目辅助配置一旦对应科目已有期初或凭证，不允许修改。
11. `customer`、`supplier`、`employee` 属于往来类辅助核算，同一科目只能选择其一。
12. 凭证分录上的辅助核算值统一进入 `fin_voucher_aux_value_YYYY`，不能把案号、收据号、当事人等业务字段硬塞进凭证明细表。
13. 凭证明细会冗余 `aux_desc`，用于列表和账簿快速展示。

## 八、期初余额实现现状

1. 期初模型：`backend/app/finance/model/Opening.php`。
2. 科目期初表：`fin_opening_balance`。
3. 辅助期初表：`fin_aux_opening_balance`。
4. 科目期初支持按期间、科目保存借方或贷方金额。
5. 同一科目期初借方和贷方不能同时录入金额。
6. 辅助期初按科目挂载的辅助配置生成录入项。
7. 辅助期初缺少必填辅助维度时拒绝保存。
8. 辅助期初每行也不允许借方和贷方同时有金额。
9. 保存辅助期初时会先将旧行软删除，再插入新行，并写审计日志。

## 九、凭证核心实现现状

1. 凭证模型：`backend/app/finance/model/Voucher.php`。
2. 凭证主表：`fin_voucher_YYYY`。
3. 凭证明细表：`fin_voucher_detail_YYYY`。
4. 凭证辅助值表：`fin_voucher_aux_value_YYYY`。
5. 支持动作：`nextNo`、`list`、`info`、`draft`、`submit`、`save`、`audit`、`unaudit`、`delete`、`batchDelete`、`void`、`printMark`。
6. 新建凭证时通过 `fin_voucher_no_sequence` 加锁生成下一号，避免并发重号。
7. 凭证日期必须落在当前会计期间内。
8. 凭证明细至少两行。
9. 借贷必须平衡，凭证金额必须大于 0。
10. 金额比较与求和必须先转成“分”的整数，避免 PHP 浮点误差。
11. 每条分录必须填写科目和摘要。
12. 分录科目必须存在、启用、末级、允许录入凭证。
13. 同一分录借方和贷方不能同时有金额，也不能同时为 0。
14. 明细金额不能为负数；红字冲销应通过红字凭证逻辑设计，不应直接破坏当前校验。
15. 科目有必填辅助核算配置时，保存凭证必须录入对应辅助值。
16. 任一辅助配置 `verification_flag=1` 时，该分录 `verification_status` 为 `UNVERIFIED`，否则为 `NOT_REQUIRED`。
17. 凭证头当前保存 `debit_amount` 和 `credit_amount`，来源仍以明细行为准。
18. 凭证状态当前使用：`DRAFT`、`SUBMITTED`、`AUDITED`、`PRINTED`、`VOIDED`，前端也出现 `POSTED` 展示口径但后端记账动作尚不完整。
19. 当前审核规则：`SUBMITTED -> AUDITED`，反审核规则：`AUDITED -> SUBMITTED`。
20. 制单人不能审核自己制单的凭证。
21. 已审核、已打印、已作废凭证不允许编辑。
22. 删除是软删除，且只允许 `DRAFT` 或 `SUBMITTED`，期间必须 `OPEN`，来源不能是 `AUTO_CARRY`。
23. 批量删除是事务操作，任一凭证不满足条件则整体不删。
24. 打印标记会设置 `printed_flag=1` 且状态改为 `PRINTED`。
25. 已打印凭证不允许作废。
26. 写操作必须记录 `sys_audit_log`。

## 十、账簿报表实现现状

1. 账簿模型：`backend/app/finance/model/Book.php`。
2. 当前实现：明细账 `/book/detailLedger`、科目余额表 `/book/subjectBalance`。
3. 明细账只取状态为 `AUDITED`、`PRINTED` 的凭证。
4. 明细账支持按期间、日期范围、科目过滤，返回日期、凭证号、摘要、科目、借方、贷方、辅助摘要。
5. 科目余额表当前从科目表左关联年度凭证明细和凭证头汇总借贷与净额。
6. 当前还没有完整总账、试算平衡表、辅助核算专项账、多维组合汇总、Excel 导出闭环，后续实现必须补足这些标准财务报表能力。

## 十一、案款业务实现现状

1. 案款业务模型：`backend/app/finance/model/CaseFund.php`。
2. 案款缴费表：`fin_case_fund_payment`。
3. 当前支持案款缴费登记列表和 `.xls` 导入。
4. 导入字段包括案号、确/可标记、业务类型、缴费人、当事人、开票抬头、金额、登记类型、前审案号、缴费日期、票据号码、开票日期、开票员、收费方式、收费员、承办法官、书记员、承办部门、收款账号、银行流水号、缴费单号、内转支出票号、是否提存撤销。
5. 导入校验要求：案号不能为空，业务类型不能为空，缴费人和当事人不能同时为空，金额必须大于 0，缴费日期必须合法，票据号码/银行流水号/缴费单号至少一项用于追溯。
6. 导入用 `case_no + payment_time + payment_amount + receipt_no + bank_serial_no + payment_order_no` 生成 MD5 指纹，防止重复导入。
7. `voucher_status` 当前有 `UNGENERATED`、`GENERATED`、`VOIDED` 等口径，但案款自动生成凭证尚未完成。
8. 前端已有 `frontend/src/views/case-fund/PaymentRegisterView.vue` 和 `frontend/src/api/caseFund.ts`，但需要注意路由和菜单是否已经实际挂载到 `App.vue`、`router/index.ts`。
9. 案款核销长期规则是按“案号 + 收据号”匹配，收据号允许为空；完整往来核销闭环属于后续重点。

## 十二、账套与期间实现现状

1. 账套模型：`backend/app/finance/model/AccountSet.php`。
2. 账套支持列表、新增、编辑。
3. 新增账套会：
   - 生成账套编码。
   - 创建启用期间所在年度从启用月到 12 月的会计期间。
   - 创建对应年度凭证三张分表。
   - 将当前用户授予该账套访问权。
   - 写审计日志。
4. 账套参数包括业务类型、启用年度/期间、财务负责人、纸张尺寸、凭证导入是否自动编号、凭证打印分录行数、科目编码规则。
5. 业务类型前端已有：`CASE_FUND` 案款、`LITIGATION_FEE` 诉讼费、`CANTEEN` 食堂、`UNION` 工会。

## 十三、权限与内控实现现状

1. RBAC 设计文档：`docs/design/2026-05-03-rbac-user-management-design.md`。
2. 权限模型：`Permission.php`。
3. 角色模型：`Role.php`。
4. 用户模型：`User.php`。
5. 登录模型：`Auth.php`。
6. 权限体系为：权限 -> 角色 -> 用户，用户可多角色。
7. 数据权限包括用户可访问账套 `fin_user_account_set` 和角色视图范围 `view_scope`。
8. `view_scope=SELF` 时，凭证列表和详情只允许查看本人制单数据。
9. 系统管理员通过 `*` 通配符拥有全部权限。
10. 预置角色包括：系统管理员、财务主管、制单会计、审核会计、记账会计。
11. 内控规则必须以后端为准，前端隐藏按钮只作为体验优化。
12. 用户禁用后不可登录，但历史数据保留。
13. 首次登录或重置密码后 `must_change_password=1`，前端通过强制改密弹窗处理。
14. 不允许删除或禁用最后一个系统管理员。

## 十四、前端页面与交互现状

1. 应用外壳：`frontend/src/App.vue`。
2. 登录页：`frontend/src/views/auth/LoginView.vue`。
3. 账套选择页：`frontend/src/views/auth/AccountSetSelectView.vue`。
4. 首页看板：`frontend/src/views/dashboard/DashboardView.vue`。
5. 凭证中心：`frontend/src/views/voucher/VoucherListView.vue`。
6. 凭证录入/编辑/查看：`frontend/src/views/voucher/VoucherEditorView.vue`。
7. 明细账：`frontend/src/views/books/DetailLedgerView.vue`。
8. 科目余额表：`frontend/src/views/books/SubjectBalanceView.vue`。
9. 科目管理：`frontend/src/views/base/SubjectManageView.vue`。
10. 期初余额：`frontend/src/views/base/OpeningBalanceView.vue`。
11. 辅助核算项：`frontend/src/views/base/AuxItemManageView.vue`。
12. 用户管理：`frontend/src/views/system/UserManageView.vue`。
13. 角色管理：`frontend/src/views/system/RoleManageView.vue`。
14. 角色权限配置：`frontend/src/views/system/RolePermissionView.vue`。
15. 账套管理：`frontend/src/views/system/AccountSetManageView.vue`。
16. 审计日志：`frontend/src/views/system/AuditLogView.vue`。
17. 案款缴费登记页文件已存在：`frontend/src/views/case-fund/PaymentRegisterView.vue`。

当前路由在 `frontend/src/router/index.ts`，已经注册登录、账套选择、首页、凭证、账簿、基础资料、系统管理页面。新增页面必须同步处理：

1. 路由注册。
2. `App.vue` 菜单入口。
3. `App.vue` 页签标题 `titleMap`。
4. 权限码。
5. API wrapper。
6. 类型定义。

## 十五、前端 API 约定

1. 不要在视图里直接调用 axios。
2. 新接口必须在 `frontend/src/api/<module>.ts` 中封装。
3. API 封装统一调用 `apiAction('/module/method', payload)`。
4. 类型放在 `frontend/src/types/api.ts`。
5. 权限判断优先使用 `context.hasPermission()` 或 `v-permission` 指令。
6. 按钮权限与后端权限码必须保持一致，不能只做前端隐藏。

## 十六、测试与验证现状

### 1. 后端测试脚本

当前 `backend/tests/` 下有若干 PHP 脚本式测试：

1. `AccountSetHelperTest.php`
2. `AuxDeleteGuardTest.php`
3. `CaseFundPaymentImportTest.php`
4. `SubjectCodeRuleTest.php`
5. `SubjectDeleteGuardTest.php`
6. `SubjectXlsImportExportTest.php`
7. `UserAccountSetOptionsTest.php`
8. `VoucherAmountSummaryTest.php`
9. `VoucherDeleteEligibilityTest.php`

运行单个测试示例：

```bash
/Applications/MAMP/bin/php/php7.4.33/bin/php backend/tests/VoucherAmountSummaryTest.php
```

### 2. 前端测试脚本

当前 `frontend/tests/` 下有 Node 断言式测试：

1. `AuxItemManageDeleteTest.mjs`
2. `CaseFundMenuTest.mjs`
3. `SubjectManageActionsTest.mjs`
4. `VoucherEditorReviewTest.mjs`
5. `VoucherListUnauditTest.mjs`

运行示例：

```bash
node frontend/tests/VoucherListUnauditTest.mjs
```

前端主要完整校验仍是：

```bash
cd frontend
npm run build
```

## 十七、当前文档与计划

主要文档：

1. `docs/requirements.md`：法院专项账务记账系统 V2.0 业务需求。
2. `docs/design/phase1-implementation.md`：一期实现说明。
3. `docs/design/2026-05-03-rbac-user-management-design.md`：RBAC 用户管理设计。

已有实现计划/规格：

1. 科目编码规则与树形科目设计：`docs/superpowers/specs/2026-05-04-subject-code-rule-design.md`。
2. 科目编码规则实现计划：`docs/superpowers/plans/2026-05-04-subject-code-rule.md`。
3. 凭证头借贷金额设计：`docs/superpowers/specs/2026-05-04-voucher-header-amounts-design.md`。
4. 凭证头借贷金额实现计划：`docs/superpowers/plans/2026-05-04-voucher-header-amounts.md`。
5. 凭证编辑删除设计：`docs/superpowers/specs/2026-05-04-voucher-edit-delete-design.md`。
6. 凭证编辑删除实现计划：`docs/superpowers/plans/2026-05-04-voucher-edit-delete.md`。

## 十八、重要开发注意事项

1. 当前仓库存在未提交改动，开发时不得随意回滚别人已经改过的文件。
2. 不要修改 `backend/thinkphp/` 框架源码，除非明确定位到框架兼容问题。
3. 不要把 `frontend/node_modules/`、`frontend/dist/`、`backend/runtime/` 当作业务源码修改。
4. 所有业务查询默认必须带 `account_set_id` 和 `del_flag=0`。
5. 所有写操作必须维护创建/更新字段和软删除标记。
6. 所有重要写操作必须写审计日志。
7. 金额计算必须使用“分”的整数逻辑，不允许直接用浮点做平衡判断。
8. 凭证类业务必须开启事务，尤其是凭证号生成、凭证头/明细/辅助值联动保存、批量删除、导入落库。
9. 辅助核算是财务维度，不是备注字段；不能用自由文本替代应有的档案和配置约束。
10. 案号、收据号、当事人、供应商等业务属性必须通过辅助核算或业务登记表表达，不能污染通用凭证明细结构。
11. 数据库脚本要尽量保持可迁移，后续目标包括达梦 DM8、人大金仓 KingbaseES；除非当前补丁明确只面向 MySQL，否则避免强绑定 MySQL 方言。
12. 前端页面要保持政务/财务系统风格：信息密度适中、表格清晰、操作可追溯，不做营销式界面。
13. 新增菜单必须同时考虑权限码、路由守卫、侧边栏、页签标题和后端权限校验。
14. 新增财务功能不能只做存储，必须同步考虑账簿查询、辅助账、报表、对账和审计。

## 十九、已知差距与后续重点

1. 凭证“记账/过账”后端闭环尚不完整，当前主要到审核/打印。
2. 会计期间结账、反结账、期末损益结转尚未形成完整闭环。
3. 科目余额表当前按凭证明细实时汇总，尚未完整维护“科目层级余额 + 辅助维度余额”两套余额表。
4. 辅助核算专项账、客户/供应商往来账、部门/项目明细账、多维组合汇总仍需补齐。
5. 总账、试算平衡表、标准报表导出 Excel 仍需补齐。
6. 案款按“案号 + 收据号”的往来核销、未核销案款明细、账龄分析仍需补齐。
7. 银行流水导入、自动对账、银行存款余额调节表仍需补齐。
8. 案款缴费登记页面文件已存在，但菜单/路由挂载需要核对当前实现是否完整。
9. 前端 `CaseFundMenuTest.mjs` 对案款菜单和路由有断言，后续涉及案款菜单时必须运行。
10. 前端 store 当前默认期间写死为 `2026-05`，后续应与账套当前期间或期间选择联动。

## 二十、推荐开发工作流

1. 先阅读本文件和相关设计文档。
2. 按“财务规则 -> 数据库 -> 流程 -> 接口 -> 字典档案 -> 代码”的固定顺序输出或实施。
3. 后端改动优先写或更新 `backend/tests/` 中的脚本式测试。
4. 前端改动优先补充 `frontend/tests/` 中的断言式测试，至少运行相关测试和 `npm run build`。
5. 涉及数据库字段时同步更新 DDL、预置脚本、幂等补丁、模型、前端类型。
6. 涉及权限时同步更新 `migration_rbac.sql`、后端 `requirePermission()`、前端 `v-permission`/路由 meta。
7. 涉及凭证、期初、辅助核算、账簿时必须自查总账与辅助账能否对账平衡。
