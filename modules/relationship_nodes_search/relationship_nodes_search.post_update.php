<?php

/**
 * @file
 * Post update functions for Relationship Nodes Search.
 */

/**
 * Rebuild Elasticsearch indexes with explicit mapping for relationship fields.
 *
 * The child fields of relationship fields now have an explicit mapping
 * instead of the types Elasticsearch guessed. Elasticsearch cannot change the
 * type of an existing field, so the indexes that use the relationship indexer
 * are recreated and reindexed.
 */
function relationship_nodes_search_post_update_explicit_nested_mapping(array &$sandbox): string {
  $storage = \Drupal::entityTypeManager()->getStorage('search_api_index');

  if (!isset($sandbox['indexes'])) {
    $sandbox['indexes'] = [];
    foreach ($storage->loadMultiple() as $index) {
      if ($index->status()
        && $index->isValidProcessor('relationship_indexer')
        && $index->hasValidServer()
        && $index->getServerInstance()->getBackendId() === 'elasticsearch') {
        // Clearing an Elasticsearch index recreates it with the current mapping
        // and marks all items for reindexing.
        $index->clear();
        $sandbox['indexes'][] = $index->id();
      }
    }
    $sandbox['total'] = 0;
    foreach ($sandbox['indexes'] as $index_id) {
      $sandbox['total'] += $storage->load($index_id)->getTrackerInstance()->getTotalItemsCount();
    }
  }

  // Reindex in batches, so search results are complete after the update.
  $remaining = 0;
  foreach ($sandbox['indexes'] as $index_id) {
    $index = $storage->load($index_id);
    if ($index->getTrackerInstance()->getRemainingItemsCount() > 0) {
      $index->indexItems(100);
    }
    $remaining += $index->getTrackerInstance()->getRemainingItemsCount();
  }

  // Stop when a pass makes no progress (e.g. items that fail to index); the
  // rest is indexed by cron or "drush search-api:index".
  $stalled = isset($sandbox['remaining']) && $remaining >= $sandbox['remaining'];
  $sandbox['remaining'] = $remaining;
  if ($remaining === 0 || $stalled) {
    $sandbox['#finished'] = 1;
  }
  else {
    $sandbox['#finished'] = min(0.99, 1 - $remaining / max(1, $sandbox['total']));
  }

  if ($stalled && $remaining > 0) {
    return (string) t('Rebuilt the search indexes @indexes; @count items could not be indexed now and will be indexed by cron.', [
      '@indexes' => implode(', ', $sandbox['indexes']),
      '@count' => $remaining,
    ]);
  }
  return $sandbox['indexes']
    ? (string) t('Rebuilt the search indexes @indexes with explicit mapping for relationship fields.', ['@indexes' => implode(', ', $sandbox['indexes'])])
    : (string) t('No Elasticsearch indexes use the relationship indexer.');
}
