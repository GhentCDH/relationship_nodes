# relationship_nodes_search

Elasticsearch / Search API integration for `relationship_nodes`.

## Purpose

Enables relationship data (from `relationship_nodes` relation nodes) to be indexed in Elasticsearch and queried via Search API Views, including faceted filtering. Relationship fields are indexed as Elasticsearch `nested` objects, which prevents cross-object query pollution when filtering on multi-value nested documents.

## Architecture overview

```
src/
├── EventSubscriber/
│   ├── NestedRelationshipMappingSubscriber.php   — maps custom data type to ES `nested`
│   └── ReindexTargetsOnRelationUpdate.php        — triggers reindex when a relation changes
├── FieldHelper/
│   └── NestedIndexFieldHelper.php               — maps SAPI field IDs to nested child paths
├── Form/
│   └── ExtendedIndexAddFieldsForm.php           — extends the SAPI "add fields" form
├── Plugin/
│   ├── search_api/
│   │   ├── data_type/NestedRelationshipDataType.php  — custom SAPI data type
│   │   └── processor/RelationshipIndexer.php         — indexes relation data as nested objects
│   ├── facets/processor/
│   │   └── TranslateEntityMirrorProcessor.php        — translates facet values using mirror labels
│   └── views/
│       ├── field/RelationshipField.php               — Views field for relationship data
│       └── filter/RelationshipFilter.php             — Views filter for relationship data
├── QueryHelper/
│   ├── NestedQueryStructureBuilder.php          — builds ES nested aggregations and filters
│   ├── ElasticMappingInspector.php              — inspects live ES mapping for field types
│   ├── NestedFacetResultParser.php              — parses ES nested aggregation results
│   └── FilterOperatorHelper.php                — resolves filter operator labels/values
├── SearchAPI/Query/
│   ├── NestedFacetParamBuilder.php             — decorates elasticsearch_connector facet builder
│   ├── NestedFilterBuilder.php                  — decorates elasticsearch_connector filter builder
│   ├── NestedChildFieldCondition.php
│   ├── NestedChildFieldConditionGroup.php
│   ├── NestedConditionGroupBase.php
│   ├── NestedFacetParamBuilder.php
│   ├── NestedFilterBuilder.php
│   └── NestedParentFieldConditionGroup.php
└── Views/
    ├── Config/
    │   ├── NestedFieldViewsFieldConfigurator.php
    │   ├── NestedFieldViewsFilterConfigurator.php
    │   └── NestedFieldViewsConfiguratorBase.php
    ├── Parser/NestedFieldResultViewsParser.php
    └── Widget/
        ├── NestedExposedFormBuilder.php
        └── NestedFilterDropdownOptionsProvider.php
```

## Why Elasticsearch `nested` type is required

Search API's default indexing flattens nested objects. For a document with two relationships, flattening produces:

```
{ "relation_type": ["employs", "is_member_of"], "related_id": [101, 202] }
```

A query for `relation_type = employs AND related_id = 202` would incorrectly match, because the two values come from different relationships but are merged into the same flat arrays. Using Elasticsearch's `nested` type keeps each relationship as an isolated sub-document, so cross-object matches are prevented.

## Service decoration pattern

`NestedFacetParamBuilder` and `NestedFilterBuilder` **decorate** `elasticsearch_connector`'s built-in services rather than replacing them:

```yaml
relationship_nodes_search.nested_facet_builder:
  decorates: elasticsearch_connector.facet_builder

relationship_nodes_search.nested_query_filter_builder:
  decorates: elasticsearch_connector.query_filter_builder
```

When a field is a `nested_relationship` type, the decorated service intercepts and builds the Elasticsearch `nested` query structure. For all other fields, it delegates to the original service. Replacing the services entirely would break non-nested fields.

## The `parent:child` field ID convention

Nested relationship fields are identified with a `parent:child` notation throughout the query builder (e.g. `my_relation_field:calculated_related_id`). `NestedQueryStructureBuilder` splits on `:` to construct the ES nested path and child field path.

## Reindex on relation update

`ReindexTargetsOnRelationUpdate` fires on `INSERT`, `UPDATE`, and `PREDELETE` of relation nodes. When a relation node changes, both the old and new target nodes must be reindexed in Search API — cache tag invalidation alone is insufficient because Search API uses its own item-tracking queue, not Drupal's cache system.

For UPDATE events, the subscriber reads both `$entity->original` (old state) and the current entity to cover cases where a relation is reassigned from one node to another.

## Field mapping

`NestedRelationshipMappingSubscriber` handles two `elasticsearch_connector` events:
- `SupportsDataTypeEvent` — marks `relationship_nodes_search_nested_relationship` as a supported data type
- `FieldMappingEvent` — maps that data type to `{ "type": "nested" }` in the Elasticsearch index mapping

## Known limitations (from `relationship_nodes/todo.md`)

- Should be moved to a submodule of `relationship_nodes` rather than a sibling module
- Child field types are not yet recognized for mapping before indexing (the mapping inspector inspects the live ES index, not SAPI metadata)
- Autocomplete widget not yet implemented
- Disabling this module mid-production can cause `SearchApiException` errors from `elasticsearch_connector` when it tries to update index settings and finds the custom data type unresolvable

## Dependencies

- `search_api:search_api`
- `elasticsearch_connector:elasticsearch_connector`
- `facets:facets_exposed_filters`
- `better_exposed_filters:better_exposed_filters`
- `relationship_nodes:relationship_nodes`
