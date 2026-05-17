请先阅读 AGENTS.md。当前项目的长期上下文、技术栈、数据库现状和开发规范都以 AGENTS.md 为准。
这是一次架构重构，不是新增功能。目标是取消年度物理分表，改为统一凭证表 + account_set_id + fiscal_year + period 的标准财务软件存储模式。
当前项目现状：
1. 当前采用“按年度分表”的方式存储凭证数据。
2. 现有年度表包括：
   - fin_voucher_YYYY
   - fin_voucher_detail_YYYY
   - fin_voucher_aux_value_YYYY
3. 后端通过 Common::yearTable($baseTable, $period) 根据会计期间动态拼接年度表名。
4. 新增账套或新增年度时，会同步创建对应年度的三张凭证分表。
5. 账套仍然通过 account_set_id 做隔离，不存在 customer_id 或 SaaS 多租户设计。

现在我想把“年度分表账套”改成“统一单表账套”模式。

目标架构：
1. 不再使用 fin_voucher_2026、fin_voucher_detail_2026、fin_voucher_aux_value_2026 这种年度分表。
2. 改为统一表：
   - fin_voucher
   - fin_voucher_detail
   - fin_voucher_aux_value
3. 所有凭证相关业务表都必须保留：
   - account_set_id
   - fiscal_year
   - fiscal_period / period
   - create_user
   - create_time
   - update_user
   - update_time
   - del_flag
4. 年度不再通过表名体现，而是通过 fiscal_year 字段体现。
5. 会计期间仍然保留，通过 period 或 fiscal_period 字段筛选。
6. 账套隔离仍然只使用 account_set_id。

请按以下顺序给我设计并实施迁移方案：

第一步：业务规则说明
- 明确：统一单表并不取消年度账套、会计期间、期末结账、年结。
- 年结仍然是业务动作，不是数据库分表动作。
- 年结需要生成下一年度期初余额、辅助期初余额，并关闭旧年度期间。
- 旧年度凭证不能随意修改，仍然受期间状态、凭证状态、权限控制。

第二步：数据库改造方案
- 新建统一表：
  - fin_voucher
  - fin_voucher_detail
  - fin_voucher_aux_value
- 表结构应兼容原 fin_voucher_YYYY 三张表字段。
- 增加 fiscal_year 字段。
- 增加必要索引：
  - account_set_id + fiscal_year + period
  - account_set_id + fiscal_year + period + voucher_no
  - account_set_id + voucher_date
  - voucher_id
  - voucher_detail_id
  - subject_id
  - del_flag
- 保留 UUID 主键规范。
- 不要引入 customer_id。
- 所有金额字段继续保持当前项目规范，金额计算业务层必须用“分”的整数逻辑。

第三步：数据迁移方案
- 将历史年度分表数据迁移进统一表。
- 例如：
  - fin_voucher_2026 -> fin_voucher，fiscal_year=2026
  - fin_voucher_detail_2026 -> fin_voucher_detail，fiscal_year=2026
  - fin_voucher_aux_value_2026 -> fin_voucher_aux_value，fiscal_year=2026
- 如果存在多个年度分表，逐年迁移。
- 迁移前检查目标表是否已存在相同 id，避免重复导入。
- 迁移完成后先不要删除旧年度分表，先保留作为备份。
- 提供幂等 SQL 补丁或 PHP 迁移脚本。

第四步：后端代码改造
- 移除或弱化 Common::yearTable($baseTable, $period) 在凭证业务中的使用。
- Voucher.php 中所有对 fin_voucher_YYYY、fin_voucher_detail_YYYY、fin_voucher_aux_value_YYYY 的访问，改为访问统一表。
- 所有查询必须显式带：
  - account_set_id
  - fiscal_year
  - period/期间条件
  - del_flag=0
- 凭证号生成仍然按 account_set_id + fiscal_year + period 控制连续编号。
- 凭证列表、详情、保存、审核、反审核、删除、作废、打印标记都要同步改造。
- Book.php 中明细账、科目余额表查询也要改成统一表查询，不再拼年度表名。
- AccountSet.php 新增账套时，不再创建年度凭证分表，只创建会计期间、授权、账套基础信息。
- Period.php 后续新增年度期间时，也不再创建年度凭证分表。

第五步：期初与余额体系
- 当前已有：
  - fin_opening_balance
  - fin_aux_opening_balance
- 继续保留。
- 后续完整余额体系应扩展：
  - fin_subject_balance
  - fin_aux_balance
- 科目期初和辅助期初都必须按 account_set_id + fiscal_year + period 存储。
- period=0 可表示年初期初。
- 启用辅助核算的科目，辅助期初合计必须等于科目期初。
- 保存辅助期初时，可以由辅助期初自动汇总生成科目期初，避免两套数据不一致。

第六步：年结逻辑
- 统一单表后仍然要做年结。
- 年结流程：
  1. 检查旧年度所有期间是否已结账。
  2. 检查凭证是否全部审核/记账。
  3. 生成下一年度会计期间。
  4. 复制或延续科目体系、辅助核算配置。
  5. 将旧年度期末科目余额生成下一年度 period=0 的科目期初。
  6. 将旧年度期末辅助余额生成下一年度 period=0 的辅助期初。
  7. 收入、费用、损益类科目按规则结转清零。
  8. 关闭旧年度。
- 不要把“统一表”误认为“不需要年结”。

第七步：前端影响
- 前端账套选择逻辑原则上不变。
- 凭证、账簿、期初余额页面请求中仍然需要传 account_set_id、当前期间。
- 如果后端接口需要 fiscal_year，前端从当前期间如 2026-05 解析出 2026。
- 检查 frontend/src/stores/context.ts 当前期间逻辑，避免写死 2026-05。
- 所有 API wrapper 保持 apiAction('/module/method', payload) 风格。

第八步：测试要求
- 更新或新增后端测试：
  - 凭证保存到统一表
  - 凭证列表按年度/期间筛选
  - 凭证详情读取统一表
  - 审核/反审核/删除/作废不受年度分表影响
  - 账簿明细账按统一表查询
  - 新增账套不再创建年度分表
- 更新前端测试或至少运行：
  - npm run build
  - 凭证列表相关测试
  - 凭证录入相关测试
  - 账簿相关页面测试

特别注意：
1. 不要迁移技术栈，当前仍然是 PHP 7.4 + ThinkPHP 5 + MySQL 8 + Vue 3。
2. 不要引入 Java/Spring Boot。
3. 不要修改 backend/thinkphp/ 框架源码。
4. 所有业务查询必须带 account_set_id 和 del_flag=0。
5. 金额计算不能直接用 PHP 浮点数。
6. 改造时必须保持现有接口风格和返回格式。
7. 先给出设计方案和影响清单，再开始修改代码。