ALTER TABLE llx_mrpbatch_orderline ADD UNIQUE INDEX uk_mrpbatch_orderline (fk_commandedet);
ALTER TABLE llx_mrpbatch_orderline ADD INDEX idx_mrpbatch_orderline_cmd (fk_commande);
