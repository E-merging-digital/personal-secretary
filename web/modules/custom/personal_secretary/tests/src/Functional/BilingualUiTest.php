<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\block\Entity\Block;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\PersonalTask;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the bilingual product UI while preserving domain and timezone truth.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class BilingualUiTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  protected function setUp(): void {
    parent::setUp();

    if (ConfigurableLanguage::load('fr') === NULL) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }
    require_once DRUPAL_ROOT . '/modules/custom/personal_secretary/personal_secretary.install';
    personal_secretary_import_french_catalog();

    $this->config('system.site')
      ->set('default_langcode', 'fr')
      ->save();
    $this->config('language.negotiation')
      ->set('url.source', 'path_prefix')
      ->set('url.prefixes', ['fr' => 'fr', 'en' => 'en'])
      ->set('url.domains', ['fr' => '', 'en' => ''])
      ->set('selected_langcode', 'site_default')
      ->save();
    $this->config('language.types')
      ->set('negotiation.language_interface.enabled', [
        'language-url' => 0,
        'language-user' => 1,
        'language-selected' => 2,
      ])
      ->save();
    $this->container->get('language_manager')->reset();

    Block::create([
      'id' => 'olivero_language_switcher_test',
      'theme' => 'olivero',
      'region' => 'secondary_menu',
      'weight' => -5,
      'plugin' => 'language_block:language_interface',
      'settings' => [
        'id' => 'language_block:language_interface',
        'label' => 'Languages',
        'label_display' => FALSE,
        'provider' => 'language',
      ],
      'visibility' => [],
    ])->save();

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

  public function testUserPreferenceSwitcherAndProductSurfacesPreserveDomainTruth(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $responsibilities = $this->container->get('personal_secretary.responsibility_mutation');
    $preparations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    $tasks = $this->container->get('personal_secretary.personal_task_mutation');
    $today = $this->container->get('personal_secretary.today');
    $timeline = $this->container->get('personal_secretary.revision_timeline');

    $person = $domain->createPerson('Éva Bilingue');
    $household = $domain->createHousehold('Foyer Démo', [(int) $person->id()]);

    $user = $this->drupalCreateUser([
      HouseholdAuthorizationService::PRODUCT_USE_PERMISSION,
      HouseholdAuthorizationService::ADMIN_PERMISSION,
    ]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $user->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household->id()]]);
    $user->set('timezone', 'Europe/Brussels');
    $user->set('preferred_langcode', 'en');
    $user->save();

    $switcher = $this->container->get('account_switcher');
    $switcher->switchTo($user);
    try {
      $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
        ->setTimezone(new DateTimeZone('UTC'));
      $window = $today->windowFor($user, $nowUtc);

      $todayStart = $nowUtc->modify('-15 minutes')->setTimezone(new DateTimeZone('Europe/Brussels'));
      $todayEnd = $nowUtc->modify('+45 minutes')->setTimezone(new DateTimeZone('Europe/Brussels'));
      $todaySeries = $this->createSeriesWithRule(
        'Activité Aujourd’hui – inchangée',
        (int) $household->id(),
        (int) $person->id(),
        $todayStart,
        $todayEnd,
      );

      $futureStart = $nowUtc->modify('+1 day')->setTimezone(new DateTimeZone('Europe/Brussels'));
      $futureEnd = $futureStart->modify('+1 hour');
      $futureSeries = $this->createSeriesWithRule(
        'Cours de guitare – Éva',
        (int) $household->id(),
        (int) $person->id(),
        $futureStart,
        $futureEnd,
      );
      $dueAt = $nowUtc->modify('+1 hour');
      $preparations->createPreparationRequirement(
        $futureSeries,
        'Préparer le cahier bleu – ne pas traduire',
        $futureStart->getTimestamp() - $dueAt->getTimestamp(),
        $nowUtc->modify('-1 day'),
      );
      $tasks->createTask(
        'Acheter du pain – ne pas traduire',
        (int) $household->id(),
        PersonalTask::DUE_DATE,
        $window['local_date'],
      );

      $target = $timeline->projectBaseWindow(
        $futureSeries,
        $nowUtc,
        $nowUtc->modify('+2 days'),
      )[0];
      $before = [
        'timezone' => (string) $user->getTimezone(),
        'rrule' => (string) $futureSeries->get('recurrence')->first()?->getValue()['rrule'],
        'source_timezone' => (string) $futureSeries->get('recurrence')->first()?->getValue()['timezone'],
        'original_key' => $target->originalOccurrenceKey,
        'utc_start' => $target->utcStart,
        'utc_end' => $target->utcEnd,
        'series_count' => count($this->container->get('entity_type.manager')->getStorage('personal_sec_activity_series')->loadMultiple()),
      ];
    }
    finally {
      $switcher->switchBack();
    }

    $this->drupalLogin($user);

    // Core account storage remains the durable language preference surface.
    $this->drupalGet('/en/user/' . $user->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('preferred_langcode');
    $this->assertSession()->fieldValueEquals('preferred_langcode', 'en');
    $this->submitForm(['preferred_langcode' => 'fr'], 'Save');

    $reloaded = User::load($user->id());
    $this->assertInstanceOf(UserInterface::class, $reloaded);
    $this->assertSame('fr', $reloaded->getPreferredLangcode(FALSE));
    $this->assertSame('Europe/Brussels', (string) $reloaded->getTimezone());

    // Persisted preference drives an unprefixed request; explicit URL wins.
    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Tâches');
    $this->assertSession()->pageTextContains('Préparatifs');
    $this->assertSession()->pageTextContains('Activités');
    $this->assertSession()->pageTextContains('Activité Aujourd’hui – inchangée');
    $this->assertSession()->pageTextContains('Acheter du pain – ne pas traduire');
    $this->assertSession()->linkExists('English');

    $this->assertSurfacePair(
      '/personal-secretary/today',
      ['Tâches', 'Préparatifs', 'Activités', 'Activité Aujourd’hui – inchangée'],
      ['Tasks', 'Preparations', 'Activities', 'Activité Aujourd’hui – inchangée'],
    );
    $this->assertSurfacePair(
      '/personal-secretary/upcoming/mine',
      ['À venir pour moi', 'Cours de guitare – Éva'],
      ['My upcoming', 'Cours de guitare – Éva'],
    );
    $this->assertSurfacePair(
      '/personal-secretary/tasks/mine',
      ['Mes tâches', 'Acheter du pain – ne pas traduire'],
      ['My tasks', 'Acheter du pain – ne pas traduire'],
    );
    $this->assertSurfacePair(
      '/personal-secretary/preparations/mine',
      ['Mes préparatifs', 'Préparer le cahier bleu – ne pas traduire'],
      ['My preparations', 'Préparer le cahier bleu – ne pas traduire'],
    );
    $this->assertSurfacePair(
      '/personal-secretary/setup',
      ['Configurer votre première activité', 'Nom du foyer', 'Nom de la personne responsable'],
      ['Set up your first activity', 'Household name', 'Responsible Person name'],
    );
    $this->drupalGet('/fr/personal-secretary/setup');
    $this->assertSession()->buttonExists('Créer la première activité');
    $this->drupalGet('/en/personal-secretary/setup');
    $this->assertSession()->buttonExists('Create first activity');

    $futureSeriesReloaded = $this->container->get('entity_type.manager')
      ->getStorage('personal_sec_activity_series')
      ->load($futureSeries->id());
    $this->assertInstanceOf(ActivitySeries::class, $futureSeriesReloaded);
    $targetAfter = $timeline->projectBaseWindow(
      $futureSeriesReloaded,
      $nowUtc,
      $nowUtc->modify('+2 days'),
    )[0];
    $reloaded = User::load($user->id());
    $this->assertSame($before['timezone'], (string) $reloaded?->getTimezone());
    $this->assertSame($before['rrule'], (string) $futureSeriesReloaded->get('recurrence')->first()?->getValue()['rrule']);
    $this->assertSame($before['source_timezone'], (string) $futureSeriesReloaded->get('recurrence')->first()?->getValue()['timezone']);
    $this->assertSame($before['original_key'], $targetAfter->originalOccurrenceKey);
    $this->assertSame($before['utc_start'], $targetAfter->utcStart);
    $this->assertSame($before['utc_end'], $targetAfter->utcEnd);
    $this->assertSame(
      $before['series_count'],
      count($this->container->get('entity_type.manager')->getStorage('personal_sec_activity_series')->loadMultiple()),
    );

    // Core switcher is also available on the unauthenticated login surface.
    $this->drupalLogout();
    $this->drupalGet('/fr/user/login');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Se connecter');
    $this->assertSession()->linkByHrefExists('/en/user/login');
    $this->drupalGet('/en/user/login');
    $this->assertSession()->buttonExists('Log in');
    $this->assertSession()->linkByHrefExists('/fr/user/login');
  }

  public function testExistingInstallUpdateReimportsCatalogWithoutDomainMutation(): void {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Existing Install Person');
    $household = $domain->createHousehold('Existing Install Household', [(int) $person->id()]);
    $user = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $user);
    $user->set('timezone', 'Asia/Tokyo');
    $user->save();

    $countsBefore = [
      'person' => count($this->container->get('entity_type.manager')->getStorage('personal_secretary_person')->loadMultiple()),
      'household' => count($this->container->get('entity_type.manager')->getStorage('personal_secretary_household')->loadMultiple()),
    ];

    $sourceId = $this->container->get('database')->select('locales_source', 's')
      ->fields('s', ['lid'])
      ->condition('source', 'Today')
      ->execute()
      ->fetchField();
    $this->assertNotFalse($sourceId);
    $this->container->get('database')->delete('locales_target')
      ->condition('lid', $sourceId)
      ->condition('language', 'fr')
      ->execute();
    $this->assertFalse($this->frenchTranslation('Today'));

    require_once DRUPAL_ROOT . '/modules/custom/personal_secretary/personal_secretary.install';
    $message = personal_secretary_update_11008();
    $this->assertStringContainsString('imported the project French catalog', $message);
    $this->assertSame('Aujourd’hui', $this->frenchTranslation('Today'));

    $userReloaded = User::load($user->id());
    $this->assertSame('Asia/Tokyo', (string) $userReloaded?->getTimezone());
    $this->assertSame($countsBefore, [
      'person' => count($this->container->get('entity_type.manager')->getStorage('personal_secretary_person')->loadMultiple()),
      'household' => count($this->container->get('entity_type.manager')->getStorage('personal_secretary_household')->loadMultiple()),
    ]);

    foreach ([
      'personal_secretary_person',
      'personal_secretary_household',
      'personal_sec_activity_series',
      PersonalTask::ENTITY_TYPE_ID,
    ] as $entityTypeId) {
      $definition = $this->container->get('entity_type.manager')->getDefinition($entityTypeId);
      $this->assertFalse($definition->isTranslatable());
    }
  }

  private function assertSurfacePair(string $path, array $french, array $english): void {
    $this->drupalGet('/fr' . $path);
    $this->assertSession()->statusCodeEquals(200);
    foreach ($french as $text) {
      $this->assertSession()->pageTextContains($text);
    }

    $this->drupalGet('/en' . $path);
    $this->assertSession()->statusCodeEquals(200);
    foreach ($english as $text) {
      $this->assertSession()->pageTextContains($text);
    }
  }

  private function createSeriesWithRule(string $label, int $householdId, int $responsiblePersonId, DateTimeImmutable $start, DateTimeImmutable $end): ActivitySeries {
    $series = $this->container->get('personal_secretary.domain_mutation')
      ->createActivitySeries($label, $householdId, $start, $end, 'FREQ=DAILY;COUNT=1');
    $this->container->get('personal_secretary.responsibility_mutation')
      ->createResponsibilityRule($series, $responsiblePersonId, $start, $end, 'FREQ=DAILY;COUNT=1');
    return $series;
  }

  private function frenchTranslation(string $source): string|false {
    $query = $this->container->get('database')->select('locales_source', 's');
    $query->join('locales_target', 't', 't.lid = s.lid');
    $query->addField('t', 'translation');
    return $query
      ->condition('s.source', $source)
      ->condition('t.language', 'fr')
      ->execute()
      ->fetchField();
  }

  private function installUserReferenceField(string $fieldName, string $targetType, int $cardinality, string $label): void {
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
      'settings' => ['handler' => 'default:' . $targetType, 'handler_settings' => []],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
