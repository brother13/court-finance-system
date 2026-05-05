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
  enabled_period?: string
  enabled_period_label?: string
  current_period?: string
  current_period_label?: string
  finance_manager?: string
  paper_size?: string
  voucher_import_auto_no?: number
  voucher_print_line_count?: number
  is_current?: number
  status?: number
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
  debitAmount?: number
  debit_amount?: number
  creditAmount?: number
  credit_amount?: number
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
  voucher_entry_flag?: number
  status?: number
  remark?: string
  children?: Subject[]
}

export interface SubjectCodeRule {
  rule: string
  segments: number[]
  lengths: number[]
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

export interface CaseFundPayment {
  payment_id: string
  account_set_id: string
  fiscal_year: number
  period: string
  case_no: string
  confirmed_flag: number
  available_flag: number
  business_type: string
  payer_name?: string
  party_name?: string
  invoice_title?: string
  payment_amount: string | number
  register_type?: string
  trial_case_no?: string
  payment_date: string
  payment_time?: string
  receipt_no?: string
  invoice_date?: string
  invoice_operator?: string
  payment_method?: string
  cashier_name?: string
  judge_name?: string
  clerk_name?: string
  department_name?: string
  bank_account_no?: string
  bank_serial_no?: string
  payment_order_no?: string
  internal_transfer_ticket_no?: string
  deposit_revoke_flag: number
  source_file_name?: string
  source_row_no?: number
  voucher_status: 'UNGENERATED' | 'GENERATED' | 'VOIDED' | string
  voucher_id?: string
  voucher_no?: number
  voucher_period?: string
  voucher_generated_time?: string
}

export interface CaseFundRefund {
  refund_id: string
  account_set_id: string
  fiscal_year: number
  period: string
  case_no: string
  handler_name?: string
  clerk_name?: string
  receipt_no?: string
  invoice_date?: string
  refund_date: string
  source_receipt_no?: string
  source_receipt_date?: string
  out_order_no?: string
  out_status?: string
  out_type: string
  litigation_position?: string
  party_name?: string
  refund_amount: string | number
  total_refund_amount: string | number
  payee_party_relation?: string
  payment_method?: string
  actual_payee_name?: string
  payee_identity_no?: string
  payee_bank_account_name?: string
  payee_bank_account_no?: string
  payee_bank_name?: string
  unionpay_no?: string
  same_bank_flag?: string
  handler_note?: string
  applicant_name?: string
  source_file_name?: string
  source_row_no?: number
  voucher_status: 'UNGENERATED' | 'GENERATED' | 'VOIDED' | string
  voucher_id?: string
  voucher_no?: number
  voucher_period?: string
  voucher_generated_time?: string
}

export interface CaseFundSubjectConfig {
  config_id?: string
  account_set_id?: string
  biz_type?: string
  voucher_biz_type?: 'PAYMENT' | 'REFUND'
  business_item_type: string
  debit_subject_code: string
  debit_subject_name?: string
  credit_subject_code: string
  credit_subject_name?: string
  remark?: string
}

export interface CaseFundSubjectConfigResult {
  items: CaseFundSubjectConfig[]
  biz_type: string
  voucher_biz_type: 'PAYMENT' | 'REFUND'
  generate_voucher_by_day_flag?: number
}
