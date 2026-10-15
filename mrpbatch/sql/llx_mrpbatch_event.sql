-- Module Mrpbatch : journal des évènements atelier (réceptions, consommations, productions, préparation de commandes…)
-- Chaque ligne est aussi l'objet transmis aux triggers (module Webhook).
CREATE TABLE llx_mrpbatch_event
(
	rowid           integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity          integer DEFAULT 1 NOT NULL,
	datec           datetime NOT NULL,
	code            varchar(64) NOT NULL,
	label           varchar(255),
	element_type    varchar(32),
	fk_element      integer,
	element_ref     varchar(128),
	fk_soc          integer,
	fk_product      integer,
	batch           varchar(128),
	qty             double(24,8),
	eatby           date,
	sellby          date,
	fk_warehouse    integer,
	fk_workstation  integer,
	status          smallint DEFAULT 1 NOT NULL,
	note            text,
	data            text,
	fk_user         integer,
	tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
