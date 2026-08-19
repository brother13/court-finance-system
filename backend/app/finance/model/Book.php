<?php

namespace app\finance\model;

class Book extends Common
{
    const ACTION = 'book';
    const DETAIL_LEDGER_PDF_PAGE_SIZE = 30;
    const DETAIL_LEDGER_PDF_QUERY_PAGE_SIZE = 800;
    const CASE_MOVEMENT_SNAPSHOT_FRESH_SECONDS = 60;

    public function index($action = '', $data = [])
    {
        switch ($action) {
            case 'detailLedger':
                return $this->detailLedger($data);
            case 'detailLedgerPdf':
                return $this->detailLedgerPdf($data);
            case 'detailLedgerPdfJob':
                return $this->detailLedgerPdfJob($data);
            case 'detailLedgerPdfJobStatus':
                return $this->detailLedgerPdfJobStatus($data);
            case 'detailLedgerPdfJobDownload':
                return $this->detailLedgerPdfJobDownload($data);
            case 'subjectBalance':
                return $this->subjectBalance($data);
            case 'subjectBalancePdf':
                return $this->subjectBalancePdf($data);
            case 'subjectSummary':
                return $this->subjectSummary($data);
            case 'subjectSummaryPdf':
                return $this->subjectSummaryPdf($data);
            case 'auxBalanceSubjects':
                return $this->auxBalanceSubjects($data);
            case 'auxBalance':
                return $this->auxBalance($data);
            case 'caseMovementDetail':
                return $this->caseMovementDetail($data);
            default:
                return $this->error('操作【/' . self::ACTION . '/' . $action . '】并不存在！');
        }
    }

    public function detailLedger($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $period = trim((string)($data['period'] ?? ''));
        $startPeriod = trim((string)($data['start_period'] ?? ''));
        $endPeriod = trim((string)($data['end_period'] ?? ''));
        if ($startPeriod === '') {
            $startPeriod = $period;
        }
        if ($endPeriod === '') {
            $endPeriod = $period;
        }
        if ($startPeriod === '' || $endPeriod === '') {
            return $this->error('会计期间不能为空');
        }
        if (!$this->validPeriod($startPeriod) || !$this->validPeriod($endPeriod)) {
            return $this->error('会计期间格式不正确');
        }
        if (substr($startPeriod, 0, 4) !== substr($endPeriod, 0, 4)) {
            return $this->error('明细账暂不支持跨年度查询');
        }
        if ($startPeriod > $endPeriod) {
            return $this->error('起始期间不能晚于结束期间');
        }
        $startDate = trim((string)($data['start_date'] ?? ''));
        $endDate = trim((string)($data['end_date'] ?? ''));
        if ($startDate === '') {
            $startDate = substr($startPeriod, 0, 7) . '-01';
        }
        if ($endDate === '') {
            $endDate = date('Y-m-t', strtotime($endPeriod . '-01'));
        }
        $subjectCode = $data['subject_code'] ?? '';
        $page = max(1, (int)($data['page'] ?? 1));
        $pageSize = max(1, min(200, (int)($data['pagesize'] ?? 100)));
        $fiscalYear = $this->fiscalYear($endPeriod);
        $voucherTable = 'fin_voucher';
        $detailTable = 'fin_voucher_detail';

        $where = [
            'd.account_set_id' => $this->accountSetId,
            'd.fiscal_year' => $fiscalYear,
            'd.del_flag' => 0,
            'v.fiscal_year' => $fiscalYear,
            'v.del_flag' => 0,
            'v.void_flag' => 0,
            'v.voucher_date' => ['between', [$startDate, $endDate]],
        ];
        $codeRule = $this->subjectCodeRule();
        $lengths = $this->subjectCodeRuleLengths($codeRule);
        $maxLength = empty($lengths) ? 10 : max($lengths);

        $buildQuery = function ($withSubjectName = false) use ($detailTable, $voucherTable, $where, $startPeriod, $endPeriod, $subjectCode, $maxLength) {
            $query = db($detailTable)->alias('d')
                ->force('idx_voucher_detail_ledger_subject')
                ->join($voucherTable . ' v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
                ->where($where)
                ->where('d.period', 'between', [$startPeriod, $endPeriod])
                ->where('v.period', 'between', [$startPeriod, $endPeriod]);
            if ($withSubjectName) {
                $query->join('fin_subject s', 's.subject_code=d.subject_code and s.account_set_id=d.account_set_id and s.del_flag=0', 'LEFT');
            }
            if ($subjectCode !== '') {
                $query->where('d.subject_code', '>=', $subjectCode);
                $query->where('d.subject_code', '<=', $this->subjectRangeUpperBound($subjectCode, $maxLength));
            }
            return $query;
        };

        $total = (int)$buildQuery()->count();
        $debitTotal = $buildQuery()->sum('d.debit_amount');
        $creditTotal = $buildQuery()->sum('d.credit_amount');
        $rows = $buildQuery(true)
            ->field('v.voucher_id,v.period,v.voucher_date,v.voucher_no,d.summary,d.subject_code,s.subject_name,d.debit_amount,d.credit_amount,d.aux_desc')
            ->order('v.voucher_date asc,v.voucher_no asc,d.line_no asc')
            ->page($page, $pageSize)
            ->select();

        return $this->ok([
            'items' => $rows,
            'total' => $total,
            'debit_total' => $this->centsToDecimal($this->decimalToCents($debitTotal ?: 0)),
            'credit_total' => $this->centsToDecimal($this->decimalToCents($creditTotal ?: 0)),
        ], 'OK', $total);
    }

    public function detailLedgerPdf($data = [])
    {
        $progressCallback = $data['_progress_callback'] ?? null;
        $progressCallback = is_callable($progressCallback) ? $progressCallback : null;
        $rowsPerPdfPage = self::DETAIL_LEDGER_PDF_PAGE_SIZE;
        $queryPageSize = self::DETAIL_LEDGER_PDF_QUERY_PAGE_SIZE;
        $firstResult = $this->loadDetailLedgerSummaryForPdf($data);
        if (($firstResult['code'] ?? self::CODE_SUCCESS) !== self::CODE_SUCCESS) {
            return $firstResult;
        }
        $firstPayload = $firstResult['data'] ?? [];
        $total = (int)($firstPayload['total'] ?? count($firstPayload['items'] ?? []));
        $pdfPageCount = max(1, (int)ceil($total / $rowsPerPdfPage));
        $queryPageCount = (int)ceil($total / $queryPageSize);
        if ($progressCallback) {
            $progressCallback([
                'progress' => $total === 0 ? 20 : 1,
                'total_rows' => $total,
                'generated_pages' => 0,
                'total_pages' => $pdfPageCount,
                'message' => $total === 0 ? '暂无明细账数据，正在生成空报表' : '正在读取明细账数据',
            ]);
        }

        $vendorDir = __DIR__ . '/../../../vendor';
        if (!is_dir($vendorDir . '/mpdf/mpdf')) {
            return $this->error('PDF组件未安装，请先在backend目录安装mpdf/mpdf');
        }
        $this->registerMpdfAutoloader($vendorDir);

        $startPeriod = trim((string)($data['start_period'] ?? ($data['period'] ?? '')));
        $endPeriod = trim((string)($data['end_period'] ?? ($data['period'] ?? '')));
        $subjectCode = trim((string)($data['subject_code'] ?? ''));
        $disposition = $this->pdfDisposition($data['disposition'] ?? 'inline');
        $accountSetName = db('fin_account_set')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->value('set_name') ?: '当前账套';
        $subjectName = '';
        if ($subjectCode !== '') {
            $subjectName = db('fin_subject')
                ->where([
                    'account_set_id' => $this->accountSetId,
                    'subject_code' => $subjectCode,
                    'del_flag' => 0,
                ])
                ->value('subject_name') ?: '';
        }

        $tempDir = __DIR__ . '/../../../runtime/mpdf';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $rowCount = max(1, $total);
        $fontSizePt = 8.4;
        if ($rowCount > 32) {
            $fontSizePt = 7.8;
        }
        if ($rowCount > 55) {
            $fontSizePt = 7.1;
        }

        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'zh-CN+aCJK',
                'format' => 'A4-L',
                'tempDir' => $tempDir,
                'margin_left' => 7,
                'margin_right' => 7,
                'margin_top' => 7,
                'margin_bottom' => 7,
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'useAdobeCJK' => true,
            ]);
            $mpdf->SetTitle('明细账');
            $mpdf->SetDisplayMode('fullpage');
            $mpdf->SetFooter('第 {PAGENO} / {nbpg} 页');
            $mpdf->WriteHTML($this->detailLedgerPdfStyles($fontSizePt), \Mpdf\HTMLParserMode::HEADER_CSS);
            if ($total === 0) {
                $mpdf->WriteHTML($this->buildDetailLedgerPdfPageHtml(
                    [],
                    $accountSetName,
                    $startPeriod,
                    $endPeriod,
                    $subjectCode,
                    $subjectName,
                    $firstPayload['debit_total'] ?? 0,
                    $firstPayload['credit_total'] ?? 0,
                    '',
                    $fontSizePt
                ));
                if ($progressCallback) {
                    $progressCallback([
                        'progress' => 80,
                        'total_rows' => 0,
                        'generated_pages' => 1,
                        'total_pages' => 1,
                        'message' => '正在写入PDF文件',
                    ]);
                }
            } else {
                $pdfPageNo = 0;
                for ($queryPage = 1; $queryPage <= $queryPageCount; $queryPage++) {
                    $pageResult = $this->loadDetailLedgerRowsPageOnlyForPdf($data, $queryPage, $queryPageSize);
                    if (($pageResult['code'] ?? self::CODE_ERROR) !== self::CODE_SUCCESS) {
                        return $pageResult;
                    }
                    foreach (array_chunk($pageResult['data']['items'] ?? [], $rowsPerPdfPage) as $rows) {
                        $pdfPageNo++;
                        if ($pdfPageNo > 1) {
                            $mpdf->AddPage();
                        }
                        $isLastPage = $pdfPageNo === $pdfPageCount;
                        $mpdf->WriteHTML($this->buildDetailLedgerPdfPageHtml(
                            $rows,
                            $accountSetName,
                            $startPeriod,
                            $endPeriod,
                            $subjectCode,
                            $subjectName,
                            $isLastPage ? ($firstPayload['debit_total'] ?? 0) : null,
                            $isLastPage ? ($firstPayload['credit_total'] ?? 0) : null,
                            '',
                            $fontSizePt
                        ));
                        if ($progressCallback) {
                            $progress = min(95, max(1, (int)floor($pdfPageNo * 95 / $pdfPageCount)));
                            $progressCallback([
                                'progress' => $progress,
                                'total_rows' => $total,
                                'generated_pages' => $pdfPageNo,
                                'total_pages' => $pdfPageCount,
                                'message' => '正在生成第' . $pdfPageNo . '/' . $pdfPageCount . '页PDF',
                            ]);
                        }
                    }
                }
            }
            $pdf = $mpdf->Output('', 'S');
            if ($progressCallback) {
                $progressCallback([
                    'progress' => 98,
                    'total_rows' => $total,
                    'generated_pages' => $pdfPageCount,
                    'total_pages' => $pdfPageCount,
                    'message' => 'PDF已生成，正在保存文件',
                ]);
            }
        } catch (\Exception $e) {
            return $this->error('生成明细账PDF失败：' . $e->getMessage());
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="detail-ledger.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function detailLedgerPdfJob($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $this->ensureDetailLedgerPdfJobDir();
        $jobId = uuid();
        $job = [
            'job_id' => $jobId,
            'status' => 'QUEUED',
            'created_time' => $this->now(),
            'updated_time' => $this->now(),
            'account_set_id' => $this->accountSetId,
            'year' => $this->year,
            'user_id' => $this->userid,
            'user_name' => $this->username,
            'params' => array_merge($data, ['disposition' => 'attachment']),
            'pdf_path' => $this->detailLedgerPdfJobPdfPath($jobId),
            'log_path' => $this->detailLedgerPdfJobLogPath($jobId),
            'message' => '任务已创建',
            'error' => '',
            'progress' => 0,
            'total_rows' => 0,
            'generated_pages' => 0,
            'total_pages' => 0,
        ];
        $this->writeDetailLedgerPdfJob($job);
        $startResult = $this->startDetailLedgerPdfJobProcess($jobId);
        if (!($startResult['ok'] ?? false)) {
            $job = $this->readDetailLedgerPdfJob($jobId) ?: $job;
            $job['status'] = 'FAILED';
            $job['updated_time'] = $this->now();
            $job['message'] = 'PDF任务启动失败';
            $job['error'] = $startResult['error'] ?? '后台PDF任务启动失败';
            $this->writeDetailLedgerPdfJob($job);
            return $this->error($job['error']);
        }

        $job = $this->readDetailLedgerPdfJob($jobId) ?: $job;
        return $this->ok($this->publicDetailLedgerPdfJob($job), 'OK');
    }

    public function detailLedgerPdfJobStatus($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $job = $this->readDetailLedgerPdfJob(trim((string)($data['job_id'] ?? '')));
        if (!$job) {
            return $this->error('PDF任务不存在或已清理');
        }
        if (($job['account_set_id'] ?? '') !== $this->accountSetId) {
            return $this->error('无权访问该PDF任务');
        }
        $job = $this->refreshDetailLedgerPdfJobStatus($job);

        return $this->ok($this->publicDetailLedgerPdfJob($job), 'OK');
    }

    public function detailLedgerPdfJobDownload($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $job = $this->readDetailLedgerPdfJob(trim((string)($data['job_id'] ?? '')));
        if (!$job) {
            return $this->error('PDF任务不存在或已清理');
        }
        if (($job['account_set_id'] ?? '') !== $this->accountSetId) {
            return $this->error('无权访问该PDF任务');
        }
        if (($job['status'] ?? '') !== 'DONE') {
            return $this->error('PDF任务尚未完成');
        }
        $path = $job['pdf_path'] ?? '';
        if ($path === '' || !is_file($path)) {
            return $this->error('PDF文件不存在或已清理');
        }

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="detail-ledger.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function subjectBalance($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $period = trim((string)($data['period'] ?? ''));
        $startPeriod = trim((string)($data['start_period'] ?? ''));
        $endPeriod = trim((string)($data['end_period'] ?? ''));
        if ($startPeriod === '') {
            $startPeriod = $period;
        }
        if ($endPeriod === '') {
            $endPeriod = $period;
        }
        if ($startPeriod === '' || $endPeriod === '') {
            return $this->error('会计期间不能为空');
        }
        if (!$this->validPeriod($startPeriod) || !$this->validPeriod($endPeriod)) {
            return $this->error('会计期间格式不正确');
        }
        if (substr($startPeriod, 0, 4) !== substr($endPeriod, 0, 4)) {
            return $this->error('科目余额表暂不支持跨年度查询');
        }
        if ($startPeriod > $endPeriod) {
            return $this->error('起始期间不能晚于结束期间');
        }
        $codeRule = $this->subjectCodeRule();
        $lengths = $this->subjectCodeRuleLengths($codeRule);
        $maxLevel = empty($lengths) ? 1 : count($lengths);
        $maxLength = empty($lengths) ? 10 : max($lengths);
        $subjectStartCode = trim((string)($data['subject_start_code'] ?? ''));
        $subjectEndCode = trim((string)($data['subject_end_code'] ?? ''));
        $levelStart = (int)($data['level_start'] ?? 1);
        $levelEnd = (int)($data['level_end'] ?? $maxLevel);
        if ($levelStart < 1) {
            $levelStart = 1;
        }
        if ($levelEnd < 1) {
            $levelEnd = 1;
        }
        if ($levelStart > $maxLevel) {
            $levelStart = $maxLevel;
        }
        if ($levelEnd > $maxLevel) {
            $levelEnd = $maxLevel;
        }
        if ($levelStart > $levelEnd) {
            $tmp = $levelStart;
            $levelStart = $levelEnd;
            $levelEnd = $tmp;
        }
        $zeroFilter = $data['zero_filter'] ?? 'all';
        if (!in_array($zeroFilter, ['all', 'nonzero', 'active', 'balance'], true)) {
            $zeroFilter = 'all';
        }
        $subjects = db('fin_subject')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->field('subject_code,subject_name,direction,subject_type,level_no,leaf_flag')
            ->order('subject_code asc')
            ->select();
        $subjectMap = [];
        $balances = [];
        foreach ($subjects as $subject) {
            $subjectMap[$subject['subject_code']] = $subject;
            $balances[$subject['subject_code']] = [
                'subject_code' => $subject['subject_code'],
                'subject_name' => $subject['subject_name'],
                'direction' => $subject['direction'],
                'subject_type' => $subject['subject_type'],
                'level_no' => (int)($subject['level_no'] ?? 1),
                'leaf_flag' => (int)($subject['leaf_flag'] ?? 1),
                'opening_debit_cents' => 0,
                'opening_credit_cents' => 0,
                'current_debit_cents' => 0,
                'current_credit_cents' => 0,
                'year_debit_cents' => 0,
                'year_credit_cents' => 0,
            ];
        }

        $openingRows = db('fin_opening_balance')
            ->where([
                'account_set_id' => $this->accountSetId,
                'period' => $this->openingPeriodFor($startPeriod, 'fin_opening_balance'),
                'del_flag' => 0,
            ])
            ->field('subject_code,debit_amount,credit_amount')
            ->select();
        foreach ($openingRows as $row) {
            $this->addSubjectBalanceAmount(
                $balances,
                $row['subject_code'],
                $subjectMap,
                $codeRule,
                'opening',
                $this->decimalToCents($row['debit_amount'] ?? 0),
                $this->decimalToCents($row['credit_amount'] ?? 0)
            );
        }

        $currentRows = $this->loadSubjectBalanceVoucherRows($startPeriod, $endPeriod);
        foreach ($currentRows as $row) {
            $debitCents = $this->decimalToCents($row['debit_amount'] ?? 0);
            $creditCents = $this->decimalToCents($row['credit_amount'] ?? 0);
            $this->addSubjectBalanceAmount(
                $balances,
                $row['subject_code'],
                $subjectMap,
                $codeRule,
                'current',
                $debitCents,
                $creditCents
            );
        }

        $yearStartPeriod = $this->reportYearStartPeriod($endPeriod);
        $yearRows = $yearStartPeriod === $startPeriod
            ? $currentRows
            : $this->loadSubjectBalanceVoucherRows($yearStartPeriod, $endPeriod);
        foreach ($yearRows as $row) {
            $debitCents = $this->decimalToCents($row['debit_amount'] ?? 0);
            $creditCents = $this->decimalToCents($row['credit_amount'] ?? 0);
            $this->addSubjectBalanceAmount(
                $balances,
                $row['subject_code'],
                $subjectMap,
                $codeRule,
                'year',
                $debitCents,
                $creditCents
            );
        }

        $rows = [];
        foreach ($balances as $balance) {
            $row = $this->formatSubjectBalanceRow($balance);
            if (!$this->subjectBalanceRowVisible($row, $subjectStartCode, $subjectEndCode, $levelStart, $levelEnd, $maxLength, $zeroFilter)) {
                continue;
            }
            $rows[] = $row;
        }

        return $this->ok($rows, 'OK', count($rows));
    }

    public function subjectBalancePdf($data = [])
    {
        $result = $this->subjectBalance($data);
        if (($result['code'] ?? self::CODE_ERROR) !== self::CODE_SUCCESS) {
            return $result;
        }

        $vendorDir = __DIR__ . '/../../../vendor';
        if (!is_dir($vendorDir . '/mpdf/mpdf')) {
            return $this->error('PDF组件未安装，请先在backend目录安装mpdf/mpdf');
        }
        $this->registerMpdfAutoloader($vendorDir);

        $rows = $result['data'] ?? [];
        $displayRows = $this->subjectBalanceDisplayRows($rows);
        $totals = $this->subjectBalanceTotals($rows);

        $startPeriod = trim((string)($data['start_period'] ?? ($data['period'] ?? '')));
        $endPeriod = trim((string)($data['end_period'] ?? ($data['period'] ?? '')));
        $accountSetName = db('fin_account_set')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->value('set_name') ?: '当前账套';

        $tempDir = __DIR__ . '/../../../runtime/mpdf';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $rowCount = max(1, count($displayRows));
        $fontSizePt = 7.8;
        if ($rowCount > 34) {
            $fontSizePt = 7.2;
        }
        if ($rowCount > 52) {
            $fontSizePt = 6.6;
        }

        $html = $this->buildSubjectBalancePdfHtml(
            $displayRows,
            $accountSetName,
            $startPeriod,
            $endPeriod,
            $totals,
            $fontSizePt
        );

        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'zh-CN+aCJK',
                'format' => 'A4-L',
                'tempDir' => $tempDir,
                'margin_left' => 6,
                'margin_right' => 6,
                'margin_top' => 7,
                'margin_bottom' => 7,
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'useAdobeCJK' => true,
            ]);
            $mpdf->SetTitle('科目余额表');
            $mpdf->SetDisplayMode('fullpage');
            $mpdf->WriteHTML($html);
            $pdf = $mpdf->Output('', 'S');
        } catch (\Exception $e) {
            return $this->error('生成科目余额表PDF失败：' . $e->getMessage());
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="subject-balance.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function subjectSummary($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $period = trim((string)($data['period'] ?? ''));
        $startPeriod = trim((string)($data['start_period'] ?? ''));
        $endPeriod = trim((string)($data['end_period'] ?? ''));
        if ($startPeriod === '') {
            $startPeriod = $period;
        }
        if ($endPeriod === '') {
            $endPeriod = $period;
        }
        if ($startPeriod === '' || $endPeriod === '') {
            return $this->error('会计期间不能为空');
        }
        if (!$this->validPeriod($startPeriod) || !$this->validPeriod($endPeriod)) {
            return $this->error('会计期间格式不正确');
        }
        if (substr($startPeriod, 0, 4) !== substr($endPeriod, 0, 4)) {
            return $this->error('科目汇总表暂不支持跨年度查询');
        }
        if ($startPeriod > $endPeriod) {
            return $this->error('起始期间不能晚于结束期间');
        }
        $subjectStartCode = trim((string)($data['subject_start_code'] ?? ''));
        $subjectEndCode = trim((string)($data['subject_end_code'] ?? ''));
        $codeRule = $this->subjectCodeRule();
        $lengths = $this->subjectCodeRuleLengths($codeRule);
        $maxLength = empty($lengths) ? 10 : max($lengths);

        $subjects = db('fin_subject')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->field('subject_code,subject_name,level_no,direction,subject_type,leaf_flag')
            ->select();
        $subjectMap = [];
        foreach ($subjects as $subject) {
            $subjectMap[$subject['subject_code']] = $subject;
        }

        $fiscalYear = $this->fiscalYear($endPeriod);
        $voucherTable = 'fin_voucher';
        $detailTable = 'fin_voucher_detail';
        $where = [
            'd.account_set_id' => $this->accountSetId,
            'd.fiscal_year' => $fiscalYear,
            'd.del_flag' => 0,
            'v.fiscal_year' => $fiscalYear,
            'v.del_flag' => 0,
            'v.void_flag' => 0,
        ];

        $query = db($detailTable)->alias('d')
            ->join($voucherTable . ' v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
            ->where($where)
            ->where('d.period', 'between', [$startPeriod, $endPeriod])
            ->where('v.period', 'between', [$startPeriod, $endPeriod]);
        if ($subjectStartCode !== '') {
            $query->where('d.subject_code', '>=', $subjectStartCode);
        }
        if ($subjectEndCode !== '') {
            $query->where('d.subject_code', '<=', $this->subjectRangeUpperBound($subjectEndCode, $maxLength));
        }
        $details = $query
            ->field('d.subject_code,d.debit_amount,d.credit_amount')
            ->order('d.subject_code asc')
            ->select();

        $summary = [];
        foreach ($details as $detail) {
            foreach ($this->ancestorSubjectCodes($detail['subject_code'], $subjectMap, $codeRule) as $summaryCode) {
                if ($subjectStartCode !== '' && strcmp($summaryCode, $subjectStartCode) < 0) {
                    continue;
                }
                if ($subjectEndCode !== '' && strcmp($summaryCode, $this->subjectRangeUpperBound($subjectEndCode, $maxLength)) > 0) {
                    continue;
                }
                if (!isset($summary[$summaryCode])) {
                    $subject = $subjectMap[$summaryCode] ?? [
                        'subject_code' => $summaryCode,
                        'subject_name' => '',
                        'level_no' => 1,
                        'direction' => '',
                        'subject_type' => '',
                        'leaf_flag' => 1,
                    ];
                    $summary[$summaryCode] = [
                        'subject_code' => $summaryCode,
                        'subject_name' => $subject['subject_name'] ?? '',
                        'level_no' => (int)($subject['level_no'] ?? 1),
                        'direction' => $subject['direction'] ?? '',
                        'subject_type' => $subject['subject_type'] ?? '',
                        'leaf_flag' => (int)($subject['leaf_flag'] ?? 1),
                        'debit_cents' => 0,
                        'credit_cents' => 0,
                        'entry_count' => 0,
                    ];
                }
                $summary[$summaryCode]['debit_cents'] += $this->decimalToCents($detail['debit_amount']);
                $summary[$summaryCode]['credit_cents'] += $this->decimalToCents($detail['credit_amount']);
                $summary[$summaryCode]['entry_count']++;
            }
        }
        ksort($summary);

        $rows = [];
        foreach ($summary as $item) {
            $debitCents = $item['debit_cents'];
            $creditCents = $item['credit_cents'];
            $rows[] = [
                'subject_code' => $item['subject_code'],
                'subject_name' => $item['subject_name'],
                'level_no' => $item['level_no'],
                'direction' => $item['direction'],
                'subject_type' => $item['subject_type'],
                'leaf_flag' => $item['leaf_flag'],
                'debit_amount' => $this->centsToDecimal($debitCents),
                'credit_amount' => $this->centsToDecimal($creditCents),
                'balance_amount' => $this->centsToDecimal($debitCents - $creditCents),
                'entry_count' => $item['entry_count'],
            ];
        }
        $rows = $this->sortSubjectSummaryRows($rows);

        return $this->ok($rows, 'OK', count($rows));
    }

    public function subjectSummaryPdf($data = [])
    {
        $result = $this->subjectSummary($data);
        if (($result['code'] ?? self::CODE_ERROR) !== self::CODE_SUCCESS) {
            return $result;
        }

        $vendorDir = __DIR__ . '/../../../vendor';
        if (!is_dir($vendorDir . '/mpdf/mpdf')) {
            return $this->error('PDF组件未安装，请先在backend目录安装mpdf/mpdf');
        }
        $this->registerMpdfAutoloader($vendorDir);

        $rows = $result['data'] ?? [];
        $summaryRows = array_filter($rows, function ($row) {
            return (int)($row['leaf_flag'] ?? 1) === 1;
        });
        if (empty($summaryRows)) {
            $summaryRows = $rows;
        }

        $totalDebitCents = 0;
        $totalCreditCents = 0;
        foreach ($summaryRows as $row) {
            $totalDebitCents += $this->decimalToCents($row['debit_amount'] ?? 0);
            $totalCreditCents += $this->decimalToCents($row['credit_amount'] ?? 0);
        }

        $startPeriod = trim((string)($data['start_period'] ?? ($data['period'] ?? '')));
        $endPeriod = trim((string)($data['end_period'] ?? ($data['period'] ?? '')));
        $accountSetName = db('fin_account_set')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->value('set_name') ?: '当前账套';

        $tempDir = __DIR__ . '/../../../runtime/mpdf';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $rowCount = max(1, count($rows));
        $fontSizePt = 9.5;
        if ($rowCount > 42) {
            $fontSizePt = 8.5;
        }
        if ($rowCount > 58) {
            $fontSizePt = 7.5;
        }
        if ($rowCount > 76) {
            $fontSizePt = 6.8;
        }

        $html = $this->buildSubjectSummaryPdfHtml(
            $rows,
            $accountSetName,
            $startPeriod,
            $endPeriod,
            $this->centsToDecimal($totalDebitCents),
            $this->centsToDecimal($totalCreditCents),
            $fontSizePt
        );

        try {
            $mpdf = new \Mpdf\Mpdf([
                'mode' => 'zh-CN+aCJK',
                'format' => 'A4-L',
                'tempDir' => $tempDir,
                'margin_left' => 8,
                'margin_right' => 8,
                'margin_top' => 8,
                'margin_bottom' => 8,
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
                'useAdobeCJK' => true,
            ]);
            $mpdf->SetTitle('科目汇总表');
            $mpdf->SetDisplayMode('fullpage');
            $mpdf->WriteHTML($html);
            $pdf = $mpdf->Output('', 'S');
        } catch (\Exception $e) {
            return $this->error('生成科目汇总表PDF失败：' . $e->getMessage());
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="subject-summary.pdf"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    protected function buildSubjectSummaryPdfHtml($rows, $accountSetName, $startPeriod, $endPeriod, $totalDebit, $totalCredit, $fontSizePt)
    {
        $escape = function ($value) {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        };
        $money = function ($value) {
            return $this->decimalToCents($value) === 0 ? '' : number_format((float)$value, 2, '.', ',');
        };
        $body = '';
        foreach ($rows as $row) {
            $level = max(1, (int)($row['level_no'] ?? 1));
            $indent = str_repeat('&nbsp;&nbsp;', $level - 1);
            $body .= '<tr>';
            $body .= '<td class="code">' . $escape($row['subject_code'] ?? '') . '</td>';
            $body .= '<td class="name">' . $indent . $escape($row['subject_name'] ?? '未维护名称') . '</td>';
            $body .= '<td class="amount">' . $escape($money($row['debit_amount'] ?? 0)) . '</td>';
            $body .= '<td class="amount">' . $escape($money($row['credit_amount'] ?? 0)) . '</td>';
            $body .= '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td class="empty" colspan="4">暂无科目汇总数据</td></tr>';
        }
        $body .= '<tr class="total-row">'
            . '<td class="code">合计</td>'
            . '<td class="name"></td>'
            . '<td class="amount">' . $escape(number_format((float)$totalDebit, 2, '.', ',')) . '</td>'
            . '<td class="amount">' . $escape(number_format((float)$totalCredit, 2, '.', ',')) . '</td>'
            . '</tr>';

        return '<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: gb, sun-exta, "SimSun", serif; color: #111; font-size: ' . $fontSizePt . 'pt; }
    h1 { text-align: center; margin: 0 0 8px; font-size: 16pt; font-weight: bold; }
    .meta { width: 100%; margin-bottom: 8px; font-size: 8pt; }
    .meta td { border: 0; padding: 0; }
    .meta-right { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #111; padding: 3px 5px; }
    th { text-align: center; font-weight: bold; background: #f2f2f2; }
    .code { width: 18%; text-align: center; }
    .name { width: 42%; }
    .amount { width: 20%; text-align: right; font-family: dejavusansmono, gb, sun-exta, monospace; }
    .empty { text-align: center; color: #666; }
    .total-row td { font-weight: bold; background: #f7f7f7; }
  </style>
</head>
<body>
  <h1>科目汇总表</h1>
  <table class="meta" border="0" cellpadding="0" cellspacing="0">
    <tr>
      <td>账套：' . $escape($accountSetName) . '</td>
      <td class="meta-right">期间：' . $escape($startPeriod) . ' 至 ' . $escape($endPeriod) . '</td>
    </tr>
  </table>
  <table border="1" cellpadding="3" cellspacing="0">
    <thead>
      <tr>
        <th class="code">科目编码</th>
        <th class="name">科目名称</th>
        <th class="amount">金额合计借方</th>
        <th class="amount">金额合计贷方</th>
      </tr>
    </thead>
    <tbody>' . $body . '</tbody>
  </table>
</body>
</html>';
    }

    protected function registerMpdfAutoloader($vendorDir)
    {
        if (class_exists('\Mpdf\Mpdf', false)) {
            return;
        }
        if (!class_exists('\Composer\Autoload\ClassLoader', false)) {
            require_once $vendorDir . '/composer/ClassLoader.php';
        }
        $loader = new \Composer\Autoload\ClassLoader($vendorDir);
        $loader->addPsr4('Mpdf\\', $vendorDir . '/mpdf/mpdf/src');
        $loader->addPsr4('Mpdf\\PsrHttpMessageShim\\', $vendorDir . '/mpdf/psr-http-message-shim/src');
        $loader->addPsr4('Mpdf\\PsrLogAwareTrait\\', $vendorDir . '/mpdf/psr-log-aware-trait/src');
        $loader->addPsr4('setasign\\Fpdi\\', $vendorDir . '/setasign/fpdi/src');
        $loader->addPsr4('Psr\\Log\\', $vendorDir . '/psr/log/Psr/Log');
        $loader->addPsr4('Psr\\Http\\Message\\', $vendorDir . '/psr/http-message/src');
        $loader->addPsr4('DeepCopy\\', $vendorDir . '/myclabs/deep-copy/src/DeepCopy');
        $loader->register(true);
        require_once $vendorDir . '/myclabs/deep-copy/src/DeepCopy/deep_copy.php';
        require_once $vendorDir . '/mpdf/mpdf/src/functions.php';
    }

    protected function pdfDisposition($value)
    {
        return $value === 'attachment' ? 'attachment' : 'inline';
    }

    protected function detailLedgerPdfJobDir()
    {
        return __DIR__ . '/../../../runtime/detail_ledger_pdf_jobs';
    }

    protected function ensureDetailLedgerPdfJobDir()
    {
        $dir = $this->detailLedgerPdfJobDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        return $dir;
    }

    protected function detailLedgerPdfJobPath($jobId)
    {
        return $this->detailLedgerPdfJobDir() . '/' . basename((string)$jobId) . '.json';
    }

    protected function detailLedgerPdfJobPdfPath($jobId)
    {
        return $this->detailLedgerPdfJobDir() . '/' . basename((string)$jobId) . '.pdf';
    }

    protected function detailLedgerPdfJobLogPath($jobId)
    {
        return $this->detailLedgerPdfJobDir() . '/' . basename((string)$jobId) . '.log';
    }

    protected function readDetailLedgerPdfJob($jobId)
    {
        if ($jobId === '') {
            return null;
        }
        $path = $this->detailLedgerPdfJobPath($jobId);
        if (!is_file($path)) {
            return null;
        }
        $job = json_decode((string)file_get_contents($path), true);
        return is_array($job) ? $job : null;
    }

    protected function writeDetailLedgerPdfJob($job)
    {
        $this->ensureDetailLedgerPdfJobDir();
        file_put_contents(
            $this->detailLedgerPdfJobPath($job['job_id'] ?? ''),
            json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    protected function detailLedgerPdfJobErrorFromLog($job)
    {
        $logPath = $job['log_path'] ?? '';
        if ($logPath !== '' && is_file($logPath) && filesize($logPath) > 0) {
            $content = trim((string)file_get_contents($logPath, false, null, 0, 2000));
            if ($content !== '') {
                return $content;
            }
        }
        return '';
    }

    protected function refreshDetailLedgerPdfJobStatus($job)
    {
        $status = $job['status'] ?? '';
        if ($status !== 'QUEUED' && $status !== 'RUNNING') {
            return $job;
        }
        $updatedTime = strtotime((string)($job['updated_time'] ?? '')) ?: time();
        $idleSeconds = time() - $updatedTime;
        $error = '';
        if ($status === 'QUEUED' && $idleSeconds >= 8) {
            $error = $this->detailLedgerPdfJobErrorFromLog($job);
            if ($error === '') {
                $error = 'PDF后台任务未启动。请检查生产环境是否允许PHP执行exec函数、是否安装PHP CLI、backend/scripts/detail_ledger_pdf_job.php是否已部署，以及backend/runtime目录是否可写。';
            }
        }
        if ($status === 'RUNNING' && $idleSeconds >= 600) {
            $error = $this->detailLedgerPdfJobErrorFromLog($job);
            if ($error === '') {
                $error = 'PDF后台任务超过10分钟没有进度更新，可能已被服务器中止。请检查PHP CLI执行权限、内存限制和runtime目录权限。';
            }
        }
        if ($error === '') {
            return $job;
        }

        $job['status'] = 'FAILED';
        $job['message'] = 'PDF生成失败';
        $job['error'] = $error;
        $job['updated_time'] = $this->now();
        $this->writeDetailLedgerPdfJob($job);
        return $job;
    }

    protected function publicDetailLedgerPdfJob($job)
    {
        return [
            'job_id' => $job['job_id'] ?? '',
            'status' => $job['status'] ?? 'UNKNOWN',
            'message' => $job['message'] ?? '',
            'error' => $job['error'] ?? '',
            'created_time' => $job['created_time'] ?? '',
            'updated_time' => $job['updated_time'] ?? '',
            'file_size' => isset($job['pdf_path']) && is_file($job['pdf_path']) ? filesize($job['pdf_path']) : 0,
            'progress' => isset($job['progress']) ? (int)$job['progress'] : 0,
            'total_rows' => isset($job['total_rows']) ? (int)$job['total_rows'] : 0,
            'generated_pages' => isset($job['generated_pages']) ? (int)$job['generated_pages'] : 0,
            'total_pages' => isset($job['total_pages']) ? (int)$job['total_pages'] : 0,
        ];
    }

    protected function startDetailLedgerPdfJobProcess($jobId)
    {
        $script = realpath(__DIR__ . '/../../../scripts/detail_ledger_pdf_job.php');
        if (!$script) {
            return ['ok' => false, 'error' => 'PDF后台脚本不存在，请确认 backend/scripts/detail_ledger_pdf_job.php 已部署'];
        }
        $php = $this->findPhpCliBinary();
        if (!is_file($php)) {
            return ['ok' => false, 'error' => '未找到PHP CLI可执行文件，当前路径：' . $php];
        }
        $log = $this->detailLedgerPdfJobLogPath($jobId);
        $job = $this->readDetailLedgerPdfJob($jobId);
        if (!$job) {
            return ['ok' => false, 'error' => 'PDF任务文件不存在，无法启动后台任务'];
        }
        $job['launcher_php'] = $php;
        $job['launcher_script'] = $script;
        $job['message'] = '后台任务启动中';
        $this->writeDetailLedgerPdfJob($job);

        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWindows) {
            if (!$this->isPhpFunctionAvailable('popen') || !$this->isPhpFunctionAvailable('pclose')) {
                return ['ok' => false, 'error' => '生产环境禁用了PHP popen/pclose函数，无法在Windows上启动PDF后台任务'];
            }
            $cmd = 'cmd /C start "" /B ' . escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($jobId)
                . ' > ' . escapeshellarg($log) . ' 2>&1';
            $handle = @popen($cmd, 'r');
            if (!$handle) {
                $job['launcher_command'] = $cmd;
                $job['launcher_code'] = -1;
                $this->writeDetailLedgerPdfJob($job);
                return ['ok' => false, 'error' => 'Windows后台任务启动失败，请检查XAMPP运行用户是否允许执行cmd/start'];
            }
            pclose($handle);
            $job = $this->readDetailLedgerPdfJob($jobId) ?: $job;
            $job['launcher_code'] = 0;
            $job['launcher_pid'] = '';
            $job['launcher_command'] = $cmd;
            $this->writeDetailLedgerPdfJob($job);
            return ['ok' => true];
        } else {
            if (!$this->isPhpFunctionAvailable('exec')) {
                return ['ok' => false, 'error' => '生产环境禁用了PHP exec函数，无法启动PDF后台任务'];
            }
            $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($jobId)
                . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!';
            $output = [];
            $code = 0;
            exec($cmd, $output, $code);
            $job = $this->readDetailLedgerPdfJob($jobId) ?: $job;
            $job['launcher_code'] = $code;
            $job['launcher_pid'] = trim((string)($output[0] ?? ''));
            $job['launcher_command'] = $cmd;
            $this->writeDetailLedgerPdfJob($job);
            if ($code !== 0) {
                $error = $this->detailLedgerPdfJobErrorFromLog($job);
                return ['ok' => false, 'error' => $error !== '' ? $error : 'PDF后台任务启动命令执行失败'];
            }

            return ['ok' => true];
        }
    }

    protected function isPhpFunctionAvailable($function)
    {
        if (!function_exists($function)) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
        return !in_array($function, $disabled, true);
    }

    protected function findPhpCliBinary()
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $names = $isWindows ? ['php.exe', 'php'] : ['php'];
        $candidates = [];
        $baseDirs = [PHP_BINDIR, dirname(PHP_BINARY)];
        if ($isWindows) {
            foreach ([PHP_BINDIR, dirname(PHP_BINARY)] as $dir) {
                $dir = rtrim($dir, '/\\');
                $baseDirs[] = dirname(dirname($dir)) . DIRECTORY_SEPARATOR . 'php';
                $baseDirs[] = dirname(dirname(dirname($dir))) . DIRECTORY_SEPARATOR . 'php';
            }
            $pathDirs = explode(PATH_SEPARATOR, (string)getenv('PATH'));
            foreach ($pathDirs as $pathDir) {
                if ($pathDir !== '') {
                    $baseDirs[] = $pathDir;
                }
            }
        }
        foreach (array_unique($baseDirs) as $dir) {
            foreach ($names as $name) {
                $candidates[] = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name;
            }
        }
        if ($this->isPhpCliBinaryPath(PHP_BINARY)) {
            $candidates[] = PHP_BINARY;
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && $this->isPhpCliBinaryPath($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    protected function isPhpCliBinaryPath($path)
    {
        $name = strtolower(basename((string)$path));
        return $name === 'php' || $name === 'php.exe';
    }

    protected function buildSubjectBalancePdfHtml($rows, $accountSetName, $startPeriod, $endPeriod, $totals, $fontSizePt)
    {
        $escape = function ($value) {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        };
        $money = function ($value, $keepZero = false) {
            $cents = $this->decimalToCents($value);
            if (!$keepZero && $cents === 0) {
                return '';
            }
            return number_format($cents / 100, 2, '.', ',');
        };
        $amountFields = $this->subjectBalanceAmountFields();
        $body = '';
        foreach ($rows as $row) {
            $level = max(1, (int)($row['level_no'] ?? 1));
            $indent = ($row['row_type'] ?? 'subject') === 'subtotal' ? '' : str_repeat('&nbsp;&nbsp;', $level - 1);
            $body .= '<tr class="' . (($row['row_type'] ?? 'subject') === 'subtotal' ? 'subtotal-row' : '') . '">';
            $body .= '<td class="code">' . $escape($row['subject_code'] ?? '') . '</td>';
            $body .= '<td class="name">' . $indent . $escape(($row['row_type'] ?? 'subject') === 'subtotal' ? '小计' : ($row['subject_name'] ?? '未维护名称')) . '</td>';
            foreach ($amountFields as $field) {
                $body .= '<td class="amount">' . $escape($money($row[$field] ?? 0)) . '</td>';
            }
            $body .= '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td class="empty" colspan="10">暂无科目余额数据</td></tr>';
        }
        $body .= '<tr class="total-row">'
            . '<td class="code">合计</td>'
            . '<td class="name"></td>';
        foreach ($amountFields as $field) {
            $body .= '<td class="amount">' . $escape($money($totals[$field] ?? 0, true)) . '</td>';
        }
        $body .= '</tr>';

        return '<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <style>
    body { font-family: gb, sun-exta, "SimSun", serif; color: #111; font-size: ' . $fontSizePt . 'pt; }
    h1 { text-align: center; margin: 0 0 8px; font-size: 16pt; font-weight: bold; }
    .meta { width: 100%; margin-bottom: 8px; font-size: 8pt; }
    .meta td { border: 0; padding: 0; }
    .meta-right { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #111; padding: 3px 4px; }
    th { text-align: center; font-weight: bold; background: #f2f2f2; }
    .code { width: 10%; text-align: center; }
    .name { width: 18%; }
    .amount { width: 9%; text-align: right; font-family: dejavusansmono, gb, sun-exta, monospace; }
    .empty { text-align: center; color: #666; }
    .subtotal-row td { font-weight: bold; background: #f7f7f7; }
    .total-row td { font-weight: bold; background: #efefef; }
  </style>
</head>
<body>
  <h1>科目余额表</h1>
  <table class="meta" border="0" cellpadding="0" cellspacing="0">
    <tr>
      <td>账套：' . $escape($accountSetName) . '</td>
      <td class="meta-right">期间：' . $escape($startPeriod) . ' 至 ' . $escape($endPeriod) . '</td>
    </tr>
  </table>
  <table border="1" cellpadding="3" cellspacing="0">
    <thead>
      <tr>
        <th class="code">科目编码</th>
        <th class="name">科目名称</th>
        <th class="amount">期初借方</th>
        <th class="amount">期初贷方</th>
        <th class="amount">本期借方</th>
        <th class="amount">本期贷方</th>
        <th class="amount">累计借方</th>
        <th class="amount">累计贷方</th>
        <th class="amount">期末借方</th>
        <th class="amount">期末贷方</th>
      </tr>
    </thead>
    <tbody>' . $body . '</tbody>
  </table>
</body>
</html>';
    }

    protected function loadDetailLedgerRowsForPdf($data)
    {
        $pageSize = 200;
        $firstResult = $this->detailLedger(array_merge($data, [
            'page' => 1,
            'pagesize' => 200,
        ]));
        if (($firstResult['code'] ?? self::CODE_ERROR) !== self::CODE_SUCCESS) {
            return $firstResult;
        }
        $payload = $firstResult['data'] ?? [];
        $items = $payload['items'] ?? [];
        $total = (int)($payload['total'] ?? count($items));
        $pageCount = (int)ceil($total / $pageSize);
        for ($page = 2; $page <= $pageCount; $page++) {
            $pageResult = $this->detailLedger(array_merge($data, [
                'page' => $page,
                'pagesize' => 200,
            ]));
            if (($pageResult['code'] ?? self::CODE_ERROR) !== self::CODE_SUCCESS) {
                return $pageResult;
            }
            $pagePayload = $pageResult['data'] ?? [];
            $items = array_merge($items, $pagePayload['items'] ?? []);
        }

        return [
            'code' => self::CODE_SUCCESS,
            'items' => $items,
            'total' => $total,
            'debit_total' => $payload['debit_total'] ?? 0,
            'credit_total' => $payload['credit_total'] ?? 0,
        ];
    }

    protected function loadDetailLedgerSummaryForPdf($data)
    {
        return $this->detailLedger(array_merge($data, [
            'page' => 1,
            'pagesize' => 1,
        ]));
    }

    protected function loadDetailLedgerRowsPageOnlyForPdf($data, $page, $pageSize)
    {
        $queryResult = $this->buildDetailLedgerRowsQueryForPdf($data);
        if (($queryResult['code'] ?? self::CODE_ERROR) !== self::CODE_SUCCESS) {
            return $queryResult;
        }
        $rows = $queryResult['query']
            ->field('v.voucher_id,v.period,v.voucher_date,v.voucher_no,d.summary,d.subject_code,s.subject_name,d.debit_amount,d.credit_amount,d.aux_desc')
            ->order('v.voucher_date asc,v.voucher_no asc,d.line_no asc')
            ->page(max(1, (int)$page), max(1, (int)$pageSize))
            ->select();

        return $this->ok([
            'items' => $rows,
        ]);
    }

    protected function buildDetailLedgerRowsQueryForPdf($data)
    {
        $paramsResult = $this->normalizeDetailLedgerPdfParams($data);
        if (($paramsResult['code'] ?? self::CODE_ERROR) !== self::CODE_SUCCESS) {
            return $paramsResult;
        }
        $params = $paramsResult['data'];
        $where = [
            'd.account_set_id' => $this->accountSetId,
            'd.fiscal_year' => $params['fiscal_year'],
            'd.del_flag' => 0,
            'v.fiscal_year' => $params['fiscal_year'],
            'v.del_flag' => 0,
            'v.void_flag' => 0,
            'v.voucher_date' => ['between', [$params['start_date'], $params['end_date']]],
        ];
        $query = db('fin_voucher_detail')->alias('d')
            ->force('idx_voucher_detail_ledger_subject')
            ->join('fin_voucher v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
            ->join('fin_subject s', 's.subject_code=d.subject_code and s.account_set_id=d.account_set_id and s.del_flag=0', 'LEFT')
            ->where($where)
            ->where('d.period', 'between', [$params['start_period'], $params['end_period']])
            ->where('v.period', 'between', [$params['start_period'], $params['end_period']]);
        if ($params['subject_code'] !== '') {
            $query->where('d.subject_code', '>=', $params['subject_code']);
            $query->where('d.subject_code', '<=', $this->subjectRangeUpperBound($params['subject_code'], $params['max_length']));
        }

        return [
            'code' => self::CODE_SUCCESS,
            'query' => $query,
        ];
    }

    protected function normalizeDetailLedgerPdfParams($data)
    {
        $period = trim((string)($data['period'] ?? ''));
        $startPeriod = trim((string)($data['start_period'] ?? ''));
        $endPeriod = trim((string)($data['end_period'] ?? ''));
        if ($startPeriod === '') {
            $startPeriod = $period;
        }
        if ($endPeriod === '') {
            $endPeriod = $period;
        }
        if ($startPeriod === '' || $endPeriod === '') {
            return $this->error('会计期间不能为空');
        }
        if (!$this->validPeriod($startPeriod) || !$this->validPeriod($endPeriod)) {
            return $this->error('会计期间格式不正确');
        }
        if (substr($startPeriod, 0, 4) !== substr($endPeriod, 0, 4)) {
            return $this->error('明细账暂不支持跨年度查询');
        }
        if ($startPeriod > $endPeriod) {
            return $this->error('起始期间不能晚于结束期间');
        }
        $startDate = trim((string)($data['start_date'] ?? ''));
        $endDate = trim((string)($data['end_date'] ?? ''));
        if ($startDate === '') {
            $startDate = substr($startPeriod, 0, 7) . '-01';
        }
        if ($endDate === '') {
            $endDate = date('Y-m-t', strtotime($endPeriod . '-01'));
        }
        $codeRule = $this->subjectCodeRule();
        $lengths = $this->subjectCodeRuleLengths($codeRule);

        return $this->ok([
            'start_period' => $startPeriod,
            'end_period' => $endPeriod,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'subject_code' => trim((string)($data['subject_code'] ?? '')),
            'fiscal_year' => $this->fiscalYear($endPeriod),
            'max_length' => empty($lengths) ? 10 : max($lengths),
        ]);
    }

    protected function buildDetailLedgerPdfHtml($rows, $accountSetName, $startPeriod, $endPeriod, $subjectCode, $subjectName, $debitTotal, $creditTotal, $fontSizePt)
    {
        return '<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">'
            . $this->detailLedgerPdfStyles($fontSizePt)
            . '</head>
<body>'
            . $this->buildDetailLedgerPdfPageHtml($rows, $accountSetName, $startPeriod, $endPeriod, $subjectCode, $subjectName, $debitTotal, $creditTotal, '', $fontSizePt)
            . '</body>
</html>';
    }

    protected function buildDetailLedgerPdfPageHtml($rows, $accountSetName, $startPeriod, $endPeriod, $subjectCode, $subjectName, $debitTotal, $creditTotal, $rightMetaText, $fontSizePt = 7.1)
    {
        $escape = function ($value) {
            return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        };
        $money = function ($value, $keepZero = false) {
            $cents = $this->decimalToCents($value);
            if (!$keepZero && $cents === 0) {
                return '';
            }
            return number_format($cents / 100, 2, '.', ',');
        };
        $body = '';
        foreach ($rows as $row) {
            $voucherNo = $row['voucher_no'] !== null && $row['voucher_no'] !== ''
                ? '记-' . str_pad((string)$row['voucher_no'], 4, '0', STR_PAD_LEFT)
                : '';
            $subjectLabel = trim((string)($row['subject_code'] ?? '') . ' ' . (string)($row['subject_name'] ?? ''));
            $body .= '<tr>';
            $body .= '<td class="date">' . $escape($row['voucher_date'] ?? '') . '</td>';
            $body .= '<td class="voucher-no">' . $escape($voucherNo) . '</td>';
            $summaryText = (string)($row['summary'] ?? '');
            $summaryStyle = $this->detailLedgerPdfSummaryStyle($summaryText, $fontSizePt);
            $body .= '<td class="summary"><span class="summary-text"' . $summaryStyle . '>' . $escape($summaryText) . '</span></td>';
            $body .= '<td class="subject">' . $escape($subjectLabel) . '</td>';
            $body .= '<td class="amount">' . $escape($money($row['debit_amount'] ?? 0)) . '</td>';
            $body .= '<td class="amount">' . $escape($money($row['credit_amount'] ?? 0)) . '</td>';
            $body .= '<td class="aux">' . $escape($this->formatDetailLedgerAuxDesc($row['aux_desc'] ?? '')) . '</td>';
            $body .= '</tr>';
        }
        if ($body === '') {
            $body = '<tr><td class="empty" colspan="7">暂无明细账数据</td></tr>';
        }
        if ($debitTotal !== null && $creditTotal !== null) {
            $body .= '<tr class="total-row">'
                . '<td class="date">合计</td>'
                . '<td class="voucher-no"></td>'
                . '<td class="summary"></td>'
                . '<td class="subject"></td>'
                . '<td class="amount">' . $escape($money($debitTotal, true)) . '</td>'
                . '<td class="amount">' . $escape($money($creditTotal, true)) . '</td>'
                . '<td class="aux"></td>'
                . '</tr>';
        }
        $subjectText = trim($subjectCode . ($subjectName !== '' ? ' ' . $subjectName : ''));

        return '
  <h1>明细账</h1>
  <table class="meta" border="0" cellpadding="0" cellspacing="0">
    <tr>
      <td>账套：' . $escape($accountSetName) . '</td>
      <td class="meta-right">期间：' . $escape($startPeriod) . ' 至 ' . $escape($endPeriod) . '</td>
    </tr>
    <tr>
      <td>科目：' . $escape($subjectText !== '' ? $subjectText : '全部科目') . '</td>
      <td class="meta-right">' . $escape($rightMetaText) . '</td>
    </tr>
  </table>
  <table border="1" cellpadding="3" cellspacing="0">
    <thead>
      <tr>
        <th class="date">日期</th>
        <th class="voucher-no">凭证号</th>
        <th class="summary">摘要</th>
        <th class="subject">科目</th>
        <th class="amount">借方金额</th>
        <th class="amount">贷方金额</th>
        <th class="aux">辅助核算</th>
      </tr>
    </thead>
    <tbody>' . $body . '</tbody>
  </table>';
    }

    protected function detailLedgerPdfStyles($fontSizePt)
    {
        return '
  <style>
    body { font-family: gb, sun-exta, "SimSun", serif; color: #111; font-size: ' . $fontSizePt . 'pt; }
    h1 { text-align: center; margin: 0 0 8px; font-size: 16pt; font-weight: bold; }
    .meta { width: 100%; margin-bottom: 8px; font-size: 8pt; }
    .meta td { border: 0; padding: 0; }
    .meta-right { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #111; padding: 3px 4px; vertical-align: top; }
    th { text-align: center; font-weight: bold; background: #f2f2f2; }
    .date { width: 10%; text-align: center; }
    .voucher-no { width: 9%; text-align: center; }
    .summary { width: 24%; }
    .summary-text { display: block; }
    .subject { width: 18%; }
    .amount { width: 11%; text-align: right; font-family: dejavusansmono, gb, sun-exta, monospace; }
    .aux { width: 17%; }
    .empty { text-align: center; color: #666; }
    .total-row td { font-weight: bold; background: #efefef; }
  </style>';
    }

    protected function detailLedgerPdfSummaryStyle($summary, $baseFontSizePt)
    {
        $length = $this->utf8Length($summary);
        if ($length <= 24) {
            return '';
        }
        $baseFontSizePt = max(4.0, (float)$baseFontSizePt);
        $fontSize = max(3.6, min($baseFontSizePt, round($baseFontSizePt * 24 / max(1, $length), 2)));
        return ' style="font-size: ' . $fontSize . 'pt; line-height: 1; white-space: nowrap; word-break: keep-all;"';
    }

    protected function utf8Length($value)
    {
        $text = (string)$value;
        if ($text === '') {
            return 0;
        }
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }
        if (preg_match_all('/./us', $text, $matches)) {
            return count($matches[0]);
        }
        return strlen($text);
    }

    protected function formatDetailLedgerAuxDesc($auxDesc)
    {
        $parts = preg_split('/[;；\/]/u', (string)$auxDesc);
        $labels = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $index = strpos($part, ':');
            $labels[] = trim($index === false ? $part : substr($part, $index + 1));
        }
        return implode('；', array_filter($labels, 'strlen'));
    }

    protected function subjectBalanceDisplayRows($rows)
    {
        $displayRows = [];
        $currentType = '';
        $groupRows = [];
        foreach ($rows as $row) {
            $rowType = (string)($row['subject_type'] ?? 'OTHER');
            if (!empty($groupRows) && $rowType !== $currentType) {
                $displayRows[] = $this->makeSubjectBalanceSubtotalRow($currentType, $groupRows);
                $groupRows = [];
            }
            $currentType = $rowType;
            $subjectRow = $row;
            $subjectRow['row_type'] = 'subject';
            $displayRows[] = $subjectRow;
            $groupRows[] = $row;
        }
        if (!empty($groupRows)) {
            $displayRows[] = $this->makeSubjectBalanceSubtotalRow($currentType, $groupRows);
        }
        return $displayRows;
    }

    protected function makeSubjectBalanceSubtotalRow($subjectType, $rows)
    {
        $leafRows = array_filter($rows, function ($row) {
            return (int)($row['leaf_flag'] ?? 1) === 1;
        });
        $sourceRows = empty($leafRows) ? $rows : $leafRows;
        $subtotal = [
            'row_type' => 'subtotal',
            'subject_code' => $this->subjectTypeLabel($subjectType) . '小计',
            'subject_name' => '',
            'subject_type' => $subjectType,
            'level_no' => 0,
            'leaf_flag' => 0,
        ];
        foreach ($this->subjectBalanceAmountFields() as $field) {
            $cents = 0;
            foreach ($sourceRows as $row) {
                $cents += $this->decimalToCents($row[$field] ?? 0);
            }
            $subtotal[$field] = $this->centsToDecimal($cents);
        }
        return $subtotal;
    }

    protected function subjectBalanceTotals($rows)
    {
        $leafRows = array_filter($rows, function ($row) {
            return (int)($row['leaf_flag'] ?? 1) === 1;
        });
        $sourceRows = empty($leafRows) ? $rows : $leafRows;
        $totals = [];
        foreach ($this->subjectBalanceAmountFields() as $field) {
            $cents = 0;
            foreach ($sourceRows as $row) {
                $cents += $this->decimalToCents($row[$field] ?? 0);
            }
            $totals[$field] = $this->centsToDecimal($cents);
        }
        return $totals;
    }

    protected function subjectBalanceAmountFields()
    {
        return [
            'opening_debit_amount',
            'opening_credit_amount',
            'debit_amount',
            'credit_amount',
            'year_debit_amount',
            'year_credit_amount',
            'ending_debit_amount',
            'ending_credit_amount',
        ];
    }

    protected function subjectTypeLabel($subjectType)
    {
        $labels = [
            'ASSET' => '资产',
            'LIABILITY' => '负债',
            'COMMON' => '共同',
            'EQUITY' => '所有者权益',
            'COST' => '成本',
            'PROFIT_LOSS' => '损益',
        ];
        return $labels[(string)$subjectType] ?? '未分类';
    }

    protected function sortSubjectSummaryRows($rows)
    {
        $childrenByParent = [];
        foreach ($rows as $row) {
            $parentCode = $this->subjectSummaryParentCode($row, $rows);
            if (!isset($childrenByParent[$parentCode])) {
                $childrenByParent[$parentCode] = [];
            }
            $childrenByParent[$parentCode][] = $row;
        }

        $result = [];
        $this->appendSubjectSummaryTree('', $childrenByParent, $result);
        return $result;
    }

    protected function appendSubjectSummaryTree($parentCode, &$childrenByParent, &$result)
    {
        if (empty($childrenByParent[$parentCode])) {
            return;
        }
        usort($childrenByParent[$parentCode], [$this, 'compareSubjectSummaryRows']);
        foreach ($childrenByParent[$parentCode] as $row) {
            $result[] = $row;
            $this->appendSubjectSummaryTree((string)($row['subject_code'] ?? ''), $childrenByParent, $result);
        }
    }

    protected function subjectSummaryParentCode($row, $rows)
    {
        $code = (string)($row['subject_code'] ?? '');
        $level = (int)($row['level_no'] ?? 1);
        $parentCode = '';
        foreach ($rows as $candidate) {
            $candidateCode = (string)($candidate['subject_code'] ?? '');
            if ($candidateCode === '' || $candidateCode === $code) {
                continue;
            }
            if ((int)($candidate['level_no'] ?? 1) >= $level) {
                continue;
            }
            if (strpos($code, $candidateCode) !== 0) {
                continue;
            }
            if (strlen($candidateCode) > strlen($parentCode)) {
                $parentCode = $candidateCode;
            }
        }
        return $parentCode;
    }

    protected function compareSubjectSummaryRows($a, $b)
    {
        $typeDiff = $this->subjectTypeOrder($a['subject_type'] ?? '') - $this->subjectTypeOrder($b['subject_type'] ?? '');
        if ($typeDiff !== 0) {
            return $typeDiff;
        }
        $codeA = (string)($a['subject_code'] ?? '');
        $codeB = (string)($b['subject_code'] ?? '');
        $codeDiff = strnatcmp($codeA, $codeB);
        return $codeDiff !== 0 ? $codeDiff : strcmp($codeA, $codeB);
    }

    protected function subjectTypeOrder($subjectType)
    {
        $orders = [
            'ASSET' => 1,
            'LIABILITY' => 2,
            'COMMON' => 3,
            'EQUITY' => 4,
            'COST' => 5,
            'PROFIT_LOSS' => 6,
        ];
        return $orders[(string)$subjectType] ?? 99;
    }

    public function auxBalance($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $period = $data['period'] ?? '';
        if ($period === '') {
            return $this->error('会计期间不能为空');
        }
        $subjectCode = trim((string)($data['subject_code'] ?? ''));
        $caseNoFilter = trim((string)($data['case_no'] ?? ''));
        $receiptNoFilter = trim((string)($data['receipt_no'] ?? ''));
        $onlyMonitor = !empty($data['only_monitor']);
        $page = max(1, (int)($data['page'] ?? 1));
        $pageSize = max(1, min(200, (int)($data['pagesize'] ?? 50)));
        $exportAll = !empty($data['export_all']);
        $refreshSnapshot = !empty($data['refresh_snapshot']);
        $snapshotError = $this->ensureAuxBalanceSnapshot($period, $refreshSnapshot);
        if ($snapshotError) {
            return $snapshotError;
        }

        if ($exportAll) {
            $rows = $this->loadAuxBalanceSnapshotRows($period, $subjectCode, $caseNoFilter, $receiptNoFilter, $onlyMonitor);
            return $this->ok([
                'items' => $rows,
                'total' => count($rows),
                'period' => $period,
                'snapshot_time' => $this->auxBalanceSnapshotTime($period),
            ], 'OK', count($rows));
        }

        $result = $this->loadAuxBalanceSnapshotPage(
            $period,
            $subjectCode,
            $caseNoFilter,
            $receiptNoFilter,
            $onlyMonitor,
            $page,
            $pageSize
        );
        return $this->ok([
            'items' => $result['items'],
            'total' => $result['total'],
            'monitor_total' => $result['monitor_total'],
            'ending_total' => $result['ending_total'],
            'period' => $period,
            'page' => $page,
            'pagesize' => $pageSize,
            'only_monitor' => $onlyMonitor,
            'snapshot_time' => $this->auxBalanceSnapshotTime($period),
        ], 'OK', $result['total']);
    }

    public function caseMovementDetail($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }
        $period = trim((string)($data['period'] ?? ''));
        if (!$this->validPeriod($period)) {
            return $this->error('会计期间格式不正确');
        }
        $startPeriod = $this->accountSetEnabledPeriod();
        if (!$this->validPeriod($startPeriod)) {
            $startPeriod = substr($period, 0, 4) . '-01';
        }
        if ($startPeriod > $period) {
            return $this->error('查询期间不能早于账套启用期间');
        }

        $subjectCode = trim((string)($data['subject_code'] ?? ''));
        $caseNo = trim((string)($data['case_no'] ?? ''));
        $receiptNo = trim((string)($data['receipt_no'] ?? ''));
        $page = max(1, (int)($data['page'] ?? 1));
        $pageSize = max(1, min(200, (int)($data['pagesize'] ?? 100)));
        $refreshSnapshot = !empty($data['refresh_snapshot']);

        $snapshotError = $this->ensureCaseMovementSnapshot($refreshSnapshot);
        if ($snapshotError) {
            return $snapshotError;
        }

        $buildQuery = function () use ($startPeriod, $period, $subjectCode, $caseNo, $receiptNo) {
            return $this->caseMovementSnapshotQuery($startPeriod, $period, $subjectCode, $caseNo, $receiptNo);
        };
        $total = (int)$buildQuery()->count();
        $debitTotal = $buildQuery()->sum('debit_amount');
        $creditTotal = $buildQuery()->sum('credit_amount');
        $rows = $buildQuery()
            ->field('snapshot_id,voucher_id,fiscal_year,period,voucher_date,voucher_word,voucher_no,voucher_status,detail_id,line_no,subject_code,subject_name,direction,case_no,receipt_no,summary,debit_amount,credit_amount')
            ->order('case_no asc,voucher_date asc,voucher_no asc,line_no asc')
            ->page($page, $pageSize)
            ->select();

        foreach ($rows as &$row) {
            $row['voucher_no'] = (int)($row['voucher_no'] ?? 0);
            $row['line_no'] = (int)($row['line_no'] ?? 0);
            $row['voucher_label'] = ($row['voucher_word'] ?? '记') . '-' . $row['voucher_no'];
            $row['debit_amount'] = $this->centsToDecimal($this->decimalToCents($row['debit_amount'] ?? 0));
            $row['credit_amount'] = $this->centsToDecimal($this->decimalToCents($row['credit_amount'] ?? 0));
        }
        unset($row);

        return $this->ok([
            'items' => $rows,
            'total' => $total,
            'debit_total' => $this->centsToDecimal($this->decimalToCents($debitTotal ?: 0)),
            'credit_total' => $this->centsToDecimal($this->decimalToCents($creditTotal ?: 0)),
            'start_period' => $startPeriod,
            'end_period' => $period,
            'snapshot_time' => $this->caseMovementSnapshotTime(),
            'page' => $page,
            'pagesize' => $pageSize,
        ], 'OK', $total);
    }

    protected function ensureCaseMovementSnapshot($forceRefresh = false)
    {
        if (!$forceRefresh && $this->caseMovementSnapshotFresh()) {
            return null;
        }
        return $this->rebuildCaseMovementSnapshot();
    }

    protected function caseMovementSnapshotFresh()
    {
        try {
            $row = db('fin_case_movement_snapshot')
                ->where([
                    'account_set_id' => $this->accountSetId,
                    'del_flag' => 0,
                ])
                ->field('count(*) as row_count,max(source_updated_time) as source_updated_time,max(snapshot_time) as snapshot_time')
                ->find();
            if ((int)($row['row_count'] ?? 0) <= 0) {
                return !$this->caseMovementSourceExists();
            }
            $snapshotTimestamp = strtotime((string)($row['snapshot_time'] ?? ''));
            if ($snapshotTimestamp !== false && $snapshotTimestamp >= time() - self::CASE_MOVEMENT_SNAPSHOT_FRESH_SECONDS) {
                return true;
            }
            return (string)($row['source_updated_time'] ?? '') === $this->caseMovementSourceUpdatedTime();
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function caseMovementSourceExists()
    {
        $startPeriod = $this->accountSetEnabledPeriod();
        if (!$this->validPeriod($startPeriod)) {
            return false;
        }
        return db('fin_voucher_detail')->alias('d')
            ->join('fin_voucher v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
            ->join('fin_voucher_aux_value case_aux', 'case_aux.detail_id=d.detail_id and case_aux.account_set_id=d.account_set_id and case_aux.fiscal_year=d.fiscal_year and case_aux.period=d.period')
            ->where([
                'd.account_set_id' => $this->accountSetId,
                'd.del_flag' => 0,
                'v.del_flag' => 0,
                'v.void_flag' => 0,
                'case_aux.aux_type_code' => 'case_no',
                'case_aux.del_flag' => 0,
            ])
            ->where('d.fiscal_year', '>=', (int)substr($startPeriod, 0, 4))
            ->where('v.fiscal_year', '>=', (int)substr($startPeriod, 0, 4))
            ->where('d.period', '>=', $startPeriod)
            ->where('v.period', '>=', $startPeriod)
            ->count() > 0;
    }

    protected function rebuildCaseMovementSnapshot()
    {
        $startPeriod = $this->accountSetEnabledPeriod();
        if (!$this->validPeriod($startPeriod)) {
            return $this->error('账套启用期间未配置，无法建立案号收支明细缓存');
        }
        $startFiscalYear = (int)substr($startPeriod, 0, 4);
        $sourceTime = $this->caseMovementSourceUpdatedTime();
        $snapshotTime = $this->now();
        try {
            $sourceRows = db('fin_voucher_detail')->alias('d')
                ->join('fin_voucher v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
                ->join('fin_voucher_aux_value case_aux', 'case_aux.detail_id=d.detail_id and case_aux.account_set_id=d.account_set_id and case_aux.fiscal_year=d.fiscal_year and case_aux.period=d.period')
                ->join('fin_voucher_aux_value receipt_aux', "receipt_aux.detail_id=d.detail_id and receipt_aux.account_set_id=d.account_set_id and receipt_aux.fiscal_year=d.fiscal_year and receipt_aux.period=d.period and receipt_aux.aux_type_code='receipt_no' and receipt_aux.del_flag=0", 'LEFT')
                ->join('fin_subject s', 's.subject_code=d.subject_code and s.account_set_id=d.account_set_id and s.del_flag=0', 'LEFT')
                ->where([
                    'd.account_set_id' => $this->accountSetId,
                    'd.del_flag' => 0,
                    'v.del_flag' => 0,
                    'v.void_flag' => 0,
                    'case_aux.aux_type_code' => 'case_no',
                    'case_aux.del_flag' => 0,
                ])
                ->where('d.fiscal_year', '>=', $startFiscalYear)
                ->where('v.fiscal_year', '>=', $startFiscalYear)
                ->where('d.period', '>=', $startPeriod)
                ->where('v.period', '>=', $startPeriod)
                ->field("d.fiscal_year,d.period,d.detail_id,d.line_no,d.subject_code,d.summary,d.debit_amount,d.credit_amount,v.voucher_id,v.voucher_date,v.voucher_word,v.voucher_no,v.status as voucher_status,s.subject_name,s.direction,trim(coalesce(nullif(case_aux.aux_label,''),case_aux.aux_value,'')) as case_no,trim(coalesce(nullif(receipt_aux.aux_label,''),receipt_aux.aux_value,'')) as receipt_no")
                ->order('d.fiscal_year asc,d.period asc,v.voucher_date asc,v.voucher_no asc,d.line_no asc')
                ->select();
        } catch (\Exception $e) {
            return $this->error('读取案号收支明细来源失败：' . $e->getMessage());
        }

        \think\Db::startTrans();
        try {
            db('fin_case_movement_snapshot')->where(['account_set_id' => $this->accountSetId])->delete();
            $batch = [];
            foreach ($sourceRows as $sourceRow) {
                if (trim((string)($sourceRow['case_no'] ?? '')) === '') {
                    continue;
                }
                $batch[] = $this->buildCaseMovementSnapshotInsertRow($sourceRow, $snapshotTime, $sourceTime);
                if (count($batch) >= 500) {
                    db('fin_case_movement_snapshot')->insertAll($batch);
                    $batch = [];
                }
            }
            if (!empty($batch)) {
                db('fin_case_movement_snapshot')->insertAll($batch);
            }
            \think\Db::commit();
        } catch (\Exception $e) {
            \think\Db::rollback();
            return $this->error('刷新案号收支明细缓存失败：' . $e->getMessage());
        }
        return null;
    }

    protected function buildCaseMovementSnapshotInsertRow($sourceRow, $snapshotTime, $sourceTime)
    {
        return [
            'snapshot_id' => uuid(),
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => (int)($sourceRow['fiscal_year'] ?? 0),
            'period' => $sourceRow['period'] ?? '',
            'voucher_id' => $sourceRow['voucher_id'] ?? '',
            'voucher_date' => $sourceRow['voucher_date'] ?? null,
            'voucher_word' => $sourceRow['voucher_word'] ?? '记',
            'voucher_no' => (int)($sourceRow['voucher_no'] ?? 0),
            'voucher_status' => $sourceRow['voucher_status'] ?? '',
            'detail_id' => $sourceRow['detail_id'] ?? '',
            'line_no' => (int)($sourceRow['line_no'] ?? 0),
            'subject_code' => $sourceRow['subject_code'] ?? '',
            'subject_name' => $sourceRow['subject_name'] ?? '',
            'direction' => $sourceRow['direction'] ?? 'DEBIT',
            'case_no' => trim((string)($sourceRow['case_no'] ?? '')),
            'receipt_no' => trim((string)($sourceRow['receipt_no'] ?? '')),
            'summary' => $sourceRow['summary'] ?? '',
            'debit_amount' => $this->centsToDecimal($this->decimalToCents($sourceRow['debit_amount'] ?? 0)),
            'credit_amount' => $this->centsToDecimal($this->decimalToCents($sourceRow['credit_amount'] ?? 0)),
            'source_updated_time' => $sourceTime !== '' ? $sourceTime : null,
            'snapshot_time' => $snapshotTime,
            'created_by' => $this->userid,
            'created_time' => $snapshotTime,
            'updated_by' => $this->userid,
            'updated_time' => $snapshotTime,
            'del_flag' => 0,
            'version' => 0,
        ];
    }

    protected function caseMovementSnapshotQuery($startPeriod, $endPeriod, $subjectCode, $caseNo, $receiptNo)
    {
        $query = db('fin_case_movement_snapshot')->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => ['between', [(int)substr($startPeriod, 0, 4), (int)substr($endPeriod, 0, 4)]],
            'period' => ['between', [$startPeriod, $endPeriod]],
            'del_flag' => 0,
        ]);
        if ($subjectCode !== '') {
            $query->where('subject_code', $subjectCode);
        }
        if ($caseNo !== '') {
            $query->where('case_no', 'like', '%' . $caseNo . '%');
        }
        if ($receiptNo !== '') {
            $query->where('receipt_no', 'like', '%' . $receiptNo . '%');
        }
        return $query;
    }

    protected function caseMovementSnapshotTime()
    {
        try {
            $row = db('fin_case_movement_snapshot')
                ->where(['account_set_id' => $this->accountSetId, 'del_flag' => 0])
                ->field('max(snapshot_time) as snapshot_time')
                ->find();
            return (string)($row['snapshot_time'] ?? '');
        } catch (\Exception $e) {
            return '';
        }
    }

    protected function caseMovementSourceUpdatedTime()
    {
        $startPeriod = $this->accountSetEnabledPeriod();
        if (!$this->validPeriod($startPeriod)) {
            return '';
        }
        $startFiscalYear = (int)substr($startPeriod, 0, 4);
        $times = [
            $this->maxCaseMovementUpdatedTime('fin_voucher', $startFiscalYear, $startPeriod),
            $this->maxCaseMovementUpdatedTime('fin_voucher_detail', $startFiscalYear, $startPeriod),
            $this->maxCaseMovementUpdatedTime('fin_voucher_aux_value', $startFiscalYear, $startPeriod),
            $this->maxUpdatedTime('fin_subject', ['account_set_id' => $this->accountSetId]),
            $this->maxUpdatedTime('fin_subject_aux_config', ['account_set_id' => $this->accountSetId]),
        ];
        $times = array_values(array_filter($times, 'strlen'));
        if (empty($times)) {
            return '';
        }
        sort($times);
        return end($times);
    }

    protected function maxCaseMovementUpdatedTime($table, $startFiscalYear, $startPeriod)
    {
        $row = db($table)
            ->where(['account_set_id' => $this->accountSetId])
            ->where('fiscal_year', '>=', $startFiscalYear)
            ->where('period', '>=', $startPeriod)
            ->field('max(updated_time) as max_time')
            ->find();
        return (string)($row['max_time'] ?? '');
    }

    protected function ensureAuxBalanceSnapshot($period, $forceRefresh = false)
    {
        if (!$forceRefresh && $this->auxBalanceSnapshotFresh($period)) {
            return null;
        }
        return $this->rebuildAuxBalanceSnapshot($period);
    }

    protected function auxBalanceSnapshotFresh($period)
    {
        $fiscalYear = $this->fiscalYear($period);
        $where = [
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $fiscalYear,
            'period' => $period,
            'del_flag' => 0,
        ];
        try {
            $row = db('fin_aux_balance_snapshot')->where($where)
                ->field('count(*) as row_count,max(source_updated_time) as source_updated_time')
                ->find();
            if ((int)($row['row_count'] ?? 0) <= 0) {
                return false;
            }
            return (string)($row['source_updated_time'] ?? '') === $this->auxBalanceSourceUpdatedTime($period);
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function rebuildAuxBalanceSnapshot($period)
    {
        $fiscalYear = $this->fiscalYear($period);
        $sourceTime = $this->auxBalanceSourceUpdatedTime($period);
        $snapshotTime = $this->now();
        $groups = $this->buildAuxBalanceGroupsFromSources($period);
        $where = [
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $fiscalYear,
            'period' => $period,
        ];

        \think\Db::startTrans();
        try {
            db('fin_aux_balance_snapshot')->where($where)->delete();
            $batch = [];
            foreach ($groups as $detail) {
                $batch[] = $this->buildAuxBalanceSnapshotInsertRow($detail, $fiscalYear, $period, $snapshotTime, $sourceTime);
                if (count($batch) >= 500) {
                    db('fin_aux_balance_snapshot')->insertAll($batch);
                    $batch = [];
                }
            }
            if (!empty($batch)) {
                db('fin_aux_balance_snapshot')->insertAll($batch);
            }
            \think\Db::commit();
        } catch (\Exception $e) {
            \think\Db::rollback();
            return $this->error('刷新辅助核算余额快照失败：' . $e->getMessage());
        }

        return null;
    }

    protected function buildAuxBalanceGroupsFromSources($period)
    {
        $fiscalYear = $this->fiscalYear($period);
        $groups = [];
        $subjectMap = $this->subjectInfoMap();
        $caseSubjectMap = $this->subjectAuxConfigSubjectMap('case_no');
        $receiptSubjectMap = $this->subjectAuxConfigSubjectMap('receipt_no');

        $openingRows = db('fin_aux_opening_balance')
            ->where([
                'account_set_id' => $this->accountSetId,
                'period' => $this->openingPeriodFor($period, 'fin_aux_opening_balance'),
                'del_flag' => 0,
            ])
            ->field('subject_code,aux_values_json,debit_amount,credit_amount')
            ->select();
        foreach ($openingRows as $row) {
            $rowSubjectCode = $row['subject_code'] ?? '';
            if (empty($caseSubjectMap[$rowSubjectCode])) {
                continue;
            }
            $auxValues = json_decode($row['aux_values_json'] ?? '{}', true);
            if (!is_array($auxValues)) {
                $auxValues = [];
            }
            $caseNo = trim((string)($auxValues['case_no'] ?? ''));
            $receiptNo = !empty($receiptSubjectMap[$rowSubjectCode]) ? trim((string)($auxValues['receipt_no'] ?? '')) : '';
            $subject = $subjectMap[$rowSubjectCode] ?? [];
            $this->addAuxBalanceAmount(
                $groups,
                $rowSubjectCode,
                $subject['subject_name'] ?? '',
                $subject['direction'] ?? 'DEBIT',
                $caseNo,
                $receiptNo,
                $this->decimalToCents($row['debit_amount'] ?? 0),
                $this->decimalToCents($row['credit_amount'] ?? 0),
                0,
                0
            );
        }

        $voucherWhere = [
            'd.account_set_id' => $this->accountSetId,
            'd.fiscal_year' => $fiscalYear,
            'd.del_flag' => 0,
            'v.fiscal_year' => $fiscalYear,
            'v.del_flag' => 0,
            'v.void_flag' => 0,
            'case_aux.aux_type_code' => 'case_no',
            'case_aux.del_flag' => 0,
        ];
        $voucherQuery = db('fin_voucher_detail')->alias('d')
            ->join('fin_voucher v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
            ->join('fin_voucher_aux_value case_aux', 'case_aux.detail_id=d.detail_id and case_aux.account_set_id=d.account_set_id and case_aux.fiscal_year=d.fiscal_year and case_aux.period=d.period')
            ->join('fin_voucher_aux_value receipt_aux', "receipt_aux.detail_id=d.detail_id and receipt_aux.account_set_id=d.account_set_id and receipt_aux.fiscal_year=d.fiscal_year and receipt_aux.period=d.period and receipt_aux.aux_type_code='receipt_no' and receipt_aux.del_flag=0", 'LEFT')
            ->join('fin_subject_aux_config case_cfg', "case_cfg.subject_code=d.subject_code and case_cfg.account_set_id=d.account_set_id and case_cfg.aux_type_code='case_no' and case_cfg.del_flag=0")
            ->join('fin_subject s', 's.subject_code=d.subject_code and s.account_set_id=d.account_set_id and s.del_flag=0', 'LEFT')
            ->where($voucherWhere)
            ->where('d.period', 'between', [$this->reportYearStartPeriod($period), $period])
            ->where('v.period', 'between', [$this->reportYearStartPeriod($period), $period]);
        $voucherRows = $voucherQuery
            ->field("d.subject_code,s.subject_name,s.direction,trim(coalesce(nullif(case_aux.aux_label,''),case_aux.aux_value)) as case_no,trim(coalesce(nullif(receipt_aux.aux_label,''),receipt_aux.aux_value,'')) as receipt_no,sum(d.debit_amount) as debit_amount,sum(d.credit_amount) as credit_amount,count(*) as entry_count")
            ->group('d.subject_code,s.subject_name,s.direction,case_no,receipt_no')
            ->order('d.subject_code asc,case_no asc,receipt_no asc')
            ->select();
        foreach ($voucherRows as $row) {
            $caseNo = trim((string)$row['case_no']);
            $receiptNo = !empty($receiptSubjectMap[$row['subject_code']]) ? trim((string)$row['receipt_no']) : '';
            $this->addAuxBalanceAmount(
                $groups,
                $row['subject_code'],
                $row['subject_name'] ?? '',
                $row['direction'] ?? 'DEBIT',
                $caseNo,
                $receiptNo,
                0,
                0,
                $this->decimalToCents($row['debit_amount'] ?? 0),
                $this->decimalToCents($row['credit_amount'] ?? 0),
                (int)($row['entry_count'] ?? 0)
            );
        }

        return $groups;
    }

    protected function buildAuxBalanceSnapshotInsertRow($detail, $fiscalYear, $period, $snapshotTime, $sourceTime)
    {
        $openingBalanceCents = $this->normalBalanceCents($detail['direction'], $detail['opening_debit_cents'], $detail['opening_credit_cents']);
        $endingBalanceCents = $this->normalBalanceCents(
            $detail['direction'],
            $detail['opening_debit_cents'] + $detail['debit_cents'],
            $detail['opening_credit_cents'] + $detail['credit_cents']
        );
        return [
            'snapshot_id' => uuid(),
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $fiscalYear,
            'period' => $period,
            'subject_code' => $detail['subject_code'],
            'subject_name' => $detail['subject_name'],
            'direction' => $detail['direction'],
            'case_no' => $detail['case_no'],
            'receipt_no' => $detail['receipt_no'],
            'opening_debit_amount' => $this->centsToDecimal($detail['opening_debit_cents']),
            'opening_credit_amount' => $this->centsToDecimal($detail['opening_credit_cents']),
            'opening_balance_amount' => $this->centsToDecimal($openingBalanceCents),
            'debit_amount' => $this->centsToDecimal($detail['debit_cents']),
            'credit_amount' => $this->centsToDecimal($detail['credit_cents']),
            'ending_balance_amount' => $this->centsToDecimal($endingBalanceCents),
            'entry_count' => (int)$detail['entry_count'],
            'monitor_flag' => $endingBalanceCents !== 0 ? 1 : 0,
            'source_updated_time' => $sourceTime !== '' ? $sourceTime : null,
            'snapshot_time' => $snapshotTime,
            'created_by' => $this->userid,
            'created_time' => $snapshotTime,
            'updated_by' => $this->userid,
            'updated_time' => $snapshotTime,
            'del_flag' => 0,
            'version' => 0,
        ];
    }

    protected function auxBalanceSnapshotQuery($period, $subjectCode, $caseNoFilter, $receiptNoFilter, $onlyMonitor)
    {
        $query = db('fin_aux_balance_snapshot')->where([
            'account_set_id' => $this->accountSetId,
            'fiscal_year' => $this->fiscalYear($period),
            'period' => $period,
            'del_flag' => 0,
        ]);
        if ($subjectCode !== '') {
            $query->where('subject_code', $subjectCode);
        }
        if ($caseNoFilter !== '') {
            $query->where('case_no', 'like', '%' . $caseNoFilter . '%');
        }
        if ($receiptNoFilter !== '') {
            $query->where('receipt_no', 'like', '%' . $receiptNoFilter . '%');
        }
        if ($onlyMonitor) {
            $query->where('monitor_flag', 1);
        }
        return $query;
    }

    protected function loadAuxBalanceSnapshotPage($period, $subjectCode, $caseNoFilter, $receiptNoFilter, $onlyMonitor, $page, $pageSize)
    {
        $caseStats = $this->auxBalanceSnapshotQuery($period, $subjectCode, $caseNoFilter, $receiptNoFilter, $onlyMonitor)
            ->field('count(distinct case_no) as case_total')
            ->find();
        $total = (int)($caseStats['case_total'] ?? 0);

        $stats = $this->auxBalanceSnapshotQuery($period, $subjectCode, $caseNoFilter, $receiptNoFilter, $onlyMonitor)
            ->field('sum(monitor_flag) as monitor_total,sum(ending_balance_amount) as ending_total')
            ->find();

        $pageCaseRows = $this->auxBalanceSnapshotQuery($period, $subjectCode, $caseNoFilter, $receiptNoFilter, $onlyMonitor)
            ->field('case_no')
            ->group('case_no')
            ->order('case_no asc')
            ->page($page, $pageSize)
            ->select();
        $pageCaseNos = [];
        foreach ($pageCaseRows as $caseRow) {
            $caseNo = trim((string)($caseRow['case_no'] ?? ''));
            if ($caseNo !== '') {
                $pageCaseNos[] = $caseNo;
            }
        }

        $snapshotRows = [];
        if (!empty($pageCaseNos)) {
            $snapshotRows = $this->auxBalanceSnapshotQuery($period, $subjectCode, $caseNoFilter, $receiptNoFilter, $onlyMonitor)
                ->where('case_no', 'in', $pageCaseNos)
                ->field('subject_code,max(subject_name) as subject_name,max(direction) as direction,case_no,sum(opening_debit_amount) as opening_debit_amount,sum(opening_credit_amount) as opening_credit_amount,sum(debit_amount) as debit_amount,sum(credit_amount) as credit_amount,sum(entry_count) as entry_count,sum(monitor_flag) as monitor_count,count(*) as receipt_count')
                ->group('subject_code,case_no')
                ->order('case_no asc,subject_code asc')
                ->select();
        }
        $items = [];
        foreach ($snapshotRows as $row) {
            $items[] = $this->formatAuxBalanceCaseRow($row);
        }
        return [
            'items' => $items,
            'total' => $total,
            'monitor_total' => (int)($stats['monitor_total'] ?? 0),
            'ending_total' => $this->centsToDecimal($this->decimalToCents($stats['ending_total'] ?? 0)),
        ];
    }

    protected function formatAuxBalanceCaseRow($row)
    {
        $direction = $row['direction'] ?? 'DEBIT';
        $openingDebitCents = $this->decimalToCents($row['opening_debit_amount'] ?? 0);
        $openingCreditCents = $this->decimalToCents($row['opening_credit_amount'] ?? 0);
        $debitCents = $this->decimalToCents($row['debit_amount'] ?? 0);
        $creditCents = $this->decimalToCents($row['credit_amount'] ?? 0);
        $monitorCount = (int)($row['monitor_count'] ?? 0);
        return [
            'row_key' => 'case|' . $this->auxBalanceKey($row['subject_code'], $row['case_no'], ''),
            'row_type' => 'CASE',
            'subject_code' => $row['subject_code'],
            'subject_name' => $row['subject_name'] ?? '',
            'direction' => $direction,
            'case_no' => $row['case_no'],
            'receipt_no' => '',
            'opening_balance_amount' => $this->centsToDecimal($this->normalBalanceCents($direction, $openingDebitCents, $openingCreditCents)),
            'debit_amount' => $this->centsToDecimal($debitCents),
            'credit_amount' => $this->centsToDecimal($creditCents),
            'ending_balance_amount' => $this->centsToDecimal($this->normalBalanceCents(
                $direction,
                $openingDebitCents + $debitCents,
                $openingCreditCents + $creditCents
            )),
            'entry_count' => (int)($row['entry_count'] ?? 0),
            'monitor_flag' => $monitorCount > 0,
            'monitor_count' => $monitorCount,
            'receipt_count' => (int)($row['receipt_count'] ?? 0),
            'children' => [],
        ];
    }

    protected function loadAuxBalanceSnapshotRows($period, $subjectCode, $caseNoFilter, $receiptNoFilter, $onlyMonitor = false)
    {
        $receiptSubjectMap = $this->subjectAuxConfigSubjectMap('receipt_no');
        $query = $this->auxBalanceSnapshotQuery($period, $subjectCode, $caseNoFilter, $receiptNoFilter, $onlyMonitor);

        $snapshotRows = $query
            ->field('subject_code,subject_name,direction,case_no,receipt_no,opening_debit_amount,opening_credit_amount,debit_amount,credit_amount,entry_count')
            ->order('subject_code asc,case_no asc,receipt_no asc')
            ->select();
        $groups = [];
        foreach ($snapshotRows as $row) {
            $this->addAuxBalanceAmount(
                $groups,
                $row['subject_code'],
                $row['subject_name'] ?? '',
                $row['direction'] ?? 'DEBIT',
                $row['case_no'],
                !empty($receiptSubjectMap[$row['subject_code']]) ? $row['receipt_no'] : '',
                $this->decimalToCents($row['opening_debit_amount'] ?? 0),
                $this->decimalToCents($row['opening_credit_amount'] ?? 0),
                $this->decimalToCents($row['debit_amount'] ?? 0),
                $this->decimalToCents($row['credit_amount'] ?? 0),
                (int)($row['entry_count'] ?? 0)
            );
        }

        return $this->buildAuxBalanceRows($groups, $receiptSubjectMap);
    }

    protected function auxBalanceSnapshotTime($period)
    {
        try {
            $row = db('fin_aux_balance_snapshot')
                ->where([
                    'account_set_id' => $this->accountSetId,
                    'fiscal_year' => $this->fiscalYear($period),
                    'period' => $period,
                    'del_flag' => 0,
                ])
                ->field('max(snapshot_time) as snapshot_time')
                ->find();
            return (string)($row['snapshot_time'] ?? '');
        } catch (\Exception $e) {
            return '';
        }
    }

    protected function auxBalanceSourceUpdatedTime($period)
    {
        $fiscalYear = $this->fiscalYear($period);
        $periodRange = [$this->reportYearStartPeriod($period), $period];
        $times = [
            $this->maxUpdatedTime('fin_voucher', [
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => $fiscalYear,
            ], $periodRange),
            $this->maxUpdatedTime('fin_voucher_detail', [
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => $fiscalYear,
            ], $periodRange),
            $this->maxUpdatedTime('fin_voucher_aux_value', [
                'account_set_id' => $this->accountSetId,
                'fiscal_year' => $fiscalYear,
            ], $periodRange),
            $this->maxUpdatedTime('fin_aux_opening_balance', [
                'account_set_id' => $this->accountSetId,
                'period' => $this->openingPeriodFor($period, 'fin_aux_opening_balance'),
            ]),
            $this->maxUpdatedTime('fin_subject', [
                'account_set_id' => $this->accountSetId,
            ]),
            $this->maxUpdatedTime('fin_subject_aux_config', [
                'account_set_id' => $this->accountSetId,
            ]),
        ];
        $times = array_values(array_filter($times, 'strlen'));
        if (empty($times)) {
            return '';
        }
        sort($times);
        return end($times);
    }

    protected function maxUpdatedTime($table, $where, $periodRange = null)
    {
        $query = db($table)->where($where);
        if ($periodRange !== null) {
            $query->where('period', 'between', $periodRange);
        }
        $row = $query->field('max(updated_time) as max_time')->find();
        return (string)($row['max_time'] ?? '');
    }

    public function auxBalanceSubjects($data = [])
    {
        $auth = $this->requirePermission('book:view');
        if ($auth) {
            return $auth;
        }

        $rows = db('fin_subject')->alias('s')
            ->join('fin_subject_aux_config case_cfg', 'case_cfg.subject_code=s.subject_code and case_cfg.account_set_id=s.account_set_id and case_cfg.del_flag=0')
            ->where([
                's.account_set_id' => $this->accountSetId,
                's.del_flag' => 0,
                's.status' => 1,
                's.leaf_flag' => 1,
                's.voucher_entry_flag' => 1,
                'case_cfg.aux_type_code' => 'case_no',
            ])
            ->field('s.subject_code,s.subject_name,s.direction')
            ->group('s.subject_code,s.subject_name,s.direction')
            ->order('s.subject_code asc')
            ->select();

        return $this->ok($rows, 'OK', count($rows));
    }

    protected function loadSubjectBalanceVoucherRows($startPeriod, $endPeriod)
    {
        $fiscalYear = $this->fiscalYear($endPeriod);
        return db('fin_voucher_detail')->alias('d')
            ->join('fin_voucher v', 'v.voucher_id=d.voucher_id and v.account_set_id=d.account_set_id and v.fiscal_year=d.fiscal_year and v.period=d.period')
            ->where([
                'd.account_set_id' => $this->accountSetId,
                'd.fiscal_year' => $fiscalYear,
                'd.del_flag' => 0,
                'v.fiscal_year' => $fiscalYear,
                'v.del_flag' => 0,
                'v.void_flag' => 0,
            ])
            ->where('d.period', 'between', [$startPeriod, $endPeriod])
            ->where('v.period', 'between', [$startPeriod, $endPeriod])
            ->where('v.status', 'in', ['SUBMITTED', 'AUDITED', 'POSTED', 'PRINTED'])
            ->field('d.subject_code,coalesce(sum(d.debit_amount),0) as debit_amount,coalesce(sum(d.credit_amount),0) as credit_amount')
            ->group('d.subject_code')
            ->order('d.subject_code asc')
            ->select();
    }

    protected function addSubjectBalanceAmount(&$balances, $subjectCode, $subjectMap, $codeRule, $bucket, $debitCents, $creditCents)
    {
        foreach ($this->ancestorSubjectCodes($subjectCode, $subjectMap, $codeRule) as $code) {
            if (!isset($balances[$code])) {
                continue;
            }
            $balances[$code][$bucket . '_debit_cents'] += $debitCents;
            $balances[$code][$bucket . '_credit_cents'] += $creditCents;
        }
    }

    protected function ancestorSubjectCodes($subjectCode, $subjectMap, $codeRule)
    {
        $codes = [];
        $lengths = $this->subjectCodeRuleLengths($codeRule);
        foreach ($lengths as $length) {
            if (strlen($subjectCode) < $length) {
                continue;
            }
            $code = substr($subjectCode, 0, $length);
            if (isset($subjectMap[$code])) {
                $codes[] = $code;
            }
        }
        if (isset($subjectMap[$subjectCode]) && !in_array($subjectCode, $codes, true)) {
            $codes[] = $subjectCode;
        }
        return array_values(array_unique($codes));
    }

    protected function formatSubjectBalanceRow($row)
    {
        $openingDebitCents = (int)$row['opening_debit_cents'];
        $openingCreditCents = (int)$row['opening_credit_cents'];
        $currentDebitCents = (int)$row['current_debit_cents'];
        $currentCreditCents = (int)$row['current_credit_cents'];
        $yearDebitCents = (int)$row['year_debit_cents'];
        $yearCreditCents = (int)$row['year_credit_cents'];
        $endingNetCents = $openingDebitCents - $openingCreditCents + $currentDebitCents - $currentCreditCents;
        $endingDebitCents = $endingNetCents > 0 ? $endingNetCents : 0;
        $endingCreditCents = $endingNetCents < 0 ? abs($endingNetCents) : 0;
        $balanceDirection = $endingNetCents > 0 ? 'DEBIT' : ($endingNetCents < 0 ? 'CREDIT' : 'BALANCED');

        return [
            'subject_code' => $row['subject_code'],
            'subject_name' => $row['subject_name'],
            'direction' => $row['direction'],
            'subject_type' => $row['subject_type'] ?? '',
            'level_no' => (int)$row['level_no'],
            'leaf_flag' => (int)$row['leaf_flag'],
            'opening_debit_amount' => $this->centsToDecimal($openingDebitCents),
            'opening_credit_amount' => $this->centsToDecimal($openingCreditCents),
            'debit_amount' => $this->centsToDecimal($currentDebitCents),
            'credit_amount' => $this->centsToDecimal($currentCreditCents),
            'year_debit_amount' => $this->centsToDecimal($yearDebitCents),
            'year_credit_amount' => $this->centsToDecimal($yearCreditCents),
            'ending_debit_amount' => $this->centsToDecimal($endingDebitCents),
            'ending_credit_amount' => $this->centsToDecimal($endingCreditCents),
            'balance_amount' => $this->centsToDecimal($endingNetCents),
            'balance_direction' => $balanceDirection,
        ];
    }

    protected function subjectBalanceRowVisible($row, $subjectStartCode, $subjectEndCode, $levelStart, $levelEnd, $maxLength, $zeroFilter)
    {
        $subjectCode = (string)($row['subject_code'] ?? '');
        if ($subjectStartCode !== '' && strcmp($subjectCode, $subjectStartCode) < 0) {
            return false;
        }
        if ($subjectEndCode !== '' && strcmp($subjectCode, $this->subjectRangeUpperBound($subjectEndCode, $maxLength)) > 0) {
            return false;
        }
        $levelNo = (int)($row['level_no'] ?? 1);
        if ($levelNo < $levelStart || $levelNo > $levelEnd) {
            return false;
        }
        if ($zeroFilter === 'active') {
            return $this->amountTextHasValue($row['debit_amount'] ?? 0) || $this->amountTextHasValue($row['credit_amount'] ?? 0);
        }
        if ($zeroFilter === 'balance') {
            return $this->amountTextHasValue($row['ending_debit_amount'] ?? 0) || $this->amountTextHasValue($row['ending_credit_amount'] ?? 0);
        }
        if ($zeroFilter === 'nonzero') {
            foreach ([
                'opening_debit_amount',
                'opening_credit_amount',
                'debit_amount',
                'credit_amount',
                'year_debit_amount',
                'year_credit_amount',
                'ending_debit_amount',
                'ending_credit_amount',
            ] as $field) {
                if ($this->amountTextHasValue($row[$field] ?? 0)) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    protected function amountTextHasValue($amount)
    {
        return $this->decimalToCents($amount) !== 0;
    }

    protected function reportYearStartPeriod($period)
    {
        $yearStart = substr((string)$period, 0, 4) . '-01';
        $enabledPeriod = $this->accountSetEnabledPeriod();
        if ($this->validPeriod($enabledPeriod) && substr($enabledPeriod, 0, 4) === substr((string)$period, 0, 4) && $enabledPeriod > $yearStart && $enabledPeriod <= $period) {
            return $enabledPeriod;
        }
        return $yearStart;
    }

    protected function openingPeriodFor($period, $table)
    {
        $enabledPeriod = $this->accountSetEnabledPeriod();
        if ($this->validPeriod($enabledPeriod) && substr($enabledPeriod, 0, 4) === substr((string)$period, 0, 4) && $enabledPeriod <= $period) {
            return $enabledPeriod;
        }
        $yearStart = substr((string)$period, 0, 4) . '-01';
        $existing = db($table)
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->where('period', 'between', [$yearStart, $period])
            ->order('period asc')
            ->value('period');
        return $existing ?: $this->reportYearStartPeriod($period);
    }

    protected function accountSetEnabledPeriod()
    {
        return db('fin_account_set')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->value('enabled_period') ?: '';
    }

    protected function validPeriod($period)
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string)$period) === 1;
    }

    protected function subjectInfoMap()
    {
        $subjects = db('fin_subject')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->field('subject_code,subject_name,direction')
            ->select();
        $map = [];
        foreach ($subjects as $subject) {
            $map[$subject['subject_code']] = $subject;
        }
        return $map;
    }

    protected function matchAuxBalanceFilter($caseNo, $receiptNo, $caseNoFilter, $receiptNoFilter)
    {
        if ($caseNo === '') {
            return false;
        }
        if ($caseNoFilter !== '' && strpos($caseNo, $caseNoFilter) === false) {
            return false;
        }
        if ($receiptNoFilter !== '' && strpos($receiptNo, $receiptNoFilter) === false) {
            return false;
        }
        return true;
    }

    protected function addAuxBalanceAmount(&$groups, $subjectCode, $subjectName, $direction, $caseNo, $receiptNo, $openingDebitCents, $openingCreditCents, $debitCents, $creditCents, $entryCount = null)
    {
        $caseNo = trim((string)$caseNo);
        if ($caseNo === '') {
            return;
        }
        $receiptNo = trim((string)$receiptNo);
        $key = $this->auxBalanceKey($subjectCode, $caseNo, $receiptNo);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                'subject_code' => $subjectCode,
                'subject_name' => $subjectName,
                'direction' => $direction ?: 'DEBIT',
                'case_no' => $caseNo,
                'receipt_no' => $receiptNo,
                'opening_debit_cents' => 0,
                'opening_credit_cents' => 0,
                'debit_cents' => 0,
                'credit_cents' => 0,
                'entry_count' => 0,
            ];
        }
        $groups[$key]['opening_debit_cents'] += $openingDebitCents;
        $groups[$key]['opening_credit_cents'] += $openingCreditCents;
        $groups[$key]['debit_cents'] += $debitCents;
        $groups[$key]['credit_cents'] += $creditCents;
        if ($entryCount !== null) {
            $groups[$key]['entry_count'] += max(0, (int)$entryCount);
        } elseif ($debitCents !== 0 || $creditCents !== 0) {
            $groups[$key]['entry_count']++;
        }
    }

    protected function subjectAuxConfigSubjectMap($auxTypeCode)
    {
        $rows = db('fin_subject_aux_config')->where([
            'account_set_id' => $this->accountSetId,
            'aux_type_code' => $auxTypeCode,
            'del_flag' => 0,
        ])->field('subject_code')->select();
        $map = [];
        foreach ($rows as $row) {
            $subjectCode = (string)($row['subject_code'] ?? '');
            if ($subjectCode !== '') {
                $map[$subjectCode] = true;
            }
        }
        return $map;
    }

    protected function buildAuxBalanceRows($groups, $receiptSubjectMap = [])
    {
        ksort($groups);
        $caseRows = [];
        foreach ($groups as $detail) {
            $caseKey = $detail['subject_code'] . '|' . $detail['case_no'];
            $receiptDimensionEnabled = !empty($receiptSubjectMap[$detail['subject_code']]);
            if (!isset($caseRows[$caseKey])) {
                $caseRows[$caseKey] = [
                    'row_key' => 'case|' . $caseKey,
                    'row_type' => 'CASE',
                    'subject_code' => $detail['subject_code'],
                    'subject_name' => $detail['subject_name'],
                    'direction' => $detail['direction'],
                    'case_no' => $detail['case_no'],
                    'receipt_no' => '',
                    'opening_debit_cents' => 0,
                    'opening_credit_cents' => 0,
                    'debit_cents' => 0,
                    'credit_cents' => 0,
                    'entry_count' => 0,
                    'monitor_count' => 0,
                    'receipt_dimension_enabled' => $receiptDimensionEnabled,
                    'children' => [],
                ];
            }
            foreach (['opening_debit_cents', 'opening_credit_cents', 'debit_cents', 'credit_cents', 'entry_count'] as $field) {
                $caseRows[$caseKey][$field] += $detail[$field];
            }
            if ($receiptDimensionEnabled) {
                $receiptRow = $this->formatAuxBalanceRow('RECEIPT', $detail);
                $caseRows[$caseKey]['children'][] = $receiptRow;
                if ($receiptRow['monitor_flag']) {
                    $caseRows[$caseKey]['monitor_count']++;
                }
            }
        }
        $rows = [];
        foreach ($caseRows as $caseRow) {
            if (!$caseRow['receipt_dimension_enabled']) {
                $caseRow['children'] = [];
            }
            $rows[] = $this->formatAuxBalanceRow('CASE', $caseRow);
        }
        return $rows;
    }

    protected function formatAuxBalanceRow($rowType, $row)
    {
        $openingBalanceCents = $this->normalBalanceCents($row['direction'], $row['opening_debit_cents'], $row['opening_credit_cents']);
        $endingBalanceCents = $this->normalBalanceCents(
            $row['direction'],
            $row['opening_debit_cents'] + $row['debit_cents'],
            $row['opening_credit_cents'] + $row['credit_cents']
        );
        $result = [
            'row_key' => $rowType === 'CASE' ? $row['row_key'] : 'receipt|' . $this->auxBalanceKey($row['subject_code'], $row['case_no'], $row['receipt_no']),
            'row_type' => $rowType,
            'subject_code' => $row['subject_code'],
            'subject_name' => $row['subject_name'],
            'direction' => $row['direction'],
            'case_no' => $row['case_no'],
            'receipt_no' => $row['receipt_no'],
            'opening_balance_amount' => $this->centsToDecimal($openingBalanceCents),
            'debit_amount' => $this->centsToDecimal($row['debit_cents']),
            'credit_amount' => $this->centsToDecimal($row['credit_cents']),
            'ending_balance_amount' => $this->centsToDecimal($endingBalanceCents),
            'entry_count' => $row['entry_count'],
            'monitor_flag' => ($rowType === 'RECEIPT' || ($rowType === 'CASE' && empty($row['children']))) && $endingBalanceCents !== 0,
            'monitor_count' => $row['monitor_count'] ?? 0,
        ];
        if ($rowType === 'CASE') {
            $result['children'] = $row['children'];
        }
        return $result;
    }

    protected function normalBalanceCents($direction, $debitCents, $creditCents)
    {
        return strtoupper((string)$direction) === 'CREDIT'
            ? (int)$creditCents - (int)$debitCents
            : (int)$debitCents - (int)$creditCents;
    }

    protected function auxBalanceKey($subjectCode, $caseNo, $receiptNo)
    {
        return $subjectCode . '|' . $caseNo . '|' . $receiptNo;
    }

    protected function subjectCodeRule()
    {
        $rule = db('fin_account_set')
            ->where([
                'account_set_id' => $this->accountSetId,
                'del_flag' => 0,
            ])
            ->value('subject_code_rule');
        $parts = array_filter(explode('-', (string)($rule ?: '4-2-2-2')), 'strlen');
        $result = [];
        foreach ($parts as $part) {
            $length = (int)$part;
            if ($length > 0) {
                $result[] = $length;
            }
        }
        return empty($result) ? [4, 2, 2, 2] : $result;
    }

    protected function subjectCodeRuleLengths($codeRule)
    {
        $lengths = [];
        $total = 0;
        foreach ($codeRule as $length) {
            $total += (int)$length;
            $lengths[] = $total;
        }
        return $lengths;
    }

    protected function subjectSummaryCode($subjectCode, $subjectLevel, $codeRule)
    {
        $lengths = $this->subjectCodeRuleLengths($codeRule);
        if (empty($lengths)) {
            return $subjectCode;
        }
        $index = max(0, min((int)$subjectLevel, count($lengths)) - 1);
        $targetLength = $lengths[$index];
        if (strlen($subjectCode) <= $targetLength) {
            return $subjectCode;
        }
        return substr($subjectCode, 0, $targetLength);
    }

    protected function subjectRangeUpperBound($subjectCode, $maxLength)
    {
        $subjectCode = trim((string)$subjectCode);
        if ($subjectCode === '') {
            return '';
        }
        $maxLength = max(strlen($subjectCode), (int)$maxLength);
        return str_pad($subjectCode, $maxLength, '9');
    }
}
