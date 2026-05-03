# RBAC 用户管理设计文档

- **创建日期**: 2026-05-03
- **范围**: 系统管理菜单首期 - 用户、角色、权限管理
- **状态**: 设计已确认,待实施

## 1. 目标

为法院财务系统增加标准三层 RBAC 用户权限体系,支持:

- 三层架构:权限(Permission)→ 角色(Role)→ 用户(User)
- 角色继承权限、用户归属角色(支持一人多角色)
- 数据权限:账套访问范围、凭证查看范围(全部/仅本人)
- 内控规则:制单人不能审核自己制单的凭证
- 用户禁用后保留历史数据但不可登录

## 2. 关键设计决策

| 决策项 | 选定方案 | 理由 |
|-------|---------|------|
| 菜单存储 | 前端硬编码 + 权限码过滤 | 菜单结构稳定,改动小 |
| 按钮权限粒度 | 按业务模块 + 操作 | 语义清晰,如 `voucher:audit` |
| 账套权限存储 | 新建 `fin_user_account_set` 多对多 | 支持跨账套用户(审计、集团) |
| 凭证查看范围判定 | 按 `prepared_by` 字段 | 与现有凭证字段对齐 |
| 多角色权限合并 | 并集 | 符合一般直觉 |
| 内控强制层 | 后端为主 + 前端隐藏按钮 | 安全 + 体验兼顾 |
| 初始密码 | 管理员设置 + 强制首次改密 | 简单可靠 |
| 老 admin 用户迁移 | 数据库迁移脚本自动迁移 | 一次性完成 |

## 3. 数据库设计

### 3.1 新增表(全部用 `fin_` 前缀)

```sql
-- 权限表 (菜单权限 + 按钮权限)
CREATE TABLE fin_permission (
  permission_code VARCHAR(50) PRIMARY KEY COMMENT '权限码,如 voucher:audit',
  permission_name VARCHAR(100) NOT NULL,
  permission_type TINYINT NOT NULL COMMENT '1=菜单 2=按钮',
  module_code VARCHAR(50) COMMENT '所属模块: voucher/book/base/system',
  description VARCHAR(255),
  sort_order INT DEFAULT 0
);

-- 角色表
CREATE TABLE fin_role (
  role_id VARCHAR(36) PRIMARY KEY,
  role_code VARCHAR(50) UNIQUE NOT NULL,
  role_name VARCHAR(100) NOT NULL,
  description VARCHAR(255),
  is_system TINYINT DEFAULT 0 COMMENT '1=系统预置不可删',
  view_scope VARCHAR(20) DEFAULT 'ALL' COMMENT 'ALL=全部 SELF=仅本人制单',
  status TINYINT DEFAULT 1,
  created_at DATETIME,
  updated_at DATETIME
);

-- 角色-权限映射
CREATE TABLE fin_role_permission (
  role_id VARCHAR(36) NOT NULL,
  permission_code VARCHAR(50) NOT NULL,
  PRIMARY KEY (role_id, permission_code)
);

-- 用户-角色映射 (一人多角色)
CREATE TABLE fin_user_role (
  user_id VARCHAR(36) NOT NULL,
  role_id VARCHAR(36) NOT NULL,
  PRIMARY KEY (user_id, role_id)
);

-- 用户-账套映射
CREATE TABLE fin_user_account_set (
  user_id VARCHAR(36) NOT NULL,
  account_set_id VARCHAR(36) NOT NULL,
  PRIMARY KEY (user_id, account_set_id)
);
```

### 3.2 修改既有表

```sql
ALTER TABLE sys_user
  ADD COLUMN must_change_password TINYINT DEFAULT 1 COMMENT '首次登录强制改密',
  ADD COLUMN last_login_at DATETIME NULL;
-- role_code 字段在迁移完成后由迁移脚本删除
```

### 3.3 命名一致性说明

- `sys_user`、`sys_unit` 已有数据,保持不变(避免破坏现有依赖)
- 新增的 5 张 RBAC 表统一用 `fin_` 前缀,与 `fin_voucher_*`、`fin_account_set` 等保持一致
- 跨模块外键关系(如 `fin_user_role.user_id` → `sys_user.user_id`)采用逻辑外键,不强加 FK 约束以保留迁移灵活性

## 4. 预置数据

### 4.1 权限码列表

**菜单权限(permission_type=1):**

| code | name | 路由 |
|------|------|------|
| `menu:dashboard` | 首页 | `/dashboard` |
| `menu:voucher` | 凭证中心 | `/vouchers` |
| `menu:book` | 账簿 | `/books/*` |
| `menu:book:detail_ledger` | 明细账 | `/books/detail-ledger` |
| `menu:book:subject_balance` | 科目余额 | `/books/subject-balance` |
| `menu:base` | 基础数据 | `/base/*` |
| `menu:base:subject` | 科目管理 | `/base/subjects` |
| `menu:base:opening` | 期初余额 | `/base/opening-balances` |
| `menu:base:aux` | 辅助核算项 | `/base/aux-items` |
| `menu:system` | 系统管理 | `/system/*` |
| `menu:system:user` | 用户管理 | `/system/users` |
| `menu:system:role` | 角色管理 | `/system/roles` |
| `menu:system:role_permission` | 角色权限配置 | `/system/role-permissions` |
| `menu:system:audit_log` | 审计日志 | `/system/audit-logs` |

**按钮权限(permission_type=2):**

| 模块 | 适用操作 |
|------|---------|
| `voucher:` | view, add, edit, delete, audit, unaudit, post, unpost, export, print |
| `book:` | view, export, print |
| `base:` | view, add, edit, delete, export |
| `period:` | close (期末结账) |
| `system:user:` | view, add, edit, delete, reset_password |
| `system:role:` | view, add, edit, delete, assign_permission |

**统计:** 14 菜单 + 29 按钮 = 共 43 个权限码

### 4.2 5 个标准角色权限矩阵

| 权限 | 系统管理员 | 财务主管 | 制单会计 | 审核会计 | 记账会计 |
|------|:---:|:---:|:---:|:---:|:---:|
| 凭证 view/add/edit/delete | ✅ | ✅ | ✅ | view 只 | view 只 |
| 凭证 audit / unaudit | ✅ | ✅ | ❌ | ✅ | ❌ |
| 凭证 post / unpost | ✅ | ✅ | ❌ | ❌ | ✅ |
| 凭证 export / print | ✅ | ✅ | ✅ | ✅ | ✅ |
| 账簿 view/export/print | ✅ | ✅ | ✅ | ✅ | ✅ |
| 基础数据 view/add/edit/delete | ✅ | ✅ | view | view | view |
| 期末结账 | ✅ | ✅ | ❌ | ❌ | ✅ |
| 用户管理 * | ✅ | ❌ | ❌ | ❌ | ❌ |
| 角色管理 * | ✅ | ❌ | ❌ | ❌ | ❌ |
| 审计日志 | ✅ | ✅ | ❌ | ❌ | ❌ |
| view_scope | ALL | ALL | ALL | ALL | ALL |

> 系统管理员通过 `*` 通配符权限码授予所有权限,后续新增权限时不需修改预置角色。
> view_scope 默认 `ALL`,管理员可在角色管理界面按需调整为 `SELF`。

### 4.3 角色职责分离

- **制单会计**:仅 add/edit/delete 草稿凭证,不能审核/记账
- **审核会计**:仅 view + audit/unaudit
- **记账会计**:仅 view + post/unpost + period_close

叠加内控规则"制单人不能审核自己凭证"(后端校验 prepared_by ≠ current_user_id),即使一个用户拥有制单+审核双角色,也不能审自己的单。

## 5. 后端 API 设计

### 5.1 路由扩展

`backend/app/finance/controller/Index.php` 新增 3 个模块路由:

```php
case 'user':       $model = new \app\finance\model\User();       break;
case 'role':       $model = new \app\finance\model\Role();       break;
case 'permission': $model = new \app\finance\model\Permission(); break;
```

> 既有 `Auth` 模型只管登录,新增的 `User` 模型负责用户 CRUD,职责分开。

### 5.2 User 模型方法

| action | 入参 | 用途 |
|--------|------|------|
| `/user/list` | `page, size, keyword, status` | 分页用户列表(含角色名、账套名) |
| `/user/info` | `user_id` | 单用户详情(含角色 ID 数组、账套 ID 数组) |
| `/user/add` | `username, real_name, unit_id, init_password, role_ids[], account_set_ids[]` | 新增(强制 `must_change_password=1`) |
| `/user/edit` | `user_id, real_name, unit_id` | 修改基本信息(不改密码、不改角色) |
| `/user/toggleStatus` | `user_id, status` | 启用/禁用(数据保留) |
| `/user/resetPassword` | `user_id, new_password` | 管理员重置密码(置 `must_change_password=1`) |
| `/user/changePassword` | `old_password, new_password` | 用户自助改密(置 `must_change_password=0`) |
| `/user/assignRoles` | `user_id, role_ids[]` | 重设角色(先 delete 再 insert) |
| `/user/assignAccountSets` | `user_id, account_set_ids[]` | 重设可访问账套 |

### 5.3 Role 模型方法

| action | 入参 | 用途 |
|--------|------|------|
| `/role/list` | `keyword, status` | 角色列表(含权限数量、用户数量) |
| `/role/info` | `role_id` | 角色详情(含权限码数组) |
| `/role/add` | `role_code, role_name, description, view_scope` | 新增自定义角色 |
| `/role/edit` | `role_id, role_name, description, view_scope, status` | 编辑角色(系统预置不可改 code) |
| `/role/delete` | `role_id` | 删除(`is_system=1` 拒绝;有用户绑定拒绝) |
| `/role/assignPermissions` | `role_id, permission_codes[]` | 重设角色权限 |

### 5.4 Permission 模型方法

| action | 入参 | 用途 |
|--------|------|------|
| `/permission/list` | `{}` | 所有权限码,按 `module_code` 分组 |
| `/permission/userPermissions` | `{}` | 当前用户合并后的权限码列表 |

### 5.5 Auth 登录响应增强

修改 `backend/app/finance/model/Auth.php` 的 `login` 方法,登录成功后多返回:

```php
[
  // ... existing fields
  'permissions'         => ['voucher:view', 'voucher:audit', ...], // 多角色合并(并集)
  'view_scope'          => 'ALL',           // 多角色取最宽松值: ALL > SELF
  'must_change_password'=> 1,               // 是否需强制改密
  'roles'               => [{role_id, role_code, role_name}, ...],
  'account_sets'        => [{id, code, name}, ...]
]
```

登录时若 `status=0` 则拒绝并提示"用户已被禁用"。
若 `must_change_password=1`,前端登录后强制弹改密弹窗(不可关闭)。

### 5.6 内控规则后端强制点

**1. 凭证审核校验(`Voucher::audit`)**

```php
if ($voucher['prepared_by'] === $currentUserId) {
    throw new \Exception('制单人不能审核自己制单的凭证');
}
```

**2. 凭证查询数据范围过滤(`Voucher::list`)**

```php
if ($currentUser['view_scope'] === 'SELF') {
    $where[] = ['prepared_by', '=', $currentUserId];
}
```

**3. 操作权限校验中间件**

每个写操作 action 入口校验 `hasPermission(<code>)`,通过 token 中的 user_id 反查 `fin_user_role → fin_role_permission → fin_permission` 拿到合并后的权限码列表(可缓存到会话)。

### 5.7 数据库迁移脚本

`backend/database/migration_rbac.sql`:

```sql
-- 1. 创建新表(略,见第 3 部分)
-- 2. 插入预置权限数据
INSERT INTO fin_permission VALUES (...);
-- 3. 插入 5 个标准角色
INSERT INTO fin_role VALUES (...);
-- 4. 角色-权限映射(按矩阵插入)
INSERT INTO fin_role_permission VALUES (...);
-- 5. 把 sys_user 中 role_code='admin' 的用户关联到"系统管理员"角色
INSERT INTO fin_user_role
  SELECT u.user_id, r.role_id
  FROM sys_user u, fin_role r
  WHERE u.role_code = 'admin' AND r.role_code = 'system_admin';
-- 6. 把所有现有用户关联到当前默认账套
INSERT INTO fin_user_account_set
  SELECT user_id, account_set_id FROM sys_user
  WHERE EXISTS (SELECT 1 FROM fin_account_set LIMIT 1);
-- 7. 删除旧字段
ALTER TABLE sys_user DROP COLUMN role_code;
```

## 6. 前端设计

### 6.1 Pinia store 扩展

`frontend/src/stores/context.ts`:

```typescript
state: () => ({
  // ... existing fields
  permissions: [] as string[],
  viewScope: 'ALL' as 'ALL' | 'SELF',
  mustChangePassword: false,
  roles: [] as Array<{role_id: string; role_code: string; role_name: string}>,
  accountSets: [] as Array<{id: string; code: string; name: string}>
}),
getters: {
  hasPermission: (state) => (code: string) =>
    state.permissions.includes('*') || state.permissions.includes(code),
  hasAnyPermission: (state) => (codes: string[]) =>
    codes.some(c => state.permissions.includes(c) || state.permissions.includes('*'))
}
```

### 6.2 全局权限指令

`frontend/src/directives/permission.ts`:

```typescript
import { useContextStore } from '../stores/context'

export const vPermission = {
  mounted(el: HTMLElement, binding: { value: string | string[] }) {
    const store = useContextStore()
    const codes = Array.isArray(binding.value) ? binding.value : [binding.value]
    if (!codes.some(c => store.hasPermission(c))) {
      el.parentNode?.removeChild(el)
    }
  }
}
```

使用:
```vue
<el-button v-permission="'voucher:audit'">审核</el-button>
<el-button v-permission="['voucher:add', 'voucher:edit']">编辑</el-button>
```

> 动态渲染场景下用 `v-if="hasPermission(...)"` 替代,指令仅适用初次渲染。

### 6.3 路由守卫扩展

`frontend/src/router/index.ts` 中 `beforeEach` 增加菜单权限检查:

```typescript
if (to.meta.permission && !context.hasPermission(to.meta.permission)) {
  return '/dashboard'
}
```

3 个新路由:

```typescript
{ path: '/system/users',
  component: UserManageView,
  meta: { permission: 'menu:system:user' } },
{ path: '/system/roles',
  component: RoleManageView,
  meta: { permission: 'menu:system:role' } },
{ path: '/system/role-permissions',
  component: RolePermissionView,
  meta: { permission: 'menu:system:role_permission' } }
```

### 6.4 强制改密弹窗

全局组件 `ForceChangePasswordDialog.vue`,挂在 `App.vue` 顶层,根据 `context.mustChangePassword` 显示。

- `:close-on-click-modal="false" :show-close="false"` 不可关闭
- 字段:旧密码、新密码、确认新密码
- 校验:与旧密码不同、长度 ≥ 8、含字母+数字
- 调 `/user/changePassword`,成功后置 `mustChangePassword = false`

### 6.5 三个管理页面

#### 页面 1: 用户管理 `/system/users`

```
┌─────────────────────────────────────────────────┐
│ 用户管理                              [+ 新增]   │
│ 关键字: [____] 状态: [全部▼] [查询] [重置]       │
├─────────────────────────────────────────────────┤
│ 用户名 | 姓名 | 部门 | 角色 | 账套 | 状态 | 操作 │
└─────────────────────────────────────────────────┘
```

新增/编辑弹窗(分 3 个 tab):

- Tab1 基本信息:用户名、真实姓名、部门、初始密码(仅新增)
- Tab2 角色分配:多选(transfer 或 checkbox-group)
- Tab3 账套分配:多选

按钮权限码:

- 新增 → `system:user:add`
- 编辑/禁用 → `system:user:edit`
- 重置密码 → `system:user:reset_password`

#### 页面 2: 角色管理 `/system/roles`

```
┌──────────────────────────────────────────────────────┐
│ 角色管理                                  [+ 新增]    │
├──────────────────────────────────────────────────────┤
│ 角色编码 | 角色名 | 数据范围 | 用户数 | 系统预置 | 操作│
└──────────────────────────────────────────────────────┘
```

新增/编辑弹窗:

- 角色编码(系统预置不可改)
- 角色名称、描述
- 数据范围下拉:全部 / 仅本人制单
- 状态:启用 / 禁用
- "配置权限"按钮跳到角色权限配置页并预选当前角色

> 删除按钮:仅 `is_system=0` 且 `用户数=0` 时显示。

#### 页面 3: 角色权限配置 `/system/role-permissions`

左右两栏布局:

- 左栏:角色单选列表
- 右栏:权限分组复选框,按 module_code 分区(凭证/账簿/基础数据/期末/系统管理),菜单权限置顶
- 保存按钮调 `/role/assignPermissions` 提交全量权限码

### 6.6 App.vue 菜单改造

```vue
<el-sub-menu v-if="hasAnyPermission(['menu:system:user', 'menu:system:role',
                  'menu:system:role_permission', 'menu:system:audit_log'])"
             index="system">
  <template #title><el-icon><Setting/></el-icon><span>系统管理</span></template>
  <el-menu-item v-if="hasPermission('menu:system:user')"
                index="/system/users">用户管理</el-menu-item>
  <el-menu-item v-if="hasPermission('menu:system:role')"
                index="/system/roles">角色管理</el-menu-item>
  <el-menu-item v-if="hasPermission('menu:system:role_permission')"
                index="/system/role-permissions">角色权限配置</el-menu-item>
  <el-menu-item v-if="hasPermission('menu:system:audit_log')"
                index="/system/audit-logs">审计日志</el-menu-item>
</el-sub-menu>
```

### 6.7 新增前端 API 模块

`frontend/src/api/user.ts`、`role.ts`、`permission.ts`,按现有 `voucherApi` 模式封装。

## 7. 实施步骤(7 阶段)

```
Step 1: 数据库迁移
   ├─ 建 5 张新表
   ├─ 插入预置权限/角色/映射
   └─ 迁移 admin 用户 + 删除 sys_user.role_code
   验证: SELECT 各表确认数据正确

Step 2: 后端 Permission/Role/User 三个 model
   ├─ index($action, $data) 派发
   ├─ list/info/add/edit/delete/assign 等方法
   ├─ Index.php 控制器添加路由分支
   └─ 中间件层添加权限码校验
   验证: Postman 调每个接口

Step 3: 后端 Auth 登录响应增强 + 内控规则
   ├─ login 返回 permissions/view_scope/account_sets
   ├─ Voucher::audit 添加 prepared_by 校验
   └─ Voucher::list 按 view_scope='SELF' 自动过滤
   验证: 制单后用同一用户调 audit 应报错

Step 4: 前端 Pinia 扩展 + 权限指令 + 路由守卫
   ├─ context.ts 新增字段
   ├─ directives/permission.ts 全局注册
   └─ router/index.ts 增加 meta.permission 检查
   验证: 不同角色登录,菜单可见性符合预期

Step 5: 前端 3 个管理页面
   ├─ UserManageView.vue (3-tab 弹窗)
   ├─ RoleManageView.vue
   ├─ RolePermissionView.vue (左右两栏)
   └─ api/user.ts / role.ts / permission.ts
   验证: 三页面 CRUD 闭环

Step 6: 强制改密弹窗
   ├─ ForceChangePasswordDialog.vue 全局组件
   └─ App.vue 挂载
   验证: 新增用户登录 → 强制弹窗

Step 7: App.vue 菜单改造 + 现有页面按钮加权限码
   ├─ 加 hasPermission 过滤的 3 个新菜单项
   ├─ VoucherListView/EditorView 按钮加 v-permission
   └─ Books/Base 各页面按需加
   验证: 各角色登录,验证矩阵中所有约束生效
```

## 8. 风险点

| 风险 | 影响 | 应对 |
|------|------|------|
| 已有用户登录态在迁移后失效 | 中 | 迁移后需用户重新登录;登录页加提示 |
| Token 缓存的权限码过期 | 中 | 登录后写入 localStorage;角色变更需重登录 |
| 系统管理员被误删/禁用导致无人可管理 | 高 | 后端在 `toggleStatus`、`assignRoles` 校验:不允许把所有 system_admin 用户全部禁用 |
| 多账套切换时 viewScope/permissions 是否需重新加载 | 低 | 当前设计 view_scope 与角色绑定,与账套无关,无需重加载 |
| v-permission 指令对动态渲染的按钮失效 | 中 | 配合 v-if + hasPermission 用 |
| role_code 字段删除后老代码残留引用 | 中 | 全局搜索 `role_code` 一次性清理 |
| 内控规则前端绕过 | 低 | 前端只做按钮隐藏(体验),后端强制校验(安全),双层保护 |

## 9. 验收测试要点

- [ ] admin 用户登录,4 个系统菜单全部可见
- [ ] 仅"制单会计"角色用户登录:系统管理菜单不可见,凭证页"审核""记账""期末结账"按钮均不可见
- [ ] 仅"审核会计"角色用户登录:凭证页可见但"新增""编辑""删除"按钮不可见
- [ ] 兼具 制单+审核 双角色的用户:自己制单的凭证调审核接口应返回错误
- [ ] 把某角色 view_scope 改为 SELF:该角色用户只能看到自己制单的凭证
- [ ] 禁用某用户:登录失败,但其历史制单凭证仍可见
- [ ] 新增用户:用初始密码登录 → 强制改密弹窗显示且不可关闭
- [ ] 删除有用户绑定的角色:拒绝并提示
- [ ] 删除系统预置角色:拒绝并提示

## 10. 不在本次范围

- 数据权限的部门维度(仅做账套+凭证查看范围,不做按部门隔离)
- 操作日志记录(已有 audit_log 模块,本次只做权限本身,不扩展日志格式)
- 短信/邮箱通知(初始密码靠管理员告知,不集成通知通道)
- 单点登录 / OAuth(后续独立专题)
- 移动端权限适配(本次只做 PC web)
