# Changelog

All notable changes to `relationship_nodes` and `relationship_nodes_search`. For upgrade steps, see "Upgrading" in `README.md`.

## Unreleased

Changes since 1.0.0-beta2, to be released after manual testing.

Requires Drupal 10.3+ or 11.2+, and, with `relationship_nodes_search`, `elasticsearch_connector` 8.0.0-alpha7 or later. Run `drush updb`: it rebuilds the Elasticsearch indexes that use the relationship indexer.

### Added

- Automatic titles in all translations, updated when a related node is renamed; the title field is hidden in relation forms.
- Fields that the module creates (related entities, relation type, mirror fields) are added to the default form display, with the widgets they need ("Mirror Select Widget" for the relation type). Config imports keep the imported displays.
- Tests: kernel tests for both modules (including an integration test against a real Elasticsearch server) and browser tests for the admin forms, the relation widget and the display from both sides and in another language.
- Continuous integration (GitHub Actions) on Drupal 10.3 and 11: coding standards, PHPStan, the kernel tests with an Elasticsearch server, and the browser tests.

### Changed

- Relations are saved after their parent node, also for new nodes. Saving a node no longer saves new relation nodes put in its computed relationship field; save the relation nodes themselves.
- Users who may view unpublished content also see unpublished relations; anonymous visitors only see published ones, as before.
- A relation field's target cannot be changed while relations use it, and only one relation type may connect the same two content types.
- Config imports also validate changes to relation fields alone, and refuse invalid relation field configuration.
- Search: the child fields of relationship fields have an explicit Elasticsearch mapping (dates and date ranges as `date`, numbers, text with a `keyword` subfield).
- Search: the "Contains" operator is labelled "Is not equal to", which is what it always did; a single "not equal" condition excludes items with a matching relation; only operators that work with a single value are offered.
- Search: nested facets respect the other active facets and count items instead of relations.
- Drupal coding standards, dependency injection in services, and removal of dead code.

### Fixed

- Drupal 11.2+ compatibility (OOP hooks with legacy bridges, plugin attributes); on recent Drupal 11 versions, the "Edit" link of relation fields in "Manage fields" leads to the module's field form.
- Relations respect view access, and lists of relations, also empty ones (in the formatter and in `rn('formatted_relations')`), are invalidated when relations change. The formatter's `summary.total` only counts the relations the viewer may see.
- The relation widget takes the parent node from the form instead of the route; relation changes are kept when the node form is rebuilt without saving the node; the relation table of a new node shows the related node.
- The "Mirror Select Widget" no longer breaks forms of entities other than nodes.
- RN field routes are restricted, and deleting a field asks for confirmation.
- Config schema for the module's settings; relation info is refreshed when relation fields change, also after a config import.
- Module enable/disable detection on config import.
- Deleting a mirror term clears the mirror link on the other term.
- Bundle name clashes and missing-argument crashes.
- Search: unpublished relations and relations to unpublished nodes are not indexed.
- Search: related nodes are reindexed when a related node or a relation type changes.
- Search: relationship fields on indexes with all bundles; translated relation fields; items that are not nodes.
- Search: nested facets and filter dropdowns no longer treat the value `0` (e.g. of a boolean field) as "missing"; filter dropdown options are cached per user when access differs per user (node grants modules, "view own unpublished content").
- Search: PHP warnings on relationship filters; batch loading in views.
- Search: uninstall cleanup during config sync.

### Performance

- Weights, entities and options are loaded in batches.

### Documentation

- Known limitations and upgrade notes in the READMEs.

## 1.0.0-beta2 - 2026-09-17

- Relations can be enabled on the form that creates a content type.
- Config schema for the Views plugins of `relationship_nodes_search`.
- Translatable labels for the year range filter of relationship fields.

## 1.0.0-beta1 - 2026-04-28

First beta release.
