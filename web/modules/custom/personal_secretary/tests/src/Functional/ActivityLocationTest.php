<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use InvalidArgumentException;

/**
 * Proves optional ActivitySeries location creation and presentation.
 *
 * @group personal_secretary
 */
final class ActivityLocationTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testLocationCreationAndPresentation(): void {
    $this->installUserPersonFieldViaEntityApi();

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Synthetic location person');
    $household = $domain->createHousehold('Synthetic location household', [(int) $person->id()]);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->assertInstanceOf(UserInterface::class, $authorized);
    $authorized->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $authorized->set('timezone', 'Europe/Brussels');
    $authorized->save();
    $this->drupalLogin($authorized);

    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone(new DateTimeZone('UTC'));
    $nowLocal = $nowUtc->setTimezone($sourceTimezone);

    $this->drupalGet('/personal-secretary/activities/add');
    $this->assertSession()->statusCodeEquals(200);
    $locationField = $this->assertSession()->fieldExists('location');
    $this->assertSame('255', $locationField->getAttribute('maxlength'));
    $this->assertSession()->fieldNotExists('rrule');

    $weeklyStart = $nowLocal->modify('+1 day')->setTime(9, 0);
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'weekly',
      'responsible_person_id' => (string) $person->id(),
      'activity_label' => 'Synthetic location weekly',
      'location' => '  Synthetic Weekly Hall  ',
      'first_occurrence_date' => $weeklyStart->format('Y-m-d'),
      'start_local_time' => '09:00',
      'end_local_time' => '10:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->pageTextContains('Synthetic location weekly');
    $this->assertSession()->pageTextContains('Synthetic Weekly Hall');

    $weekly = $this->seriesByLabel('Synthetic location weekly');
    $this->assertSame('Synthetic Weekly Hall', (string) $weekly->get('location')->value);
    $this->assertRecurrence($weekly, 'FREQ=WEEKLY;INTERVAL=1', 'Europe/Brussels');

    $oneOffStart = $nowLocal->modify('+2 days')->setTime(11, 0);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'one_off',
      'responsible_person_id' => (string) $person->id(),
      'activity_label' => 'Synthetic location one-off',
      'location' => '  Synthetic Clinic  ',
      'first_occurrence_date' => $oneOffStart->format('Y-m-d'),
      'start_local_time' => '11:00',
      'end_local_time' => '12:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->pageTextContains('Synthetic location one-off');
    $this->assertSession()->pageTextContains('Synthetic Clinic');

    $oneOff = $this->seriesByLabel('Synthetic location one-off');
    $this->assertSame('Synthetic Clinic', (string) $oneOff->get('location')->value);
    $this->assertRecurrence($oneOff, 'FREQ=DAILY;COUNT=1', 'Europe/Brussels');

    $emptyStart = $nowLocal->modify('+3 days')->setTime(13, 0);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'one_off',
      'responsible_person_id' => '',
      'activity_label' => 'Synthetic empty location one-off',
      'location' => '   ',
      'first_occurrence_date' => $emptyStart->format('Y-m-d'),
      'start_local_time' => '13:00',
      'end_local_time' => '14:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');
    $empty = $this->seriesByLabel('Synthetic empty location one-off');
    $this->assertSame('', (string) $empty->get('location')->value);
    $this->assertArticleHasNoLocation('Synthetic empty location one-off');

    $todayStart = $nowLocal->setTime(12, 0);
    $this->drupalGet('/personal-secretary/activities/add');
    $this->submitForm([
      'household_id' => (string) $household->id(),
      'activity_type' => 'one_off',
      'responsible_person_id' => (string) $person->id(),
      'activity_label' => 'Synthetic Today location activity',
      'location' => 'Synthetic Today Room',
      'first_occurrence_date' => $todayStart->format('Y-m-d'),
      'start_local_time' => '12:00',
      'end_local_time' => '13:00',
      'source_timezone' => 'Europe/Brussels',
      'preparation_instruction' => '',
      'preparation_lead_minutes' => '0',
    ], 'Add activity');
    $todaySeries = $this->seriesByLabel('Synthetic Today location activity');
    $this->assertSame('Synthetic Today Room', (string) $todaySeries->get('location')->value);
    $this->assertRecurrence($todaySeries, 'FREQ=DAILY;COUNT=1', 'Europe/Brussels');

    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic location one-off');
    $this->assertSession()->pageTextContains('Synthetic Clinic');

    $this->drupalGet('/personal-secretary/today');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic Today location activity');
    $this->assertSession()->pageTextContains('Synthetic Today Room');

    // Location-only edits preserve recurrence and no ordinary product user may edit.
    $weeklyId = (int) $weekly->id();
    $oneOffId = (int) $oneOff->id();
    $weeklyEdit = '/personal-secretary/activities/' . $weeklyId . '/location/edit';
    $oneOffEdit = '/personal-secretary/activities/' . $oneOffId . '/location/edit';
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->linkByHrefExists($weeklyEdit);
    $this->assertSession()->linkByHrefExists($oneOffEdit);
    $this->drupalGet($weeklyEdit);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('location', 'Synthetic Weekly Hall');
    $this->assertSession()->fieldExists('location');
    $this->assertSame('255', $this->assertSession()->fieldExists('location')->getAttribute('maxlength'));
    $this->assertSession()->linkByHrefExists('/personal-secretary/upcoming');
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSame('Synthetic Weekly Hall', (string) $this->seriesByLabel('Synthetic location weekly')->get('location')->value);

    $this->drupalGet('/personal-secretary/upcoming/mine', ['query' => [
      'days' => '30', 'q' => 'Synthetic location', 'focus' => 'tomorrow',
    ]]);
    $context = ['from' => 'mine', 'days' => '30', 'q' => 'Synthetic location', 'focus' => 'tomorrow'];
    $focusedEdit = \Drupal\Core\Url::fromRoute('personal_secretary.edit_activity_location', [
      'series' => $weeklyId,
    ], ['query' => $context]);
    $focusedReturn = \Drupal\Core\Url::fromRoute('personal_secretary.my_upcoming', [], [
      'query' => ['days' => '30', 'q' => 'Synthetic location', 'focus' => 'tomorrow'],
    ]);
    $this->assertSession()->linkByHrefExists($focusedEdit->toString());
    $this->drupalGet($focusedEdit);
    $this->assertSession()->linkByHrefExists($focusedReturn->toString());
    $this->submitForm(['location' => '  Synthetic New Weekly Hall  '], 'Save location');
    $this->assertSession()->addressEquals($focusedReturn);
    $this->assertSame('Synthetic New Weekly Hall', (string) $this->seriesByLabel('Synthetic location weekly')->get('location')->value);
    $this->assertSession()->pageTextContains('Synthetic New Weekly Hall');

    // A and B share the same revision because location is non-revisionable.
    // A stale Form API POST must never silently overwrite an intervening edit.
    $this->drupalGet($weeklyEdit);
    $this->assertSession()->fieldValueEquals('location', 'Synthetic New Weekly Hall');
    $originalRevision = (string) $this->seriesByLabel('Synthetic location weekly')->getRevisionId();
    $intervening = $domain->updateActivitySeriesLocation(
      $this->seriesByLabel('Synthetic location weekly'), 'Synthetic Concurrent Hall',
    );
    $this->assertSame($originalRevision, (string) $intervening->getRevisionId());
    $this->submitForm(['location' => 'Synthetic Stale Hall'], 'Save location');
    $this->assertSession()->pageTextContains('The activity changed while this form was open. Reload before saving.');
    $this->assertSession()->fieldValueEquals('location', 'Synthetic Stale Hall');
    $this->assertSame('Synthetic Concurrent Hall', (string) $this->seriesByLabel('Synthetic location weekly')->get('location')->value);
    $this->assertSame($originalRevision, (string) $this->seriesByLabel('Synthetic location weekly')->getRevisionId());

    // A new GET supplies a fresh trusted snapshot and allows the next edit.
    $this->drupalGet($weeklyEdit);
    $this->assertSession()->fieldValueEquals('location', 'Synthetic Concurrent Hall');
    $this->submitForm(['location' => 'Synthetic New Weekly Hall'], 'Save location');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSame('Synthetic New Weekly Hall', (string) $this->seriesByLabel('Synthetic location weekly')->get('location')->value);

    $this->drupalGet($oneOffEdit);
    $this->submitForm(['location' => ''], 'Save location');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSame('', (string) $this->seriesByLabel('Synthetic location one-off')->get('location')->value);

    // A one-off ALL_DAY occurrence uses exactly the same series-level metadata.
    $day = $nowLocal->modify('+4 days')->setTime(0, 0);
    $allDay = $domain->createActivitySeries(
      'Synthetic all-day location',
      (int) $household->id(),
      $day,
      $day->modify('+1 day'),
      'FREQ=DAILY;COUNT=1',
      'Synthetic All-day Hall',
      [(int) $person->id()],
      ActivitySeries::TIME_MODE_ALL_DAY,
    );
    $allDayEdit = '/personal-secretary/activities/' . $allDay->id() . '/location/edit';
    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->linkByHrefExists($allDayEdit);
    $this->drupalGet($allDayEdit);
    $this->submitForm(['location' => 'Synthetic All-day Studio'], 'Save location');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSame('Synthetic All-day Studio', (string) $this->seriesByLabel('Synthetic all-day location')->get('location')->value);

    // Rename metadata cannot alter recurring targets or revision timeline.
    $storage = $this->container->get('entity_type.manager')->getStorage('personal_sec_activity_series');
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    $effective = $this->container->get('personal_secretary.effective_occurrence_projection');
    $timeline = $this->container->get('personal_secretary.revision_timeline');
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    $windowEnd = $nowUtc->modify('+30 days');
    $weeklyBase = $baseProjection->project($weekly, $nowUtc, $windowEnd);
    $this->assertGreaterThanOrEqual(2, count($weeklyBase));
    $cancel = $exceptions->createCancel($weekly, $weeklyBase[1]);
    $oneOffBase = $baseProjection->project($oneOff, $nowUtc, $windowEnd);
    $this->assertCount(1, $oneOffBase);
    $reschedule = $exceptions->createReschedule(
      $oneOff, $oneOffBase[0],
      (new DateTimeImmutable($oneOffBase[0]->utcStart))->modify('+30 minutes'),
      (new DateTimeImmutable($oneOffBase[0]->utcEnd))->modify('+30 minutes'),
      $oneOffBase[0]->sourceTimezone,
    );
    $preparation = $this->container->get('personal_secretary.preparation_requirement_mutation')
      ->createPreparationRequirement($weekly, 'Synthetic rename preparation', 3600, $nowUtc->modify('-1 day'));

    // The earlier semantic revision must remain historically named.
    $historyStart = $nowLocal->modify('+1 day')->setTime(14, 0);
    $history = $domain->createActivitySeries(
      'Synthetic history series', (int) $household->id(),
      $historyStart, $historyStart->modify('+1 hour'),
      'FREQ=WEEKLY;INTERVAL=1', 'Synthetic History Hall', [(int) $person->id()],
    );
    $firstHistoryRevision = (string) $history->getRevisionId();
    $boundary = $historyStart->modify('+14 days');
    $history = $domain->updateActivitySeriesRecurrence(
      $history, $boundary, $boundary->modify('+1 hour'), 'FREQ=WEEKLY;INTERVAL=1', $boundary,
    );
    $this->assertCount(2, $timeline->timeline($history));

    $contract = function (ActivitySeries $series) use ($storage, $effective, $timeline, $nowUtc, $windowEnd): array {
      $storage->resetCache([(int) $series->id()]);
      $current = $storage->load($series->id());
      $this->assertInstanceOf(ActivitySeries::class, $current);
      return [
        'uuid' => $current->uuid(),
        'revision' => (string) $current->getRevisionId(),
        'revisions' => array_keys($storage->getQuery()->accessCheck(FALSE)
          ->condition('id', $series->id())->allRevisions()->execute()),
        'household' => $current->get('household')->getValue(),
        'persons' => $current->get('concerned_persons')->getValue(),
        'recurrence' => $current->get('recurrence')->getValue(),
        'effective_from' => $current->get('effective_from')->getValue(),
        'location' => $current->get('location')->getValue(),
        'time_mode' => $current->get('time_mode')->getValue(),
        'timeline' => array_map(
          static fn(array $item): array => [
            $item['revision_id'], $item['effective_from']->format(DATE_ATOM),
            $item['effective_until']?->format(DATE_ATOM),
          ], $timeline->timeline($current),
        ),
        'occurrences' => array_map(
          static fn(object $occurrence): array => get_object_vars($occurrence),
          $effective->project($current, $nowUtc, $windowEnd),
        ),
      ];
    };
    $weeklyContract = $contract($weekly);
    $oneOffContract = $contract($oneOff);
    $historyContract = $contract($history);
    $weeklyNameEdit = '/personal-secretary/activities/' . $weeklyId . '/name/edit';
    $oneOffNameEdit = '/personal-secretary/activities/' . $oneOffId . '/name/edit';
    $historyNameEdit = '/personal-secretary/activities/' . $history->id() . '/name/edit';

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->linkByHrefExists($weeklyNameEdit);
    $this->assertSession()->linkByHrefExists($oneOffNameEdit);
    $this->assertSession()->linkByHrefExists($historyNameEdit);
    $this->drupalGet($weeklyNameEdit);
    $this->assertSession()->fieldValueEquals('name', 'Synthetic location weekly');
    $this->assertSame('255', $this->assertSession()->fieldExists('name')->getAttribute('maxlength'));
    $this->clickLink('Cancel');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSame($weeklyContract, $contract($weekly));

    $this->drupalGet($weeklyNameEdit);
    $this->submitForm(['name' => '  Synthetic location weekly  '], 'Save name');
    $this->assertSession()->pageTextContains('Activity name is unchanged.');
    $this->assertSame($weeklyContract, $contract($weekly));

    foreach (['   ', str_repeat('x', 256)] as $invalid) {
      $this->drupalGet($weeklyNameEdit);
      $this->submitForm(['name' => $invalid], 'Save name');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->fieldExists('name');
      $this->assertSame($weeklyContract, $contract($weekly));
    }

    $this->drupalGet($weeklyNameEdit);
    $this->submitForm(['snapshot_id' => str_repeat('0', 32), 'name' => 'Synthetic forged name'], 'Save name');
    $this->assertSession()->pageTextContains('The activity changed while this form was open. Reload before saving.');
    $this->assertSame($weeklyContract, $contract($weekly));

    // An intervening title edit keeps the old GET snapshot stale.
    $this->drupalGet($weeklyNameEdit);
    $storage->resetCache([$weeklyId]);
    $concurrent = $storage->load($weeklyId);
    $this->assertInstanceOf(ActivitySeries::class, $concurrent);
    $concurrent->setNewRevision(FALSE);
    $concurrent->set('name', 'Synthetic concurrent weekly');
    $concurrent->save();
    $this->submitForm(['name' => 'Synthetic stale name'], 'Save name');
    $this->assertSession()->pageTextContains('The activity changed while this form was open. Reload before saving.');
    $this->assertSame($weeklyContract, $contract($weekly));

    $this->drupalGet($weeklyNameEdit);
    $this->assertSession()->fieldValueEquals('name', 'Synthetic concurrent weekly');
    $this->submitForm(['name' => '  Synthetic renamed weekly  '], 'Save name');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->pageTextContains('Synthetic renamed weekly');
    $this->assertSame($weeklyContract, $contract($weekly));

    $focusedName = \Drupal\Core\Url::fromRoute('personal_secretary.rename_activity', [
      'series' => $oneOffId,
    ], ['query' => ['from' => 'mine', 'days' => '30', 'q' => 'Synthetic', 'focus' => 'today']]);
    $focusedReturn = \Drupal\Core\Url::fromRoute('personal_secretary.my_upcoming', [], [
      'query' => ['days' => '30', 'q' => 'Synthetic', 'focus' => 'today'],
    ]);
    $this->drupalGet($focusedName);
    $this->assertSession()->linkByHrefExists($focusedReturn->toString());
    $this->submitForm(['name' => '  Synthetic renamed one-off  '], 'Save name');
    $this->assertSession()->addressEquals($focusedReturn);
    $this->assertSame($oneOffContract, $contract($oneOff));
    $this->drupalGet('/personal-secretary/upcoming/mine', ['query' => ['days' => '30']]);
    $this->assertSession()->pageTextContains('Synthetic renamed one-off');
    $this->assertSession()->pageTextContains('Synthetic renamed weekly');

    $this->drupalGet($historyNameEdit);
    $this->submitForm(['name' => 'Synthetic renamed history'], 'Save name');
    $this->assertSame($historyContract, $contract($history));
    $historic = $storage->loadRevision($firstHistoryRevision);
    $this->assertInstanceOf(ActivitySeries::class, $historic);
    $this->assertSame('Synthetic history series', (string) $historic->label());
    $storage->resetCache([(int) $history->id()]);
    $this->assertSame('Synthetic renamed history', (string) $storage->load($history->id())->label());

    $this->assertSame((int) $cancel->id(), (int) $exceptions->activeForSeries($storage->load($weeklyId))[0]->id());
    $this->assertSame((int) $reschedule->id(), (int) $exceptions->activeForSeries($storage->load($oneOffId))[0]->id());
    $this->assertSame((string) $weeklyContract['revision'], (string) $cancel->get('target_revision_id')->value);
    $this->assertSame((string) $oneOffContract['revision'], (string) $reschedule->get('target_revision_id')->value);
    $this->assertSame($weeklyId, (int) $preparation->get('series')->target_id);
    $this->assertNotNull($this->container->get('entity_type.manager')->getStorage('personal_sec_prep_req')->load($preparation->id()));

    $this->drupalGet($weeklyNameEdit, ['query' => [
      'from' => ['mine'], 'destination' => 'https://example.invalid', 'q' => str_repeat('x', 101),
    ]]);
    $this->assertSession()->linkByHrefExists('/personal-secretary/upcoming');
    $this->drupalGet('/personal-secretary/activities/999999/name/edit');
    $this->assertSession()->statusCodeEquals(404);

    $ordinary = $this->drupalCreateUser([HouseholdAuthorizationService::PRODUCT_USE_PERMISSION]);
    $this->assertInstanceOf(UserInterface::class, $ordinary);
    $ordinary->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()]);
    $ordinary->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household->id()]]);
    $ordinary->set('timezone', 'Europe/Brussels');
    $ordinary->save();
    $this->drupalLogout();
    $this->drupalLogin($ordinary);
    $this->drupalGet('/personal-secretary/upcoming/mine');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic renamed weekly');
    $this->assertSession()->linkByHrefNotExists($weeklyEdit);
    $this->assertSession()->linkByHrefNotExists($weeklyNameEdit);
    $this->drupalGet($weeklyEdit);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($oneOffEdit);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($weeklyNameEdit);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($oneOffNameEdit);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogout();
    $this->drupalLogin($authorized);

    // Reuse the existing bilingual test setup before asserting FR labels.
    if (ConfigurableLanguage::load('fr') === NULL) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }
    require_once DRUPAL_ROOT . '/modules/custom/personal_secretary/personal_secretary.install';
    personal_secretary_import_french_catalog();
    $this->config('system.site')->set('default_langcode', 'fr')->save();
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
      ])->save();
    $this->container->get('language_manager')->reset();

    $this->drupalGet('/fr/personal-secretary/upcoming');
    $this->assertSession()->linkExists('Modifier le nom de l’activité');
    $this->assertSession()->linkExists('Modifier le lieu de l’activité');
    $this->drupalGet('/fr' . $weeklyNameEdit);
    $this->assertSession()->pageTextContains('Nom de l’activité');
    $this->assertSession()->linkExists('Annuler');
    $this->drupalGet('/en' . $weeklyNameEdit);
    $this->assertSession()->pageTextContains('Activity name');
    $this->assertSession()->fieldValueEquals('name', 'Synthetic renamed weekly');
    $this->drupalGet('/fr' . $weeklyEdit);
    $this->assertSession()->fieldExists('location');
    $this->assertSession()->pageTextContains('Laissez vide pour supprimer le lieu');
    $this->assertSession()->linkExists('Annuler');

    // Unknown and forged navigation never becomes a redirect or data write.
    $this->drupalGet('/personal-secretary/activities/999999/location/edit');
    $this->assertSession()->statusCodeEquals(404);
    $this->drupalGet($weeklyEdit, ['query' => ['from' => ['mine'], 'destination' => 'https://example.invalid']]);
    $this->assertSession()->linkByHrefExists('/personal-secretary/upcoming');
    $this->assertSame('Synthetic New Weekly Hall', (string) $this->seriesByLabel('Synthetic renamed weekly')->get('location')->value);

    $tooLongStart = $nowLocal->modify('+4 days')->setTime(15, 0);
    $this->expectException(InvalidArgumentException::class);
    $this->expectExceptionMessage('Activity location must not exceed 255 characters.');
    $domain->createActivitySeries(
      'Synthetic too-long location',
      (int) $household->id(),
      $tooLongStart,
      $tooLongStart->modify('+1 hour'),
      'FREQ=DAILY;COUNT=1',
      str_repeat('x', 256),
    );
  }

  private function seriesByLabel(string $label): ActivitySeries {
    foreach ($this->container->get('entity_type.manager')->getStorage('personal_sec_activity_series')->loadMultiple() as $series) {
      if ($series instanceof ActivitySeries && $series->label() === $label) {
        return $series;
      }
    }
    throw new \RuntimeException(sprintf('ActivitySeries %s was not found.', $label));
  }

  private function assertRecurrence(ActivitySeries $series, string $rrule, string $timezone): void {
    $item = $series->get('recurrence')->first();
    $this->assertNotNull($item);
    $value = $item->getValue();
    $this->assertSame($rrule, (string) ($value['rrule'] ?? ''));
    $this->assertSame($timezone, (string) ($value['timezone'] ?? ''));
  }

  private function assertArticleHasNoLocation(string $activityLabel): void {
    foreach ($this->getSession()->getPage()->findAll('css', 'article.personal-secretary-upcoming-activity') as $article) {
      $heading = $article->find('css', 'h2');
      if ($heading !== NULL && trim($heading->getText()) === $activityLabel) {
        $this->assertStringNotContainsString('Location', $article->getText());
        return;
      }
    }
    $this->fail(sprintf('Upcoming article %s was not found.', $activityLabel));
  }

  private function installUserPersonFieldViaEntityApi(): void {
    FieldStorageConfig::create([
      'field_name' => CurrentPersonResolver::FIELD_NAME,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => [
        'target_type' => 'personal_secretary_person',
      ],
      'cardinality' => 1,
      'translatable' => FALSE,
    ])->save();

    FieldConfig::create([
      'field_name' => CurrentPersonResolver::FIELD_NAME,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Personal Secretary person',
      'required' => FALSE,
      'translatable' => FALSE,
      'settings' => [
        'handler' => 'default:personal_secretary_person',
        'handler_settings' => [],
      ],
    ])->save();

    FieldStorageConfig::create([
      'field_name' => HouseholdAuthorizationService::FIELD_NAME,
      'entity_type' => 'user',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'personal_secretary_household'],
      'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      'translatable' => FALSE,
    ])->save();
    FieldConfig::create([
      'field_name' => HouseholdAuthorizationService::FIELD_NAME,
      'entity_type' => 'user',
      'bundle' => 'user',
      'label' => 'Personal Secretary Households',
      'required' => FALSE,
      'translatable' => FALSE,
      'settings' => [
        'handler' => 'default:personal_secretary_household',
        'handler_settings' => [],
      ],
    ])->save();
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
