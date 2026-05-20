# 后端生成 PDF 打印改造记录

日期：2026-05-20

本文记录科目汇总表从前端 SVG/Canvas 图片式 PDF 改为后端 mPDF 生成 PDF 时遇到的问题、稳定做法，以及后续迁移科目余额表、凭证打印、明细账打印时需要复用的约束。

## 一、改造结论

后续账簿和凭证打印优先采用“后端接口生成 PDF，前端以 Blob 打开预览”的方式。

基本链路：

1. 前端点击打印。
2. 通过 `apiBlobAction()` 请求后端 PDF 接口。
3. 后端使用业务查询结果组装 HTML。
4. 后端使用 mPDF 生成真正的文本型 PDF。
5. 接口返回 `Content-Type: application/pdf` 和 `Content-Disposition: inline`。
6. 前端 `URL.createObjectURL(blob)` 后 `window.open()` 打开。

不要再用前端把表格渲染成 SVG/Canvas 图片后塞进 PDF。图片式 PDF 容易出现字体太小、清晰度差、分页不可控、内容不可复制检索、后续版式定制困难等问题。

## 二、已引入依赖

后端通过 Composer 安装：

```bash
cd backend
/Applications/MAMP/bin/php/php7.4.33/bin/php composer.phar require mpdf/mpdf:^8.0.4
```

相关文件：

1. `backend/composer.json`
2. `backend/composer.lock`
3. `backend/vendor/`，已被忽略，不提交

`backend/composer.phar` 是本地 Composer 启动器，已加入 `.gitignore`，不提交。

## 三、这次踩过的坑

### 1. 前端图片式 PDF 字体不清楚

原方式是前端把报表页面绘制成图片再写入 PDF。PDF 看起来像“截图”，不是文本表格。缩放后文字发虚，打印时字体也很小。

后续处理：

1. 报表类打印改为后端 mPDF。
2. PDF 中保留真实文字和表格线。
3. 前端只负责打开后端返回的 PDF Blob。

### 2. ThinkPHP 统一 JSON 包装会吞掉 PDF 响应

本项目所有接口默认经 `Index.php` 统一包装为 JSON。如果 PDF 接口也被包装，就会破坏二进制内容。

处理方式：

1. `backend/app/finance/controller/Index.php` 支持模型直接返回 `think\Response`。
2. 如果返回值是 `Response`，控制器直接 `return $data`。
3. PDF 接口自己设置响应头。

必须保留：

```php
if ($data instanceof Response) {
    return $data;
}
```

### 3. Composer 自动加载和项目已有类加载可能冲突

直接 `require backend/vendor/autoload.php` 在当前 ThinkPHP 5 项目里容易和已有自动加载逻辑产生静态类名冲突。

处理方式：

1. `composer.json` 设置 `autoloader-suffix` 做防御。
2. 代码里为 mPDF 注册最小 ClassLoader，只加载 mPDF 及其依赖命名空间。
3. 不在公共入口里全局 require Composer autoload。

后续新增 PDF 功能时，优先复用现有 `registerMpdfAutoloader()`，不要在每个模型里随意 `require vendor/autoload.php`。

### 4. mPDF 对复杂表格 CSS 很敏感

这次曾出现“生成 21 页空白 PDF，只有少量标题/表头/合计被拆到不同页”的问题。根因不是 mPDF 或中文字体不可用，而是 HTML/CSS 触发了 mPDF 分页异常。

已确认应避免：

1. `table-header-group`
2. `page-break-inside: avoid`
3. 强行 `tfoot`
4. `autosize="1"`
5. 复杂 `display: table/table-cell` 布局
6. 固定毫米行高加 `overflow: hidden`
7. 强行要求整张大表永远不分页

稳定做法：

1. 使用简单 HTML 表格。
2. 使用 `border="1" cellpadding="3" cellspacing="0"` 这类 mPDF 兼容属性。
3. CSS 只保留基础字体、边框、宽度、对齐、背景色。
4. 合计行放在 `tbody` 最后一行，不用 `tfoot`。
5. 数据为空时明确输出“暂无数据”行，不能生成纯空表。

### 5. “显示一页”不能靠强行禁止分页

用户希望打印不要分页，但如果数据行数超过 A4 横向页面可容纳的物理上限，强制一页可能造成字体过小或 mPDF 分页异常。

当前策略：

1. 优先保证 PDF 有数据、可读、顺序正确。
2. 行数少时通过横向 A4、窄边距、适度字号压缩，尽量落在一页。
3. 行数很多时允许自然分页，不能用会导致空白页的 CSS 强制一页。
4. 如业务必须一页，应增加报表条件，例如只打印末级科目、只打印有发生额科目、按科目级次过滤。

### 6. PDF 顺序必须来自同一套业务排序

科目汇总表页面不是简单按 `subject_code` 扁平排序，而是：

1. 先按科目类别顺序：资产、负债、共同、权益、成本、损益。
2. 再按科目层级组织树。
3. 子科目紧跟父科目。
4. 同级再按科目编码自然排序。

PDF 如果直接使用后端 `ksort()` 结果，会和页面显示顺序不一致。

处理方式：

1. 后端 `subjectSummary()` 结果层统一排序。
2. 页面、导出、PDF 共用同一个接口结果。
3. 新增测试验证乱序输入会按科目树输出。

后续科目余额表也要注意同样问题：不要前端一套排序、后端打印另一套排序。

### 7. 金额合计仍要用“分”计算

财务金额合计不能直接依赖 PHP 浮点加减。科目汇总表 PDF 合计行沿用项目金额规则：

1. 转为整数分累加。
2. 最后再格式化为元。
3. 合计口径优先使用末级科目，避免父子级重复相加。

后续科目余额表、明细账、凭证打印仍必须遵守该规则。

### 8. 中文字体与临时目录

mPDF 初始化建议保留：

```php
'mode' => 'zh-CN+aCJK',
'autoScriptToLang' => true,
'autoLangToFont' => true,
'useAdobeCJK' => true,
'tempDir' => backend/runtime/mpdf,
```

注意：

1. `runtime/mpdf` 必须可写。
2. 不要把 mPDF 临时文件提交。
3. 出现中文乱码时先检查 mPDF 初始化参数，不要回退到图片式 PDF。

## 四、当前科目汇总表实现要点

后端：

1. `/book/subjectSummaryPdf`
2. 先调用 `subjectSummary()` 获取同口径数据。
3. 使用 mPDF 生成 A4 横向 PDF。
4. 只显示四列：科目编码、科目名称、金额合计借方、金额合计贷方。
5. 返回 `application/pdf`。

前端：

1. `frontend/src/api/http.ts` 新增 `apiBlobAction()`。
2. `frontend/src/api/books.ts` 新增 `subjectSummaryPdf()`。
3. `SubjectSummaryView.vue` 打印调用后端 PDF 接口。
4. 前端页面和导出也只保留四列。

验证：

1. `php -l backend/app/finance/model/Book.php`
2. `php -l backend/app/finance/controller/Index.php`
3. `php backend/tests/BookSubjectSummaryTest.php`
4. `node frontend/tests/BookSubjectSummaryViewTest.mjs`
5. `npm run build`
6. 用 `curl` 请求 PDF，确认文件头为 `%PDF`、`Content-Type` 为 `application/pdf`。
7. 用 `pdfinfo` 看页数，用 `pdftotext` 看文字是否真实存在。

## 五、后续迁移建议

### 1. 科目余额表打印

建议接口：

1. `/book/subjectBalancePdf`
2. 复用 `subjectBalance()` 查询和过滤。
3. 后端统一处理科目树排序，避免页面和 PDF 顺序不一致。
4. 合计金额继续按分计算。

注意：

1. 科目余额表列更多，不要盲目全部塞进一页。
2. 优先评估保留标准列：期初借方、期初贷方、本期借方、本期贷方、累计借方、累计贷方、期末借方、期末贷方。
3. 如果列太多，可采用 A4 横向、更小字号，必要时按用户选择的列打印。
4. 不要使用复杂表头跨行跨列样式，mPDF 对复杂表头分页更容易出问题。

### 2. 明细账打印

建议接口：

1. `/book/detailLedgerPdf`
2. 复用 `detailLedger()` 查询口径。
3. PDF 打印时要支持完整数据，不能只打印当前分页数据。
4. 排序保持 `voucher_date asc, voucher_no asc, line_no asc`。

注意：

1. 明细账天然可能多页，不应强制一页。
2. 标题区要显示账套、期间、日期范围、科目。
3. 摘要和辅助核算摘要可能较长，需允许换行，不能 `overflow:hidden` 截断关键业务信息。
4. 合计行使用接口中的借贷合计，或后端重新按分计算。

### 3. 凭证打印

建议接口：

1. `/voucher/printPdf`
2. 输入凭证 ID 列表。
3. 后端查询凭证头、分录、辅助核算、账套打印参数。
4. 后端生成 PDF 后，再按原业务规则调用或复用打印标记逻辑。

注意：

1. 凭证打印要继续使用账套参数：纸张尺寸、每张凭证打印分录行数。
2. 多张凭证应一张凭证一页或按配置分页，不能像报表一样连续表格。
3. 保留凭证字号、凭证号、日期、附件张数、制单人、审核人、记账人等标准字段。
4. 分录行数不足时按打印配置补空行，保证版式稳定。
5. 已审核、已记账、已打印、作废等状态规则仍以后端为准。
6. 打印成功后是否标记 `printed_flag`，必须保持现有内控规则，不要只在前端做状态变化。

## 六、推荐抽象

后续如果第二个打印功能也迁移成功，建议把公共能力抽到后端共享方法，避免每个模型复制一份：

1. mPDF 加载与初始化。
2. PDF 响应头。
3. HTML 转义。
4. 金额格式化。
5. 报表基础 CSS。
6. 空数据行。
7. 文本型 PDF 验证测试。

可以考虑新增：

```text
backend/app/finance/model/PdfReport.php
```

或在 `Common.php` 中放少量通用 helper。抽象前不要过早重构，先等科目余额表和明细账验证完，确认样式需求稳定。

## 七、测试护栏

每迁移一个打印功能，至少补以下测试：

1. 后端路由存在。
2. 后端使用 mPDF，而不是前端图片 PDF。
3. 响应头是 `application/pdf` 和 `inline`。
4. 空数据时有明确提示行，不生成空白 PDF。
5. 排序与页面一致。
6. 金额合计按财务口径计算。
7. 前端调用 `apiBlobAction()`。
8. 前端不再调用旧的 `createReportPrintPdf()` 或 `createVoucherPrintPdf()`。
9. `npm run build` 通过。

人工验证建议：

1. 浏览器里打开 PDF。
2. 放大后文字仍清晰。
3. 能复制 PDF 中的文字。
4. 用系统打印预览查看分页。
5. 用 `pdfinfo` 检查页数。
6. 用 `pdftotext` 检查内容不是空的。

