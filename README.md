# relationship_nodes

Core module for managing bidirectional relationships between nodes, with optional typed relation labels and mirror taxonomy support.

## Requirements

- Drupal 10 or higher
- [`entity_events`](https://www.drupal.org/project/entity_events) `^2.0`
- [`inline_entity_form`](https://www.drupal.org/project/inline_entity_form) `^3.0`

### Submodule: relationship_nodes_search

`modules/relationship_nodes_search/` extends the module with Search API / Elasticsearch indexing and Views integration for relation fields. It has additional dependencies — see its own `README.md`.

## Installation

### Via Composer from GitHub (recommended)

Add the repository to your project's `composer.json`:

```json
"repositories": [
  {
    "type": "vcs",
    "url": "https://github.com/GhentCDH/relationship_nodes"
  }
]
```

Then require the module:

```bash
composer require drupal/relationship_nodes:dev-main
```

Enable the module (and optionally the search submodule):

```bash
drush en relationship_nodes
drush en relationship_nodes_search
```

### Manual installation

Clone or copy this repository into `web/modules/custom/relationship_nodes/` and enable via Drush or the Drupal admin UI.

## Purpose

Provides a system where a dedicated "relation node" sits between two content nodes, carrying its own fields (type, extra metadata). Relations are bidirectional — saving or deleting on either side stays consistent automatically. Relation types can optionally carry a mirror label displayed when the relationship is read from the opposite side.

This is **not** Drupal's built-in entity_reference. A relation node is a full content entity that links two other nodes, rather than a simple field reference.

## Architecture overview

The module is organized in three internal layers:

```
RelationBundle/       — configuration & settings (what bundles are relations, and how)
RelationData/         — entity-level operations (query, sync, mirror)
Display/              — render pipeline (build data structures for Twig)
```

Supporting layers:

```
RelationField/        — field name constants, calculated field definitions, field management
Form/                 — admin UI (node type form, vocab form, field config), entity forms, widget
Plugin/               — field widget (RelationIefWidget), formatter (RelationshipFormatter),
                        field type (ReferencingRelationshipItemList), constraints
EventSubscriber/      — four event hooks (title generation, orphan cleanup, mirror sync, config import)
Validation/           — config import validation framework
Twig/                 — Twig extension exposing relationship data to templates
```

### Service map

| Service | Responsibility |
|---------|---------------|
| `relationship_nodes.field_name_resolver` | Single source of truth for all `rn_*` field name constants |
| `relationship_nodes.bundle_settings_manager` | Reads third-party settings from node type / vocab entities |
| `relationship_nodes.bundle_info_service` | `RelationBundleInfo` value objects describing bundle configuration |
| `relationship_nodes.relation_info` | Queries relation nodes: join fields, referencing relations, related entity values |
| `relationship_nodes.relation_sync` | Saves/deletes relation nodes; binds new relations to parent on form submit |
| `relationship_nodes.mirror_provider` | Resolves mirror labels from taxonomy terms |
| `relationship_nodes.mirror_sync` | Keeps mirror fields on vocabulary terms in sync |
| `relationship_nodes.relationship_data_builder` | Builds display data arrays from relation nodes for Twig |
| `relationship_nodes.calculated_field_helper` | Registry of calculated field names shared with `relationship_nodes_search` |
| `relationship_nodes.twig_formatter` | Orchestrates display pipeline; called from the Twig extension |
| `relationship_nodes.twig_extension` | Exposes `relationship_nodes_*` Twig functions to templates |
| `relationship_nodes.validation_service` | Validates bundle/field configuration in the admin UI |
| `relationship_nodes.config_import_validator` | Blocks config imports that would break existing relation data |

## Core concepts

### Relation node
A content node whose bundle is configured as a "relation bundle". It must have two entity reference fields (`rn_related_entity_1`, `rn_related_entity_2`) that point to the two "parent" nodes being linked. These field names are fixed — see `FieldNameResolver`.

### Fixed field names
All relation-related fields use the `rn_` prefix and are defined as constants in `FieldNameResolver`:

| Field | Purpose |
|-------|---------|
| `rn_related_entity_1` | First parent node reference |
| `rn_related_entity_2` | Second parent node reference |
| `rn_relation_type` | Taxonomy term reference for typed relations |
| `rn_mirror_reference` | Entity reference mirror on a vocabulary term |
| `rn_mirror_string` | String mirror on a vocabulary term |

### Typed relations
When `typed_relation` is enabled on a bundle, the relation type vocabulary (`rn_relation_type`) determines the label for the relationship. A different label can be shown from each side of the relationship using the mirror mechanism.

### Mirror labels
A vocabulary term in the relation type vocabulary can carry a mirror field (`rn_mirror_reference` or `rn_mirror_string`). When the relationship is displayed from the perspective of the node on `rn_related_entity_2`, the mirror label is used instead of the term name. This allows asymmetric labels: "employs" shown from the employer side, "is employed by" shown from the employee side.

### Calculated fields
`CalculatedFieldHelper` defines a registry of virtual field names (`calculated_this_id`, `calculated_related_id`, `calculated_related_name`, `calculated_relation_type_name`) that are not stored in the database. Their values are resolved at render time based on the viewing context (which node is being viewed). The same field names are used by `relationship_nodes_search` for Elasticsearch indexing — the field names are shared, but resolution differs: the formatter resolves them from entities; Search API indexes them as nested Elasticsearch fields.

### Auto-title
When `auto_title` is enabled on a bundle, `RelationNodeSubscriber` generates a title for the relation node automatically on presave (`EntityEventType::PRESAVE`).

## Event subscribers

| Subscriber | Event | Action |
|-----------|-------|--------|
| `RelationNodeSubscriber` | `EntityEventType::PRESAVE` | Auto-generates title for relation nodes |
| `TargetNodeSubscriber` | `EntityEventType::DELETE` | Deletes orphaned relation nodes when a parent is deleted |
| `MirrorTermSubscriber` | Entity events | Keeps mirror fields on vocab terms in sync |
| `ConfigImportSubscriber` | Config import events | Validates and cleans up settings during config import |

`TargetNodeSubscriber` only handles DELETE — not saves — so it does not interact with `RelationNodeSubscriber`'s PRESAVE. There is no save-loop risk between the two subscribers.

## Display pipeline

`RelationshipDataBuilder::buildRelationshipData()` is the entry point for rendering:
1. Loads all relation nodes for the viewing node
2. Classifies each by availability (`AVAILABLE`, `LANGUAGE_UNAVAILABLE`, `UNAVAILABLE`) by intersecting published-translation sets of all referenced entities
3. Resolves calculated fields vs real fields for each enabled field config
4. Returns structured arrays with `field_values`, `separator`, and availability metadata

Templates access this via the `relationship_nodes.twig_extension` Twig functions.

## Admin UI

- Node type / vocabulary form: enable/configure a bundle as a relation bundle
- Field config form: configure which fields to display and how
- Locked field list: prevents accidental deletion of `rn_*` fields that have live data

## Known limitations (from `todo.md`)

- Display of nested fields is not yet functional
- Relation nodes can only be enabled on existing content types, not on the node type creation form (same limitation applies to taxonomy vocabulary forms — the option is shown but not stored)
- No constraint prevents a relation node from referencing itself
- Testing and debugging incomplete

## Dependencies

- `drupal:field_ui`
- `entity_events:entity_events`
- `inline_entity_form:inline_entity_form`
