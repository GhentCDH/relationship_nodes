# Changelog

All notable changes to `relationship_nodes` and `relationship_nodes_search`. For upgrade steps, see "Upgrading" in `README.md`.

## Unreleased

### Fixed

- Fields that the module creates (related entities, relation type, mirror fields) are added to the default form display, with the widgets they need ("Mirror Select Widget" for the relation type). Before, they had to be added under "Manage form display" first. Config imports keep the imported displays.
- The "Mirror Select Widget" no longer breaks forms of entities other than nodes.
- In the relation table of a new node's form, the "Related entity" column shows the related node.
- `rn('formatted_relations')` in Twig: an empty result is now invalidated when a relation is added or becomes visible.
- Relation changes are kept when the node form is rebuilt without saving the node.
- The formatter's `summary.total` only counts the relations the viewer may see.
- A relation widget on a new node's form no longer uses the node of an earlier form in the same request.
- If the Inline Entity Form submit handler cannot be replaced, an error is logged.
- Search: nested facets and filter dropdowns no longer treat the value `0` (e.g. of a boolean field) as "missing".
- Search: filter dropdown options are cached per user when access differs per user (node grants modules, "view own unpublished content").

### Added

- Kernel test for language fallback, and continuous integration (GitHub Actions) on Drupal 10.3 and 11, with coding standards and an Elasticsearch server.

### Documentation

- Known limitations: content moderation on relation bundles, relation widgets in nested inline forms, saving relations in code, services replaced in `elasticsearch_connector`, facet settings that nested facets ignore.
- Upgrade notes for the search index rebuild in `drush updb`.

## 1.0.0-beta3 - 2026-10-05

Requires Drupal 10.3+ or 11.2+, and, with `relationship_nodes_search`, `elasticsearch_connector` 8.0.0-alpha7 or later. Run `drush updb`: it rebuilds the Elasticsearch indexes that use the relationship indexer.

### Added

- Automatic titles in all translations, updated when a related node is renamed; the title field is hidden in relation forms.
- Relations can be enabled when a content type or vocabulary is created.
- Kernel tests for both modules, including an integration test against a real Elasticsearch server.

### Changed

- Relations are saved after their parent node, also for new nodes. Saving a node no longer saves new relation nodes put in its computed relationship field.
- Users who may view unpublished content also see unpublished relations; anonymous visitors only see published ones, as before.
- A relation field's target cannot be changed while relations use it, and only one relation type may connect the same two content types.
- Config imports also validate changes to relation fields alone, and refuse invalid relation field configuration.
- Search: the child fields of relationship fields have an explicit Elasticsearch mapping (dates and date ranges as `date`, numbers, text with a `keyword` subfield).
- Search: the "Contains" operator is labelled "Is not equal to", which is what it always did; a single "not equal" condition excludes items with a matching relation; only operators that work with a single value are offered.
- Search: nested facets respect the other active facets and count items instead of relations.
- Drupal coding standards, dependency injection in services, and removal of dead code.

### Fixed

- Drupal 11.2+ compatibility (OOP hooks with legacy bridges, plugin attributes).
- Relations respect view access, and empty relation lists are invalidated.
- The relation widget takes the parent node from the form instead of the route.
- RN field routes are restricted, and deleting a field asks for confirmation.
- Config schema for the module's settings; relation info is refreshed when relation fields change, also after a config import.
- Module enable/disable detection on config import.
- Deleting a mirror term clears the mirror link on the other term.
- Bundle name clashes and missing-argument crashes.
- Search: unpublished relations and relations to unpublished nodes are not indexed.
- Search: related nodes are reindexed when a related node or a relation type changes.
- Search: relationship fields on indexes with all bundles; translated relation fields; items that are not nodes.
- Search: PHP warnings on relationship filters; batch loading in views.
- Search: uninstall cleanup during config sync.

### Performance

- Weights, entities and options are loaded in batches.

## 1.0.0-beta2

Earlier beta release.

## 1.0.0-beta1

First beta release.
