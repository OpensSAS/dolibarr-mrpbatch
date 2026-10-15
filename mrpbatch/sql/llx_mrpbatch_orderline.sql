-- Module Mrpbatch : quantités préparées par ligne de commande client
CREATE TABLE llx_mrpbatch_orderline
(
	rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_commande     integer NOT NULL,
	fk_commandedet  integer NOT NULL,
	qty_prepared    double(24,8) DEFAULT 0 NOT NULL,
	batches         text,
	date_prep       datetime,
	fk_user         integer,
	tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
