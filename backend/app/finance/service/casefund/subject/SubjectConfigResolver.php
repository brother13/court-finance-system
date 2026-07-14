<?php

namespace app\finance\service\casefund\subject;

use app\finance\service\casefund\policy\CaseFundBusinessPolicy;

class SubjectConfigResolver
{
    protected $accountSetId;
    protected $dbFactory;
    protected $policy;
    protected $subjectConfigFields;

    public function __construct($accountSetId, callable $dbFactory, CaseFundBusinessPolicy $policy, $subjectConfigFields)
    {
        $this->accountSetId = $accountSetId;
        $this->dbFactory = $dbFactory;
        $this->policy = $policy;
        $this->subjectConfigFields = $subjectConfigFields;
    }

    public function configsByBusinessItems($bizType, $voucherBizType, $businessItems = null)
    {
        return $this->canonicalMap(
            $bizType,
            $voucherBizType,
            $this->rowsByBusinessItems($bizType, $voucherBizType, $businessItems)
        );
    }

    public function directConfigsByBusinessItems($bizType, $voucherBizType, $businessItems = null)
    {
        return $this->directMap(
            $this->rowsByBusinessItems($bizType, $voucherBizType, $businessItems)
        );
    }

    public function orderedConfigsByBusinessItems($bizType, $voucherBizType, $businessItems)
    {
        $configs = $this->configsByBusinessItems($bizType, $voucherBizType, $businessItems);
        return $this->orderedFromMap($businessItems, $configs);
    }

    public function paymentMethodConfigMap($bizType)
    {
        return $this->directConfigsByBusinessItems(
            $bizType,
            'PAYMENT_METHOD',
            array_keys($this->policy->paymentMethodOptions())
        );
    }

    protected function canonicalMap($bizType, $voucherBizType, $rows)
    {
        $configs = [];
        foreach ($rows as $row) {
            $businessItemType = $row['business_item_type'] ?? '';
            $key = $this->policy->voucherSubjectConfigKey($bizType, $voucherBizType, $businessItemType);
            if ($key === $businessItemType || !isset($configs[$key])) {
                $configs[$key] = $row;
            }
        }
        return $configs;
    }

    protected function orderedFromMap($businessItems, $configs)
    {
        $result = [];
        foreach ($businessItems as $businessItem) {
            if (isset($configs[$businessItem])) {
                $result[] = $configs[$businessItem];
            }
        }
        return $result;
    }

    protected function directMap($rows)
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$row['business_item_type']] = $row;
        }
        return $map;
    }

    protected function rowsByBusinessItems($bizType, $voucherBizType, $businessItems)
    {
        // null 表示读取该凭证业务的全部配置；空数组不能进入 TP5 的空 IN 条件。
        if (is_array($businessItems) && empty($businessItems)) {
            return [];
        }

        $factory = $this->dbFactory;
        $query = $factory('fin_case_fund_subject_config')->where([
            'account_set_id' => $this->accountSetId,
            'biz_type' => $bizType,
            'voucher_biz_type' => $voucherBizType,
            'del_flag' => 0,
        ]);
        if ($businessItems !== null) {
            $lookupItems = $this->policy->subjectConfigLookupItems($bizType, $voucherBizType, $businessItems);
            if (empty($lookupItems)) {
                return [];
            }
            $query->where('business_item_type', 'in', $lookupItems);
        }
        return $query->field($this->subjectConfigFields)->select();
    }
}
