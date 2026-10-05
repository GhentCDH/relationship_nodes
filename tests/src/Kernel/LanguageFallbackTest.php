<?php

namespace Drupal\Tests\relationship_nodes\Kernel;

use Drupal\Core\Render\RenderContext;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests relations whose related node is not available in a language.
 *
 * @group relationship_nodes
 */
#[Group('relationship_nodes')]
#[RunTestsInSeparateProcesses]
class LanguageFallbackTest extends RelationshipNodesKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'field_ui',
    'filter',
    'text',
    'node',
    'taxonomy',
    'language',
    'entity_events',
    'inline_entity_form',
    'relationship_nodes',
  ];

  /**
   * Relations are left out, or shown in another language with a fallback.
   */
  public function testFallback(): void {
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('nl')->save();
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();

    $ann = $this->createPerson('Ann');
    $ann->addTranslation('nl', ['title' => 'An'])->save();
    $bob = $this->createPerson('Bob');
    $carl = $this->createPerson('Carl');
    $carl->addTranslation('nl', ['title' => 'Karel'])->save();
    $this->createRelation($ann, $bob);
    $this->createRelation($ann, $carl);

    $this->container->get('account_switcher')->switchTo(new AnonymousUserSession());
    $twig = $this->container->get('relationship_nodes.twig_extension');
    $relations = function (array $options) use ($twig, $ann): array {
      $result = $this->container->get('renderer')->executeInRenderContext(
        new RenderContext(),
        fn() => $twig->rn('formatted_relations', $ann, static::COMPUTED_FIELD, $options),
      );
      return array_map(fn($item) => [$item['related_id']['value'], $item['_langcode'], $item['_is_fallback']], $result['items'] ?? []);
    };

    // In English, both relations are available.
    $this->assertSame([['Bob', 'en', FALSE], ['Carl', 'en', FALSE]], $relations(['language' => 'en']));
    // In Dutch, Bob has no translation: left out without fallback ...
    $this->assertSame([['Karel', 'nl', FALSE]], $relations(['language' => 'nl']));
    // ... and shown in English with fallback.
    $with_fallback = $relations(['language' => 'nl', 'language_fallback' => TRUE]);
    $this->assertSame([['Bob', 'en', TRUE], ['Karel', 'nl', FALSE]], $with_fallback);
  }

}
