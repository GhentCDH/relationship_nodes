# relationship_nodes_search

Elasticsearch / Search API integration for `relationship_nodes`.

> **Requires Elasticsearch.** This submodule only works with Search API indexes on an Elasticsearch server through [`elasticsearch_connector`](https://www.drupal.org/project/elasticsearch_connector) (8.0.0-alpha7 or later). It builds Elasticsearch `nested` queries and aggregations, so the Search API database backend, Solr and other backends are not supported.

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

The module replaces three `elasticsearch_connector` services with subclasses:

```yaml
relationship_nodes_search.nested_facet_builder:
  decorates: elasticsearch_connector.facet_builder

relationship_nodes_search.nested_query_filter_builder:
  decorates: elasticsearch_connector.query_filter_builder

relationship_nodes_search.nested_facet_result_parser_es:
  decorates: elasticsearch_connector.facet_result_parser
```

For relationship fields, they build and parse the Elasticsearch `nested` structures; all other fields are handled by the parent classes. They depend on protected methods of `elasticsearch_connector`, so run this module's tests after updating it. Supported: `8.0.0-alpha7` and later.

### Query semantics

- Conditions on one relationship field are combined in one `nested` query: they must match the same relation ("a relation with person X of type Y").
- A single negative condition (not equal, not one of, field is empty) means that **no** relation matches: it is a `must_not` around a `nested` query for the positive condition.
- Nested facets are built as filter > nested > terms, so other active facets filter the indexed items, and each bucket counts items (`reverse_nested`), not relations.

## The `parent:child` field ID convention

Nested relationship fields are identified with a `parent:child` notation throughout the query builder (e.g. `my_relation_field:calculated_related_id`). `NestedQueryStructureBuilder` splits on `:` to construct the ES nested path and child field path.

## Reindexing

`ReindexTargetsOnRelationUpdate` marks nodes for reindexing in the indexes that use the relationship indexer:

- When a relation node is created, updated or deleted: the nodes on both sides, before and after the change.
- When a node's title or published status changes (in any translation): the nodes on the other side of its relations. A deleted node's relations are deleted, which is covered by the first case.
- When a relation type term's name or mirror changes: both sides of the relations typed with it or with its mirror.

Only published relations to published nodes are indexed, in the language of the indexed item.

## Field mapping

`NestedRelationshipMappingSubscriber` handles two `elasticsearch_connector` events:
- `SupportsDataTypeEvent` — marks `relationship_nodes_search_nested_relationship` as a supported data type
- `FieldMappingEvent` — maps that data type to `nested`, with an explicit mapping of the child fields based on their configured Search API types (string: `keyword`, text: `text` with a `keyword` subfield, numbers, dates)

The Search API type of a child field is derived from its Drupal field type when the relationship field is added to the index (dates and date ranges: `date`, with the start date of a range; numbers; text; everything else, including references, as `string`). It is stored in the field's configuration, so changing a Drupal field type later requires removing and re-adding the relationship field.

Elasticsearch cannot change the type of an existing field: after changing child field types, clear the index ("Clear all indexed data"), which recreates it, and reindex.

## Known limitations

- **Autocomplete widget** (to do): the exposed relationship filter offers a text field, a dropdown of indexed values or a year range, but no autocomplete.
- **Uninstalling the module** removes its fields and filters from views and the relationship fields and processor from Search API indexes. Elasticsearch then recreates the affected indexes, so reindex afterwards.

## Dependencies

- `search_api:search_api`
- `elasticsearch_connector:elasticsearch_connector`
- `facets:facets_exposed_filters`
- `better_exposed_filters:better_exposed_filters`
- `relationship_nodes:relationship_nodes`
