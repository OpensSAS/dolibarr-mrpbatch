-- Module Mrpbatch : étape de préparation / conditionnement d'une commande client
CREATE TABLE llx_mrpbatch_orderprep
(
	rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity          integer DEFAULT 1 NOT NULL,
	fk_commande     integer NOT NULL,
	step            varchar(32) NOT NULL,
	nb_packages     integer,
	weight          double(24,8),
	note            text,
	date_step       datetime,
	fk_user_step    integer,
	tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
