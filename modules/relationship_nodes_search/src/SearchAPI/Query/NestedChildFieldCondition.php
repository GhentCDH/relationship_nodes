<?php

namespace Drupal\relationship_nodes_search\SearchAPI\Query;

use Drupal\search_api\Query\Condition;


/**
 * Condition for nested child field queries.
 *
 * Extends the standard Condition to add support for Elasticsearch nested
 * queries by tracking the parent and child field names.
 */
class NestedChildFieldCondition extends Condition {

  protected ?string $parentFieldName = NULL;
  protected ?string $childFieldName = NULL;
  

  /**
   * Gets the parent field name.
   *
   * @return string|null
   *   The parent field name, or NULL if not set.
   */
  public function getParentFieldName(): ?string {
    return $this->parentFieldName;
  }


  /**
   * Sets the parent field name.
   *
   * @param string $parentFieldName
   *   The parent field name.
   *
   * @return $this
   */
  public function setParentFieldName(string $parentFieldName): self {
    $this->parentFieldName = $parentFieldName;
    return $this;
  }

  
  /**
   * Gets the child field name.
   *
   * @return string|null
   *   The child field name, or NULL if not set.
   */
  public function getChildFieldName(): ?string {
    return $this->childFieldName;
  }


  /**
   * Sets the child field name.
   *
   * @param string $childFieldName
   *   The child field name.
   *
   * @return $this
   */
  public function setChildFieldName(string $childFieldName): self {
    $this->childFieldName = $childFieldName;
    return $this;
  }


  /**
   * Checks if this is a nested child field condition.
   *
   * @return bool
   *   TRUE if both parent and child field names are set, FALSE otherwise.
   */
  public function isNestedChildField(): bool {
    return !empty($this->parentFieldName) && !empty($this->childFieldName);
  }
}