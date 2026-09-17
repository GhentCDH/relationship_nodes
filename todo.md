# relationship_nodes — TODO

## Features

- **Nested field display**: `computed_relationshipfield__*` fields show the relation node, but
  displaying fields *of* the relation node (nested field display) is not yet implemented.

- **Enable relation nodes on new content types**: ✓ Fixed. `node_type_add_form` is now included
  in the form alter hook. Root cause: `EntityForm::buildEntity()` clones the entity but only maps
  declared entity properties — third-party settings are not entity properties, so the clone that
  `NodeTypeForm::save()` writes to config never carries them. On the edit form this goes unnoticed
  because the loaded entity already has the saved settings; on the add form the config file is
  created empty and the submit-handler's second `$entity->save()` is not reliable enough.
  Fix: `NodeTypeFormAlter` now registers a `#entity_builders` callback (`copySettingsToEntity`)
  that injects the form values as third-party settings directly onto the entity inside
  `buildEntity()`, before the first save. A secondary fix in `ValidationService` skips
  `validateFormStateFields` when `$entity->id() === NULL` (validation runs before `buildEntity()`
  so a new entity has no ID and no fields to validate) and uses `$entity->id() ?? ''` in
  `displayFormStateValidationErrors` to avoid a PHP 8.1+ TypeError on null ID.

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
