# relationship_nodes — TODO

## Features

- **Nested field display**: `computed_relationshipfield__*` fields show the relation node, but
  displaying fields *of* the relation node (nested field display) is not yet implemented.

- **Enable relation nodes on new content types**: `node_type_add_form` is excluded from the
  form alter hook. The root cause of why `handleSubmission` does not persist settings on the
  add form is not yet identified — requires debugging.

- **Auto-title defaults**: when auto_title is enabled, the title field should be hidden in the
  relation node's form display (it gets overwritten on save anyway). Needs careful handling of
  the disable path to restore it — both paths must be covered before implementing.


## Refactoring

- **`BundleInfoService`** mixes live-site query methods with CIM (config-import) query methods.
  Consider splitting or at minimum grouping and commenting the two sets of methods clearly.

- **`RelationInlineEntityForm::getTableFields()`**: custom override is a near-copy of the parent.
  Verify whether the override is still necessary and simplify or document why it diverges.


## relationship_nodes_search

- **Field type recognition**: child fields from relation nodes need to be mapped to their correct
  Search API data types before indexing.

- **Autocomplete widget**: the search-filter autocomplete widget is not yet implemented.
