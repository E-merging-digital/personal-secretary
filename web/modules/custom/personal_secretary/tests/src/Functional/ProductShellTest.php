<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use Drupal\block\Entity\Block;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class ProductShellTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];
  protected $defaultTheme = 'olivero';

  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install(['personal_secretary_product']);
    $this->placeProductBlocks();

    if (ConfigurableLanguage::load('fr') === NULL) ConfigurableLanguage::createFromLangcode('fr')->save();
    require_once DRUPAL_ROOT . '/modules/custom/personal_secretary/personal_secretary.install';
    personal_secretary_import_french_catalog();

    $this->config('system.site')->set('default_langcode', 'fr')->save();
    $this->config('language.negotiation')
      ->set('url.source', 'path_prefix')
      ->set('url.prefixes', ['fr' => 'fr', 'en' => 'en'])
      ->set('url.domains', ['fr' => '', 'en' => ''])
      ->set('selected_langcode', 'site_default')->save();
    $this->config('language.types')->set('negotiation.language_interface.enabled', [
      'language-url' => 0, 'language-user' => 1, 'language-selected' => 2,
    ])->save();
    $this->container->get('language_manager')->reset();

    $this->installUserReferenceField(CurrentPersonResolver::FIELD_NAME, 'personal_secretary_person', 1, 'Personal Secretary person');
    $this->installUserReferenceField(HouseholdAuthorizationService::FIELD_NAME, 'personal_secretary_household', FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED, 'Personal Secretary households');
  }

  public function testProductUserLoginLandsOnTodayAndKeepsProfileExplicit(): void {
    $user = $this->createProductUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);

    $this->drupalGet(\Drupal\Core\Url::fromRoute('user.login'));
    $page = $this->getSession()->getPage();
    $page->fillField('name', $user->getAccountName());
    $page->fillField('pass', $user->passRaw);
    $submit = $this->assertSession()->elementExists('css', '#user-login-form input[name="op"]');
    $submit->press();

    $this->assertSession()->addressMatches('#/personal-secretary/today$#');
    $this->assertSession()->elementExists('css', '[data-personal-secretary-shell]');
    $this->assertSession()->elementExists('css', '[data-product-posture="product-user"]');
    $this->assertSession()->elementExists('css', '[data-ps-menu-toggle][aria-expanded="true"]');
    $this->assertSession()->elementExists('css', '[data-ps-menu][data-open="true"]');
    $this->assertSession()->linkExists('Personal Secretary');
    $this->assertSession()->linkExists('Tasks');
    $this->assertSession()->linkExists('Activities');
    $this->assertSession()->linkExists('Preparations');
    $this->assertSession()->linkExists('Google Calendar');
    $this->assertSession()->linkExists('Settings');
    $this->assertSession()->linkNotExists('Manage Household access');

    $this->drupalGet('/admin');
    $this->assertSession()->statusCodeEquals(403);

    $this->drupalGet('/user/' . $user->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', '[data-personal-secretary-shell]');
  }

  public function testProductEntryDispatchesAnonymousAndNonProductUser(): void {
    $this->drupalGet('/personal-secretary');
    $this->assertSession()->addressMatches('#/user/login$#');

    $user = $this->createProductUser([]);
    $this->drupalLogin($user);
    $this->drupalGet('/personal-secretary');
    $this->assertSession()->addressMatches('#/user/' . $user->id() . '$#');
  }

  public function testOperatorAndSiteAdminKeepDistinctChromeAuthority(): void {
    $operator = $this->createProductUser([
      HouseholdAuthorizationService::PRODUCT_USE_PERMISSION,
      HouseholdAuthorizationService::ADMIN_PERMISSION,
    ]);
    $this->drupalLogin($operator);
    $this->drupalGet('/en/personal-secretary/today');
    $this->assertSession()->elementExists('css', '[data-product-posture="product-operator"]');
    $this->assertSession()->linkExists('Manage Household access');
    $this->drupalGet('/admin');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();

    $admin = $this->createProductUser(
      [],
      NULL,
      TRUE,
    );
    $this->drupalLogin($admin);
    $this->drupalGet('/en/personal-secretary/today');
    $this->assertSession()->elementExists('css', '[data-product-posture="site-admin"]');
    $this->assertSession()->elementExists('css', '[data-personal-secretary-shell]');
    $this->drupalGet('/admin');
    $this->assertSession()->statusCodeEquals(200);
  }

  public function testShellIsBilingualWithoutTranslatingUserContent(): void {
    $user = $this->createProductUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION], 'Jonathan Shell');
    $this->drupalLogin($user);

    $this->drupalGet('/fr/personal-secretary/today');
    $this->assertSession()->statusCodeEquals(200);
    foreach (['Aujourd’hui', 'Tâches', 'Activités', 'Préparatifs', 'Paramètres', 'Jonathan Shell', 'EN'] as $label) {
      $this->assertSession()->linkExists($label);
    }
    $this->assertSession()->pageTextNotContains('Settings');

    $this->drupalGet('/en/personal-secretary/today');
    $this->assertSession()->statusCodeEquals(200);
    foreach (['Today', 'Tasks', 'Activities', 'Preparations', 'Settings', 'Jonathan Shell', 'FR'] as $label) {
      $this->assertSession()->linkExists($label);
    }
    $this->assertSession()->pageTextNotContains('Paramètres');
  }

  private function createProductUser(
    array $permissions,
    ?string $name = NULL,
    bool $siteAdmin = FALSE,
  ): UserInterface {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Shell Person ' . uniqid('', TRUE));
    $household = $domain->createHousehold('Shell Household ' . uniqid('', TRUE), [(int) $person->id()]);
    $user = $this->drupalCreateUser($permissions, $name, $siteAdmin);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household->id()]]);
    $user->set('timezone', 'Europe/Brussels');
    $user->set('preferred_langcode', 'en');
    $user->save();
    return $user;
  }

  private function placeProductBlocks(): void {
    $definitions = [
      'personal_secretary_product_shell' => ['header', -20, 'personal_secretary_product_shell', 'Personal Secretary product shell', 'personal_secretary'],
      'personal_secretary_product_messages' => ['highlighted', -10, 'system_messages_block', 'Status messages', 'system'],
      'personal_secretary_product_page_title' => ['content', -10, 'page_title_block', 'Page title', 'core'],
      'personal_secretary_product_content' => ['content', 0, 'system_main_block', 'Main page content', 'system'],
    ];
    foreach ($definitions as $id => [$region, $weight, $plugin, $label, $provider]) {
      if (Block::load($id) !== NULL) continue;
      Block::create([
        'id' => $id, 'theme' => 'personal_secretary_product', 'region' => $region,
        'weight' => $weight, 'plugin' => $plugin,
        'settings' => ['id' => $plugin, 'label' => $label, 'label_display' => FALSE, 'provider' => $provider],
        'visibility' => [],
      ])->save();
    }
  }

  private function installUserReferenceField(string $fieldName, string $targetType, int $cardinality, string $label): void {
    FieldStorageConfig::create([
      'field_name' => $fieldName, 'entity_type' => 'user', 'type' => 'entity_reference',
      'settings' => ['target_type' => $targetType], 'cardinality' => $cardinality, 'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'field_name' => $fieldName, 'entity_type' => 'user', 'bundle' => 'user', 'label' => $label,
      'required' => FALSE, 'translatable' => FALSE,
      'settings' => ['handler' => 'default:' . $targetType, 'handler_settings' => []],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
