import { readFileSync, existsSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import assert from 'assert'

const __dirname = dirname(fileURLToPath(import.meta.url))
const app = readFileSync(resolve(__dirname, '../src/App.vue'), 'utf8')
const router = readFileSync(resolve(__dirname, '../src/router/index.ts'), 'utf8')
const api = readFileSync(resolve(__dirname, '../src/api/base.ts'), 'utf8')
const rbacSql = readFileSync(resolve(__dirname, '../../backend/database/migration_rbac.sql'), 'utf8')
const ddlSql = readFileSync(resolve(__dirname, '../../backend/database/court_finance_ddl.sql'), 'utf8')
const patchSql = readFileSync(resolve(__dirname, '../../backend/database/finance_standard_patch.sql'), 'utf8')
const controller = readFileSync(resolve(__dirname, '../../backend/app/finance/controller/Index.php'), 'utf8')
const periodModel = readFileSync(resolve(__dirname, '../../backend/app/finance/model/Period.php'), 'utf8')
const viewPath = resolve(__dirname, '../src/views/base/CarryForwardSchemeView.vue')
const modelPath = resolve(__dirname, '../../backend/app/finance/model/CarryForward.php')

assert(app.includes('menu:base:carry_forward'), 'sidebar should check carry-forward scheme menu permission')
assert(app.includes('index="/base/carry-forward-schemes"'), 'sidebar should route to carry-forward scheme page')
assert(app.includes('收支结转方案'), 'sidebar and title map should show carry-forward scheme label')

assert(router.includes("const CarryForwardSchemeView = () => import('../views/base/CarryForwardSchemeView.vue')"), 'router should lazy-load carry-forward scheme view')
assert(router.includes("path: '/base/carry-forward-schemes'"), 'router should register carry-forward scheme route')
assert(router.includes("permission: 'menu:base:carry_forward'"), 'carry-forward scheme route should require base menu permission')

assert(api.includes("apiAction('/carryForward/info'"), 'base API should expose carry-forward scheme info')
assert(api.includes("apiAction('/carryForward/save'"), 'base API should expose carry-forward scheme save')
assert(controller.includes('use app\\finance\\model\\CarryForward;'), 'controller should import carry-forward model')
assert(controller.includes("case 'carryForward':"), 'controller should route carry-forward actions')

assert(rbacSql.includes("('menu:base:carry_forward', '收支结转方案'"), 'RBAC seed should include carry-forward scheme menu permission')
assert(ddlSql.includes('create table fin_carry_forward_scheme'), 'core DDL should create carry-forward scheme table')
assert(patchSql.includes('create table if not exists fin_carry_forward_scheme'), 'standard patch should create carry-forward scheme table')
assert(existsSync(viewPath), 'carry-forward scheme view should exist')
assert(existsSync(modelPath), 'carry-forward backend model should exist')

const view = readFileSync(viewPath, 'utf8')
const model = readFileSync(modelPath, 'utf8')
assert(view.includes('收支结转方案'), 'view should title the feature as carry-forward scheme')
assert(view.includes('案款和诉讼费默认按本科目余额结转'), 'view should explain case-fund and litigation-fee balance rollover')
assert(view.includes('baseApi.carryForwardScheme'), 'view should load scheme through base API')
assert(view.includes('baseApi.saveCarryForwardScheme'), 'view should save scheme through base API')
assert(view.includes('PROFIT_LOSS'), 'view should guide source subjects from profit/loss subjects')
assert(model.includes("const TABLE = 'fin_carry_forward_scheme'"), 'model should use carry-forward scheme table')
assert(model.includes("requirePermission('base:edit')"), 'saving scheme should require base edit permission')
assert(model.includes('validateSubject'), 'model should validate source and target subjects')
assert(periodModel.includes('carryForwardSchemeStatus'), 'period year-end should read carry-forward scheme status')

console.log('Carry-forward scheme menu test passed')
