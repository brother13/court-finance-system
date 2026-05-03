export interface Result<T> {
  code: number
  message: string
  data: T
}

export interface Unit {
  unit_id: string
  unit_code: string
  unit_name: string
}

export interface LoginUser {
  user_id: string
  username: string
  real_name: string
  unit_id: string
  unit_code: string
  unit_name: string
  account_set_id?: string
  permissions?: string[]
  view_scope?: 'ALL' | 'SELF'
  must_change_password?: number | boolean
  roles?: RoleBrief[]
  account_sets?: AccountSet[]
}

export interface RoleBrief {
  role_id: string
  role_code: string
  role_name: string
  view_scope?: 'ALL' | 'SELF'
  status?: number
}

export interface AccountSet {
  account_set_id: string
  set_code: string
  set_name: string
  biz_type: string
  enabled_year: number
  remark?: string
  id?: string
  code?: string
  name?: string
}

export interface Permission {
  permission_code: string
  permission_name: string
  permission_type: 1 | 2
  module_code: string
  description?: string
  sort_order?: number
}

export interface Role {
  role_id: string
  role_code: string
  role_name: string
  description?: string
  is_system: number
  view_scope: 'ALL' | 'SELF'
  status: number
  permission_count?: number
  user_count?: number
  permission_codes?: string[]
}

export interface ManagedUser {
  user_id: string
  unit_id: string
  username: string
  real_name: string
  mobile?: string
  email?: string
  status: number
  must_change_password?: number
  last_login_time?: string
  unit_name?: string
  role_names?: string
  account_set_names?: string
  role_ids?: string[]
  account_set_ids?: string[]
}

export interface VoucherDetail {
  detailId?: string
  lineNo?: number
  subjectCode?: string
  subject_code?: string
  summary?: string
  debitAmount?: number
  debit_amount?: number
  creditAmount?: number
  credit_amount?: number
  verificationStatus?: string
  auxDesc?: string
  auxValues?: VoucherAuxValue[]
  aux_values?: VoucherAuxValue[]
}

export interface VoucherAuxValue {
  auxTypeCode?: string
  aux_type_code?: string
  auxValue?: string
  aux_value?: string
  auxLabel?: string
  aux_label?: string
}

export interface Voucher {
  voucherId?: string
  voucher_id?: string
  period: string
  voucherDate?: string
  voucher_date?: string
  voucherNo?: number
  voucher_word?: string
  attachment_count?: number
  summary?: string
  status?: string
  sourceType?: string
  source_type?: string
  details: VoucherDetail[]
}

export interface Subject {
  subjectId?: string
  subject_id?: string
  subjectCode: string
  subject_code?: string
  subjectName: string
  subject_name?: string
  parentCode?: string
  parent_code?: string
  direction: 'DEBIT' | 'CREDIT'
  subjectType: string
  subject_type?: string
  levelNo?: number
  level_no?: number
  leafFlag?: number
  leaf_flag?: number
}

export interface SubjectAuxConfig {
  config_id?: string
  subject_code: string
  aux_type_code: string
  required_flag: number
  verification_flag: number
}

export interface LedgerRow {
  voucherDate: string
  voucherNo: number
  summary: string
  subjectCode: string
  debitAmount: number
  creditAmount: number
  auxDesc: string
}
