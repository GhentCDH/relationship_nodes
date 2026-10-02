<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\facets\FacetInterface;
use Drupal\facets\FacetSource\SearchApiFacetSourceInterface;
use Drupal\facets\Result\Result;
use Drupal\taxonomy\Entity\Term;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the facets processor that shows mirror labels of relation types.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class SearchFacetsProcessorTest extends SearchKernelTestBase {

  /**
   * Relation type facets show the mirror label; other facets are unchanged.
   */
  public function testMirrorLabels(): void {
    $fields_helper = $this->container->get('search_api.fields_helper');
    foreach (['rn_relation_type' => 'integer', 'title' => 'string'] as $property => $type) {
      $field = $fields_helper->createField($this->index, $property, [
        'datasource_id' => 'entity:node',
        'property_path' => $property,
        'type' => $type,
      ]);
      $this->index->addField($field);
    }
    $this->index->save();

    $parent = $this->createRelationType('parent');
    $child = $this->createRelationType('child');
    $parent = Term::load($parent->id());
    $parent->set('rn_mirror_reference', $child->id())->save();

    $processor = $this->container->get('plugin.manager.facets.processor')->createInstance('translate_entity_mirror_label');
    $build = function (string $field_identifier, array $raw_values) use ($processor): array {
      $source = $this->createMock(SearchApiFacetSourceInterface::class);
      $source->method('getIndex')->willReturn($this->index);
      $facet = $this->createMock(FacetInterface::class);
      $facet->method('getFacetSource')->willReturn($source);
      $facet->method('getFieldIdentifier')->willReturn($field_identifier);
      $results = array_map(fn($raw) => new Result($facet, $raw, 'raw ' . $raw, 1), $raw_values);
      return array_map(fn(Result $result) => $result->getDisplayValue(), $processor->build($facet, $results));
    };

    $this->assertSame(['child', 'parent'], $build('rn_relation_type', [$parent->id(), $child->id()]));
    $this->assertSame(['raw ' . $parent->id()], $build('title', [$parent->id()]));
  }

}
