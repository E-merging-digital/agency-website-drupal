# Project Lead — Clarification avant specification

## But

Agency distingue explicitement l'intention utilisateur de la solution et de
l'implementation.

Le flux par defaut est :

```text
USER INTENT
-> classification de clarification
-> decisions suffisantes
-> specification
-> tickets
-> Delivery / implementation
-> validation
```

Le but n'est pas d'ajouter du process. Le but est d'eviter :

```text
USER INTENT
-> hypotheses implicites
-> architecture prematuree
-> tickets
-> code
-> corrections tardives
```

Le Project Lead reste responsable du besoin, des decisions et du cadrage.
Delivery reste responsable de l'execution dans ce cadre.

## Classification proportionnelle

### L0 — execution directe

Utiliser L0 lorsque le resultat attendu est clair et que les choix restants ne
peuvent pas changer materiellement la solution.

Exemples :
- bug reproductible et compris ;
- correction de texte ;
- correction CSS locale ;
- maintenance standard ;
- tache mecanique ;
- criteres d'acceptation deja explicites.

Regle :

```text
L0 = NO_GRILLING
```

Ne pas fabriquer une spec lourde pour justifier une tache simple.

### L1 — clarification ciblee

Utiliser L1 lorsque quelques decisions peuvent encore changer le comportement,
le scope ou le choix de solution.

Poser seulement les questions qui changent reellement la solution. Arreter des
que ces decisions sont suffisamment stables.

### L2 — clarification structuree

Utiliser L2 lorsqu'il existe plusieurs decisions produit, UX, architecture ou
integration encore ouvertes, ou lorsqu'un choix peut creer une contrainte
durable.

Cas typiques :
- nouvelle fonctionnalite ;
- nouveau workflow metier ;
- changement UX important ;
- integration externe ;
- decision architecturale ;
- plusieurs solutions raisonnables ;
- criteres de reussite ambigus ;
- dette ou contrainte durable probable.

L2 n'implique pas un questionnaire fixe. La clarification est progressive.

## Pendant la clarification

Le Project Lead :

1. distingue les **faits**, les **hypotheses** et les **decisions** ;
2. pose les questions une par une ou par petit groupe coherent ;
3. evite les questions ceremoniales ;
4. explique une decision structurante lorsque cela aide l'utilisateur ;
5. peut recommander une option, mais laisse la decision produit finale a
   l'utilisateur ;
6. detecte lorsqu'une question demande plutot une recherche ou un prototype ;
7. arrete lorsque les decisions suffisantes sont prises.

```text
MORE_QUESTIONS_POSSIBLE != MORE_QUESTIONS_REQUIRED
```

## Recherche et wayfinder

Une recherche est justifiee lorsqu'elle peut changer la decision, par exemple :
- verifier Drupal Core ou un module contrib existant ;
- verifier une API ou documentation officielle ;
- comparer plusieurs integrations ;
- inspecter les patterns deja presents dans Agency ;
- verifier une capacite Drupal/Canvas/AI actuelle.

Quand le chemin lui-meme est inconnu, faire une exploration courte de type
`wayfinder` :

```text
EXPLORE OPTIONS
-> IDENTIFY MATERIAL TRADE-OFFS
-> DECIDE
```

Ne pas transformer une hypothese en decision sans cette exploration lorsqu'elle
est necessaire.

## Sortie de clarification

Avant la specification, capturer seulement ce qui est utile :

- probleme a resoudre ;
- utilisateur concerne ;
- resultat attendu ;
- comportements attendus ;
- decisions prises ;
- contraintes ;
- cas limites significatifs ;
- criteres de reussite ;
- hors-perimetre explicite.

Le **quoi** et le **pourquoi** precedent le **comment**.

Ne pas figer prematurement :
- classes ;
- services ;
- plugins Drupal ;
- schemas techniques detailles ;
- structure exacte du code ;

sauf lorsqu'ils constituent eux-memes une decision architecturale.

## Frontiere clarification -> specification

Une spec peut etre produite lorsque :

```text
STRUCTURAL_DECISIONS = SUFFICIENTLY_STABLE
SUCCESS_CRITERIA = UNDERSTOOD
MATERIAL_UNKNOWNS = RESOLVED_OR_EXPLICITLY_DEFERRED
OUT_OF_SCOPE = EXPLICIT
```

Il n'est pas necessaire d'eliminer toute incertitude.

## Frontiere specification -> tickets

Les tickets decoulent de la spec. Ils peuvent preciser l'execution, mais ne
doivent pas redefinir silencieusement :
- le probleme ;
- le workflow produit ;
- les criteres de reussite ;
- une decision UX/architecture structurante.

Si Delivery rencontre une ambiguite qui pourrait changer l'une de ces choses :

```text
STOP
-> RETURN PROJECT LEAD
-> DECISION
-> CONTINUE
```

Une ambiguite purement locale d'implementation reste du ressort de Delivery
lorsque le cadre et les criteres de reussite ne changent pas.

## Stockage durable des decisions

Utiliser les mecanismes Agency existants :
- issue GitHub ;
- commentaire durable ;
- documentation projet ;
- ADR uniquement pour une vraie decision architecturale durable.

Ne pas creer de nouveau glossary, registre, framework de workflow ou famille de
documents par defaut.

Une decision merite une trace durable lorsqu'elle :
- influence des developpements futurs ;
- cree une contrainte architecturale ;
- fixe un comportement metier ;
- explique un choix important entre plusieurs options.

Les details temporaires de conversation ne sont pas tous a conserver.

## Anti-sur-ingenierie

```text
NO_MANDATORY_GRILLING_FOR_EVERY_TASK = REQUIRED
NO_FIXED_GIANT_QUESTIONNAIRE = REQUIRED
NO_HEAVY_SPEC_FOR_TRIVIAL_CHANGE = REQUIRED
NO_ADR_FOR_LOCAL_DECISION = REQUIRED
NO_DOCUMENT_MULTIPLICATION = REQUIRED
NO_PREMATURE_ABSTRACTION = REQUIRED
NO_NEW_WORKFLOW_FRAMEWORK_WITHOUT_PROVEN_GAP = REQUIRED
```

La methode doit permettre :
- d'aller **plus vite** sur L0 ;
- de poser quelques bonnes questions sur L1 ;
- de prendre de meilleures decisions avant code sur L2.

Elle reste soumise a :
- `DOD_FIRST` ;
- `MINIMUM_NECESSARY` ;
- `USE_EXISTING_FIRST`.

## Trace minimale dans les issues

Une issue technique doit pouvoir indiquer en une ligne son origine :

```text
CLARIFICATION =
L0 / direct
```

ou :

```text
CLARIFICATION =
L1 / <issue, commentaire ou spec d'autorite>
```

ou :

```text
CLARIFICATION =
L2 / <issue, commentaire ou spec d'autorite>
```

Cette trace n'est pas un nouveau gate CI et ne doit pas devenir une checklist
administrative.

## Premier pilote

Ne pas reprocesser le backlog.

Le premier pilote est la prochaine fonctionnalite UX/UI Agency materiellement
ambigue sous Epic #3. Appliquer :

```text
USER REQUEST
-> CLARIFICATION_LEVEL
-> QUESTIONS/RESEARCH NECESSAIRES
-> DECISIONS
-> SPEC
-> TICKETS
```

Puis comparer si la methode a reduit les hypotheses, le rework et le processus
total.

## Regle finale

```text
SIMPLE + CLEAR = EXECUTE
AMBIGUOUS + MATERIAL = CLARIFY
UNKNOWN PATH = RESEARCH
SUFFICIENT DECISIONS = SPECIFY
STRUCTURAL AMBIGUITY IN DELIVERY = ESCALATE
DOD PROVEN = STOP
```
