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
  year?: number
  period?: string
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
  bank_account_name?: string
  bank_account_no?: string
  bank_name?: string
  bank_branch_name?: string
  paper_size?: string
  voucher_import_auto_no?: number
  voucher_print_line_count?: number
  is_current?: number
  status?: number
  remark?: string
  id?: string
  code?: string
  name?: string
  available_years?: number[]
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

export interface VoucherExportRow {
  voucher_id: string
  period: string
  voucher_date: string
  voucher_word: string
  voucher_no: string
  line_no: number
  summary: string
  subject_code: string
  subject_name: string
  aux_desc: string
  debit_amount: string
  credit_amount: string
  attachment_count: number
  prepared_by_name: string
  audit_by_name: string
  posted_by_name: string
  status: string
  printed_flag: string
  source_type: string
}

export interface VoucherExportResult {
  items: VoucherExportRow[]
  voucher_count: number
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
  voucherEntryFlag?: number
  voucher_entry_flag?: number
  status?: number
  remark?: string
  aux_names?: string[]
  aux_names_text?: string
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
  voucher_id?: string
  period?: string
  voucher_date?: string
  voucher_no?: number
  subject_code?: string
  subject_name?: string
  debit_amount?: string | number
  credit_amount?: string | number
  aux_desc?: string
  voucherDate: string
  voucherNo: number
  summary: string
  subjectCode: string
  debitAmount: number
  creditAmount: number
  auxDesc: string
}

export interface SubjectSummaryRow {
  subject_code: string
  subject_name: string
  subject_type?: string
  level_no: number
  leaf_flag?: number
  direction: 'DEBIT' | 'CREDIT' | string
  debit_amount: string | number
  credit_amount: string | number
  balance_amount: string | number
  entry_count: number
}

export interface AuxBalanceRow {
  row_key: string
  row_type: 'CASE' | 'RECEIPT'
  subject_code: string
  subject_name: string
  direction: 'DEBIT' | 'CREDIT' | string
  case_no: string
  receipt_no: string
  opening_balance_amount: string | number
  debit_amount: string | number
  credit_amount: string | number
  ending_balance_amount: string | number
  entry_count: number
  monitor_flag: boolean
  monitor_count: number
  receipt_count?: number
  children?: AuxBalanceRow[]
}

export interface AuxBalanceResult {
  items: AuxBalanceRow[]
  total: number
  monitor_total?: number
  ending_total?: string | number
  period: string
  page?: number
  pagesize?: number
  snapshot_time?: string
}

export interface CaseMovementDetailRow {
  snapshot_id: string
  voucher_id: string
  fiscal_year: number
  period: string
  voucher_date: string
  voucher_word: string
  voucher_no: number
  voucher_label: string
  voucher_status: string
  detail_id: string
  line_no: number
  subject_code: string
  subject_name: string
  direction: 'DEBIT' | 'CREDIT' | string
  case_no: string
  receipt_no: string
  summary: string
  debit_amount: string | number
  credit_amount: string | number
}

export interface CaseMovementDetailResult {
  items: CaseMovementDetailRow[]
  total: number
  debit_total: string | number
  credit_total: string | number
  start_period: string
  end_period: string
  snapshot_time?: string
  page: number
  pagesize: number
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
  claim_date?: string | null
  posting_date: string
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

export type CaseFundReturnMatchStatus =
  | 'INELIGIBLE'
  | 'UNMATCHED'
  | 'AUTO_CANDIDATE'
  | 'AMBIGUOUS'
  | 'CONFIRMED'
  | 'REJECTED'

export interface CaseFundReturnMatchStats {
  total_unknown: number
  ineligible: number
  unmatched: number
  auto_candidate: number
  ambiguous: number
  confirmed: number
  rejected: number
  candidate_pairs: number
  saved_pairs?: number
}

export interface CaseFundReturnMatchItem {
  match_id: string
  account_set_id: string
  fiscal_year: number
  period: string
  unknown_payment_id: string
  refund_id: string
  match_status: CaseFundReturnMatchStatus
  match_rule: string
  candidate_count: number
  decision_by?: string
  decision_time?: string
  remark?: string
  payer_name?: string
  unknown_amount: string | number
  payer_account_no?: string
  transaction_date?: string
  claim_status?: string
  claim_user_name?: string
  claim_date?: string
  case_no?: string
  out_order_no?: string
  out_status?: string
  out_type?: string
  actual_payee_name?: string
  refund_amount: string | number
  payee_bank_account_no?: string
  refund_date?: string
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
  return_match_status?: CaseFundReturnMatchStatus
  return_match_count?: number
  return_match_id?: string
  matched_unknown_payment_id?: string
}

export interface CaseFundUnknownPayment {
  unknown_payment_id: string
  account_set_id: string
  fiscal_year: number
  period: string
  transaction_date: string
  transaction_time: string
  court_bank_account_no?: string
  arrival_method?: string
  unknown_amount: string | number
  payer_bank_name?: string
  payer_account_no?: string
  payer_name?: string
  bank_summary?: string
  bank_remark?: string
  aux_case_code?: string
  claim_status?: 'UNCLAIMED' | 'CLAIMED' | string
  claim_user_name?: string
  claim_date?: string
  source_file_name?: string
  source_row_no?: number
  voucher_status: 'UNGENERATED' | 'GENERATED' | 'VOIDED' | string
  voucher_id?: string
  voucher_no?: number
  voucher_period?: string
  voucher_generated_time?: string
  return_match_status?: CaseFundReturnMatchStatus
  return_match_count?: number
  return_match_id?: string
  matched_refund_id?: string
}

export interface CaseFundDistribution {
  distribution_id: string
  account_set_id: string
  fiscal_year: number
  period: string
  distribution_no: string
  in_case_no: string
  in_judge_name?: string
  in_party_name?: string
  out_case_no: string
  out_judge_name?: string
  out_party_name?: string
  invoice_type: string
  receipt_no: string
  distribution_amount: string | number
  applicant_name?: string
  apply_date?: string
  receive_date: string
  business_status?: string
  description?: string
  original_invoice_date?: string
  source_file_name?: string
  source_row_no?: number
  voucher_status: 'UNGENERATED' | 'GENERATED' | 'VOIDED' | string
  voucher_id?: string
  voucher_no?: number
  voucher_period?: string
  voucher_generated_time?: string
}

export interface CaseFundBankStatement {
  statement_id: string
  account_set_id?: string
  fiscal_year?: number
  period?: string
  bank_code: string
  bank_name?: string
  transaction_date?: string
  transaction_time: string
  direction?: 'DEBIT' | 'CREDIT' | string
  debit_amount: string | number
  credit_amount: string | number
  balance_amount: string | number
  counterparty_account_no?: string
  counterparty_account_name?: string
  counterparty_bank_name?: string
  purpose?: string
  postscript?: string
  bank_serial_no: string
  reconcile_status?: string
  reconcile_items?: CaseFundBankReconcile[]
  source_file_name?: string
  source_row_no?: number
}

export interface CaseFundBankReconcile {
  reconcile_id: string
  account_set_id?: string
  fiscal_year?: number
  period?: string
  reconcile_date: string
  statement_id?: string
  biz_type?: 'PAYMENT' | 'UNKNOWN_PAYMENT' | 'REFUND' | string
  biz_id?: string
  biz_no?: string
  bank_serial_no?: string
  bank_amount: string | number
  biz_amount: string | number
  diff_amount: string | number
  match_status: string
  match_rule?: string
  matched_by?: string
  matched_time?: string
  bank_direction?: 'DEBIT' | 'CREDIT' | string
  bank_summary?: string
  biz_summary?: string
}

export interface CaseFundSubjectConfig {
  config_id?: string
  account_set_id?: string
  biz_type?: string
  voucher_biz_type?: 'PAYMENT' | 'PAYMENT_METHOD' | 'REFUND' | 'UNKNOWN_PAYMENT' | 'DISTRIBUTION'
  business_item_type: string
  business_item_label?: string
  debit_subject_code: string
  debit_subject_name?: string
  credit_subject_code: string
  credit_subject_name?: string
  remark?: string
}

export interface CaseFundSubjectConfigResult {
  items: CaseFundSubjectConfig[]
  biz_type: string
  voucher_biz_type: 'PAYMENT' | 'PAYMENT_METHOD' | 'REFUND' | 'UNKNOWN_PAYMENT' | 'DISTRIBUTION'
  generate_voucher_by_day_flag?: number
  generate_payment_voucher_by_method_flag?: number
  generate_refund_voucher_by_out_order_no_flag?: number
  skip_confirmed_return_voucher_flag?: number
  payment_method_items?: CaseFundSubjectConfig[]
}

export type CaseFundJobStatus = 'QUEUED' | 'RUNNING' | 'SUCCEEDED' | 'PARTIAL_FAILED' | 'FAILED'

export type CaseFundJobStage =
  | 'PARSING'
  | 'VALIDATING'
  | 'MATCHING'
  | 'WRITING'
  | 'GENERATING'
  | 'FINALIZING'
  | string

export interface CaseFundJob {
  job_id: string
  account_set_id?: string
  fiscal_year?: number
  period?: string
  job_type: string
  client_request_id: string
  status: CaseFundJobStatus
  stage?: CaseFundJobStage
  progress_percent?: number
  total_count?: number
  processed_count?: number
  success_count?: number
  failure_count?: number
  message?: string
  error_message?: string
  launcher_warning?: string
  result?: Record<string, any> | null
  source_file_name?: string
  queued_time?: string
  started_time?: string
  heartbeat_time?: string
  finished_time?: string
  created_time?: string
  updated_time?: string
  remark?: string
}

export interface CaseFundJobCreateRequest {
  job_type: string
  payload: Record<string, any>
  client_request_id: string
  filename?: string
  content_base64?: string
}

export interface CaseFundJobListParams {
  job_type?: string
  status?: CaseFundJobStatus | ''
  client_request_id?: string
  page?: number
  pagesize?: number
}

export interface CaseFundJobListResult {
  items: CaseFundJob[]
  total: number
  page?: number
  pagesize?: number
}

export interface CaseFundJobError {
  item_id: string
  job_id: string
  account_set_id?: string
  fiscal_year?: number
  period?: string
  item_no?: number
  source_row_no?: number
  source_type?: string
  source_id?: string
  group_key?: string
  amount?: string | number
  status?: string
  result?: Record<string, any> | null
  error_code?: string
  error_message: string
  voucher_id?: string
  voucher_no?: number
  voucher_period?: string
  started_time?: string
  finished_time?: string
  created_time?: string
  updated_time?: string
  remark?: string
}

export interface CaseFundJobErrorListResult {
  items: CaseFundJobError[]
  total: number
  page?: number
  pagesize?: number
}
