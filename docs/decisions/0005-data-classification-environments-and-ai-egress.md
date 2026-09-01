# 0005 — Classification des données, environnements et sortie IA

Status: **ACCEPTED**
Decision authority: GitHub issue #19
Parent epic: #16
Evidence audit: #20
Materialization task: #25

## Context

Personal Secretary manipulera à terme des données personnelles et familiales
sensibles. Les frontières de classification, d'environnement, de logging et de
sortie vers un fournisseur IA doivent être fixées avant les premières entités
réelles ou intégrations.

Le dépôt est public et reste entièrement synthétique.

## Decision

### Classification des données

Chaque objet/champ futur doit pouvoir être classifié avec une taxonomie explicite :

```text
SYNTHETIC_PUBLIC
PERSONAL
SENSITIVE_PERSONAL
HIGHLY_SENSITIVE
SECRET
```

- `SYNTHETIC_PUBLIC` : données entièrement inventées utilisables comme
  exemples/fixtures publiques ;
- `PERSONAL` : données se rapportant à une personne sans relever par défaut des
  catégories les plus sensibles ;
- `SENSITIVE_PERSONAL` : informations privées dont la divulgation ou mauvaise
  utilisation présente un impact matériel ;
- `HIGHLY_SENSITIVE` : données nécessitant minimisation et frontières renforcées,
  notamment lorsqu'elles touchent vie familiale, documents privés ou finances ;
- `SECRET` : secrets opérationnels ou d'authentification tels que credentials,
  tokens, mots de passe et clés ; ils ne sont jamais des fixtures du dépôt.

La classification concrète appartient à chaque futur objet/champ et doit être
revue dans la Task qui l'introduit.

### Contrat obligatoire par domaine

Toute future entité, intégration ou domaine de données doit pouvoir déclarer :

```text
DATA_CLASSIFICATION
PII_FIELDS
SENSITIVE_FIELDS
PROD_TO_PREPROD_POLICY
PREPROD_TO_DEV_POLICY
RETENTION_POLICY
LOGGING_POLICY
AI_PROVIDER_POLICY
```

L'absence de classification/policy explicite ne constitue pas une permission
d'exporter, journaliser ou transmettre la donnée.

### Politique de sortie IA

```text
MAY_SEND
MUST_MINIMIZE
LOCAL_ONLY
FORBIDDEN
```

- `MAY_SEND` : transmission possible dans une capacité IA autorisée, sous réserve
  des autres validations/policies ;
- `MUST_MINIMIZE` : seule une représentation strictement nécessaire,
  minimisée/redacted, peut sortir ;
- `LOCAL_ONLY` : la donnée ne quitte pas la frontière locale autorisée ;
- `FORBIDDEN` : aucun traitement par fournisseur IA n'est autorisé.

La sortie est déterministe et intervient avant tout appel provider :

```text
domain data
-> classification
-> minimization/redaction
-> egress policy
-> only then Drupal AI provider abstraction
```

Drupal AI reste l'abstraction provider lorsque l'IA est introduite, mais ne
contourne jamais cette policy.

### Dépôt public et environnements

```text
PUBLIC_REPOSITORY = SYNTHETIC_ONLY
DEV/DDEV = SYNTHETIC_ONLY BY DEFAULT
PROD = real personal data only when later authorized
PREPROD = sanitized/minimized real-derived data only after explicit future need/authority
PRIVATE PROD FILES = do not leave PROD by default
```

Le dépôt public ne reçoit aucun dump réel, seed personnel réel, export réel
anonymisé/pseudonymisé, document privé, credential ou secret.

DEV/DDEV utilisent des fixtures entièrement synthétiques par défaut.

PREPROD ne reçoit de données dérivées de PROD que si un besoin futur explicite
est démontré et qu'une autorité dédiée définit collecte, isolation,
minimisation/sanitization, assertions, rétention et cleanup applicables.

Une future exception autorisant des données réelles dérivées vers PREPROD ou
DEV doit être explicite, bornée, assertée et revue indépendamment. Cette décision
n'accorde aucune telle exception.

Les fichiers privés PROD ne sont pas synchronisés hors PROD par défaut ; les
environnements inférieurs utilisent des fixtures synthétiques.

### Logging et observabilité

```text
NO sensitive family/document/financial payloads by default
NO credentials
MINIMIZE identifiers
EXPLICIT retention
```

Logging, traces et observabilité doivent respecter classification et rétention.
Un mode de logging riche ne doit jamais être activé avec des payloads sensibles
complets par défaut.

### Secrets

Les secrets et credentials sont hors dépôt et hors fixtures. Leur futur stockage
et injection utiliseront une capacité dédiée et autorisée ; cette décision ne
sélectionne aucune implémentation runtime.

## Consequences

- La classification est obligatoire avant données/intégrations réelles.
- La sortie IA est gouvernée avant invocation provider et échoue en fail closed
  sans policy explicite.
- Le dépôt public et DEV/DDEV restent synthétiques par défaut.
- PREPROD n'obtient aucune route implicite depuis PROD.
- Les fichiers privés ne descendent pas d'environnement par défaut.
- Logging et observabilité minimisent les données et possèdent une rétention
  explicite.
- Les runbooks de sanitization/snapshot restent différés jusqu'à l'existence
  d'environnements et d'un besoin réel.
