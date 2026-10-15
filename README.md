# Module Dolibarr « mrpbatch » — Atelier

Écrans web (dans Dolibarr) et tablette (plein écran) pour l'atelier :

| Écran | Rôle |
|---|---|
| **Production** | Tableau de bord des OF filtrable par poste de travail ; consommation et production par scan (lots, séries, DLC/DLUO, codes GS1) |
| **Planning** | Gantt jour / 3 jours / semaine par poste (agenda sur téléphone), replanification, OF non planifiés, abonnement .ics |
| **Réception** | Réception des matières premières avec ou sans commande fournisseur : scan produit / GS1, lot fournisseur, DLC, DLUO, contrôle conforme / non conforme (quarantaine) |
| **Commandes** | Kanban des commandes clients par étape (à préparer → … → prête), préparation par scan, lots prélevés, colis / poids, OF liés, ruptures |

Accès : menu GPAO → **Atelier**, menu du haut **Atelier** (tablette), raccourcis dans Stocks et Commandes.

## Installation / mise à jour

1. Déployer `module_mrpbatch-2.0.2.zip` (Accueil → Configuration → Modules → Déployer un module externe).
2. **Désactiver puis réactiver** le module (crée les 3 tables, le champ « Poste de travail (atelier) » des OF, les évènements Webhook et les nouveaux menus).
3. Configuration du module : vérifier la section *Diagnostic*.

Prérequis : Dolibarr ≥ 17, modules GPAO, Stocks, Lots/Séries. Facultatifs : Postes de travail (sélecteur de poste), Commandes fournisseurs, Commandes clients, Expéditions, Webhook, API REST.

## Postes de travail

Un OF est rattaché à un poste s'il contient une opération (ligne de gamme) portant ce poste **ou** si son champ « Poste de travail (atelier) » est renseigné.

## Agenda .ics

`/custom/mrpbatch/ics.php?entity=1&key=…[&ws=ID][&orders=1]` — adresses prêtes à copier dans la configuration du module (une par poste). Clé régénérable.

## Évènements Webhook

`MRPBATCH_MO_START`, `MRPBATCH_MO_CONSUME`, `MRPBATCH_MO_PRODUCE`, `MRPBATCH_MO_CLOSE`, `MRPBATCH_MO_RESCHEDULE`, `MRPBATCH_RECEPTION`, `MRPBATCH_RECEPTION_REFUSED`, `MRPBATCH_ORDER_PICK`, `MRPBATCH_ORDER_STEP`, `MRPBATCH_ORDER_READY`.
À cocher dans Webhook → Cibles. Corps : `{"triggercode": "...", "object": {code, element_type, element_ref, product_ref, batch, qty, eatby, sellby, data{...}, user_login, ...}}`. Chaque évènement est aussi journalisé dans `llx_mrpbatch_event`.

## API REST

Préfixe : **`/api/index.php/mrpbatchapi/`** (règle Dolibarr pour une classe `MrpbatchApi` ; l'ancien chemin `/mrpbatch/` documenté en 1.3.1 renvoyait 404).
`GET mos`, `GET mos/{id}/consumelines`, `POST mos/{id}/consumebatch`, `GET mos/{id}/producelines`, `POST mos/{id}/producebatch`, `POST mos/{id}/close`, `GET workstations`, `GET planning?from=AAAA-MM-JJ&to=AAAA-MM-JJ&ws=`, `POST receptions`, `GET orders`, `POST orders/{id}/step`, `POST orders/{id}/pick`, `GET events`.

## Changelog

### 2.0.2
- Correctif : « Include of main fails » sur toutes les pages quand le module est installé via un lien symbolique (`htdocs/custom/mrpbatch` → clone git). Les pages cherchent désormais `main.inc.php` à partir du chemin appelé, pas du chemin réel.

### 2.0.1
- Accessibilité (audit axe-core WCAG 2.2 AA, 0 violation sur 17 vues en thèmes clair et sombre) : contrastes des aplats et des lignes terminées, cibles du planning ≥ 24 px, repères (navigation, recherche), titres, annonce des messages aux lecteurs d'écran, focus piégé et restitué dans les modales, états « sélectionné » exposés, icônes masquées des aides techniques.
- Respect du réglage système « réduire les animations ».

### 2.0.0
- Interface entièrement revue : une seule application (CSS/JS partagés) pour le web et la tablette, mise en page selon la largeur réelle (téléphone, tablette portrait/paysage, écran de bureau), panneau de saisie ancré ou plein écran, pavé numérique, retours visuels/sonores.
- Sélecteur de poste de travail (tableau de bord, planning, flux ics).
- Nouveaux écrans : Réception matières, Planning, Commandes clients.
- Décodage GS1-128 / DataMatrix (GTIN, lot, DLC, DDM, quantité, poids).
- Lot périmé jamais présélectionné à la consommation.
- Flux iCalendar, évènements Webhook, journal atelier, nouveaux points d'API REST.
- Correctif : documentation du préfixe API (`mrpbatchapi`).

## Contenu du dépôt

- `mrpbatch/` : le module. Paquet installable : `zip -rq module_mrpbatch-2.0.2.zip mrpbatch`.
- `tests/` : maquette Playwright (`mock.js`, `shots.js`), audit d'accessibilité axe-core (`axe.js`), tests d'intégration sur un vrai Dolibarr 22 (`activate.php`, `seed.php`, `ajax_test.py`).
- `docs/captures/` : captures web (dans Dolibarr) et tablette.
