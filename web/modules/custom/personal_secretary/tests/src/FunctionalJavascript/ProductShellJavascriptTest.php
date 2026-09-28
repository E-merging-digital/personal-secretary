<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\FunctionalJavascript;

use Drupal\block\Entity\Block;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the real-browser behavior of the Personal Secretary product shell.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class ProductShellJavascriptTest extends WebDriverTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  protected function setUp(): void {
    parent::setUp();
    $this->container
      ->get('theme_installer')
      ->install(['personal_secretary_product']);
    $this->placeProductBlocks();

    if (ConfigurableLanguage::load('fr') === NULL) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }

    $this->installUserReferenceField(
      CurrentPersonResolver::FIELD_NAME,
      'personal_secretary_person',
      1,
      'Personal Secretary person',
    );
    $this->installUserReferenceField(
      HouseholdAuthorizationService::FIELD_NAME,
      'personal_secretary_household',
      FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'Personal Secretary households',
    );
  }

  public function testResponsiveProductNavigationBehavior(): void {
    $user = $this->createProductUser();
    $this->drupalLogin($user);

    // Representative mobile viewport.
    $this->getSession()->resizeWindow(390, 844);
    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->elementExists(
      'css',
      '[data-personal-secretary-shell]',
    );
    $this->assertJsCondition(
      "document.querySelector('.ps-product-header')?.dataset.navigationEnhanced === 'true'",
    );

    $assert = $this->assertSession();
    $toggle = $assert->elementExists('css', '[data-ps-menu-toggle]');
    $navigation = $assert->elementExists('css', '[data-ps-menu]');

    // Enhanced navigation starts closed on mobile.
    $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
    $this->assertSame('false', $navigation->getAttribute('data-open'));
    $this->assertFalse($navigation->isVisible());

    // Toggle open then closed, including aria-expanded.
    $toggle->click();
    $this->assertJsCondition(
      "document.querySelector('[data-ps-menu-toggle]').getAttribute('aria-expanded') === 'true'",
    );
    $this->assertSame('true', $navigation->getAttribute('data-open'));
    $this->assertTrue($navigation->isVisible());

    $toggle->click();
    $this->assertJsCondition(
      "document.querySelector('[data-ps-menu-toggle]').getAttribute('aria-expanded') === 'false'",
    );
    $this->assertSame('false', $navigation->getAttribute('data-open'));

    // Escape closes the open menu and returns focus to the toggle.
    $toggle->click();
    $this->assertJsCondition(
      "document.querySelector('[data-ps-menu]').dataset.open === 'true'",
    );
    $toggle->keyPress(27);
    $this->assertJsCondition(
      "document.querySelector('[data-ps-menu]').dataset.open === 'false'",
    );
    $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
    $this->assertTrue(
      (bool) $this->getSession()->evaluateScript(
        "document.activeElement === document.querySelector('[data-ps-menu-toggle]')",
      ),
    );

    // A real navigation-link click closes the menu. Prevent navigation once so
    // this assertion observes the product click handler rather than a reload.
    $toggle->click();
    $this->getSession()->executeScript(
      "document.querySelector('[data-ps-menu] a').addEventListener('click', (event) => event.preventDefault(), { once: true });",
    );
    $assert->elementExists('css', '[data-ps-menu] a')->click();
    $this->assertJsCondition(
      "document.querySelector('[data-ps-menu]').dataset.open === 'false'",
    );
    $this->assertSame('false', $toggle->getAttribute('aria-expanded'));

    // Sticky positioning remains effective after representative scrolling, and
    // navigation can still be opened at the scrolled position.
    $this->assertSame(
      'sticky',
      $this->getSession()->evaluateScript(
        "getComputedStyle(document.querySelector('.ps-app__header')).position",
      ),
    );
    $this->getSession()->executeScript(
      "document.body.style.minHeight = '200vh'; window.scrollTo(0, 500);",
    );
    $this->assertJsCondition('window.scrollY > 0');
    $this->assertJsCondition(
      "Math.abs(document.querySelector('.ps-app__header').getBoundingClientRect().top) < 1",
    );
    $this->assertTrue($toggle->isVisible());
    $toggle->click();
    $this->assertJsCondition(
      "document.querySelector('[data-ps-menu]').dataset.open === 'true'",
    );
    $toggle->click();

    // Representative desktop viewport: normal navigation is visible and the
    // mobile toggle is not presented as the active control.
    $this->getSession()->resizeWindow(1280, 900);
    $this->assertJsCondition('window.innerWidth >= 928');
    $this->assertTrue($navigation->isVisible());
    $this->assertSame(
      'none',
      $this->getSession()->evaluateScript(
        "getComputedStyle(document.querySelector('[data-ps-menu-toggle]')).display",
      ),
    );

    // Product brand/Home routes back to Today.
    $this->drupalGet('/personal-secretary/tasks/mine');
    $brand = $assert->elementExists('css', '.ps-product-header__brand');
    $this->assertStringEndsWith(
      '/personal-secretary/today',
      (string) $brand->getAttribute('href'),
    );
    $brand->click();
    $assert->addressMatches('#/personal-secretary/today$#');
  }

  private function createProductUser(): UserInterface {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Browser Shell Person');
    $household = $domain->createHousehold(
      'Browser Shell Household',
      [(int) $person->id()],
    );

    $user = $this->drupalCreateUser([
      HouseholdAuthorizationService::PRODUCT_USE_PERMISSION,
    ]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set(
      CurrentPersonResolver::FIELD_NAME,
      ['target_id' => (int) $person->id()],
    );
    $user->set(
      HouseholdAuthorizationService::FIELD_NAME,
      [['target_id' => (int) $household->id()]],
    );
    $user->set('timezone', 'Europe/Brussels');
    $user->save();

    return $user;
  }

  private function placeProductBlocks(): void {
    $definitions = [
      'personal_secretary_product_shell' => [
        'header',
        -20,
        'personal_secretary_product_shell',
        'Personal Secretary product shell',
        'personal_secretary',
      ],
      'personal_secretary_product_messages' => [
        'highlighted',
        -10,
        'system_messages_block',
        'Status messages',
        'system',
      ],
      'personal_secretary_product_page_title' => [
        'content',
        -10,
        'page_title_block',
        'Page title',
        'core',
      ],
      'personal_secretary_product_content' => [
        'content',
        0,
        'system_main_block',
        'Main page content',
        'system',
      ],
    ];

    foreach ($definitions as $id => [$region, $weight, $plugin, $label, $provider]) {
      if (Block::load($id) !== NULL) {
        continue;
      }
      Block::create([
        'id' => $id,
        'theme' => 'personal_secretary_product',
        'region' => $region,
        'weight' => $weight,
        'plugin' => $plugin,
        'settings' => [
          'id' => $plugin,
          'label' => $label,
          'label_display' => FALSE,
          'provider' => $provider,
        ],
        'visibility' => [],
      ])->save();
    }
  }

  private function installUserReferenceField(
    string $fieldName,
    string $targetType,
    int $cardinality,
    string $label,
  ): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => ['target_type' => $targetType],
      'cardinality' => $cardinality,
      'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'field_name' => $fieldName,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => $label,
      'required' => FALSE,
      'translatable' => FALSE,
      'settings' => [
        'handler' => 'default:' . $targetType,
        'handler_settings' => [],
      ],
    ])->save();
    $this->container
      ->get('entity_field.manager')
      ->clearCachedFieldDefinitions();
  }

}
