update fin_case_fund_payment
set payment_method = 'UNKNOWN',
    updated_by = 'system',
    updated_time = current_timestamp
where del_flag = 0
  and trim(coalesce(bank_serial_no, '')) = ''
  and payment_method <> 'UNKNOWN';

update fin_case_fund_payment
set payment_method = 'POS',
    updated_by = 'system',
    updated_time = current_timestamp
where del_flag = 0
  and (
      char_length(trim(bank_serial_no)) = 10
      or char_length(trim(bank_serial_no)) = 12
  )
  and payment_method <> 'POS';

update fin_case_fund_payment
set payment_method = 'TRANSFER',
    updated_by = 'system',
    updated_time = current_timestamp
where del_flag = 0
  and char_length(trim(bank_serial_no)) > 15
  and payment_method <> 'TRANSFER';
