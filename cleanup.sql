 START TRANSACTION;

  SET FOREIGN_KEY_CHECKS = 0;

  DELETE FROM audit_logs
  WHERE entity_type IN (
    'customer',
    'property',
    'owner_agreement',
    'tenant_agreement',
    'account_transaction',
    'work_order',
    'vendor'
  );

  DELETE FROM account_transaction_allocations;
  DELETE FROM account_transactions
  WHERE source_type <> 'petty_cash';

  DELETE FROM agreement_dispute_comments;
  DELETE FROM agreement_disputes;
  DELETE FROM agreement_additional_payments;

  DELETE FROM work_order_payments;
  DELETE FROM stock_movements
  WHERE reference_type LIKE '%work_order%';

  DELETE FROM work_order_lines;
  DELETE FROM work_orders;

  DELETE FROM tenant_agreement_status_history;
  DELETE FROM tenant_agreement_installments;
  DELETE FROM tenant_agreement_properties;
  DELETE FROM tenant_agreements;

  DELETE FROM owner_agreement_status_history;
  DELETE FROM owner_agreement_installments;
  DELETE FROM owner_agreement_properties;
  DELETE FROM owner_agreements;

  DELETE properties
  FROM properties
  JOIN customer_role_assignments
    ON customer_role_assignments.customer_id = properties.owner_customer_id;

  DELETE FROM customer_role_assignments;
  DELETE FROM customers;
  DELETE FROM vendors;

  UPDATE quotations
  SET work_order_id = NULL,
      vendor_id = NULL;

  UPDATE invoices
  SET work_order_id = NULL,
      vendor_id = NULL;

  SET FOREIGN_KEY_CHECKS = 1;

  COMMIT;