ALTER TABLE llx_mrpbatch_event ADD INDEX idx_mrpbatch_event_code (code);
ALTER TABLE llx_mrpbatch_event ADD INDEX idx_mrpbatch_event_element (element_type, fk_element);
ALTER TABLE llx_mrpbatch_event ADD INDEX idx_mrpbatch_event_datec (datec);
