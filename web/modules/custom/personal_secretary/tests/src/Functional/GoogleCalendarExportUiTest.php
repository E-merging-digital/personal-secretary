<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Entity\GoogleCalendarProjection;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves manual occurrence actions and French copy without Google credentials.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class GoogleCalendarExportUiTest extends BrowserTestBase {

  protected static $modules = ['block', 'field', 'personal_secretary'];
  protected $defaultTheme = 'olivero';

  public function testOccurrenceStatesAndExplicitConsentBoundary(): void {
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

    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Synthetic calendar member');
    $household = $domain->createHousehold('Synthetic calendar household', [(int) $person->id()]);
    $start = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Brussels')))->modify('+2 days')->setTime(10, 0);
    $end = $start->modify('+1 hour');
    $series = $domain->createActivitySeries('Synthetic calendar activity', (int) $household->id(), $start, $end, 'FREQ=DAILY;COUNT=1');
    $this->container->get('personal_secretary.responsibility_mutation')->createResponsibilityRule($series, (int) $person->id(), $start, $end, 'FREQ=DAILY;COUNT=1');
    $this->container->get('personal_secretary.time_commitment_mutation')->createFullOccurrenceCommitment($series, $start);
    $key = $this->container->get('personal_secretary.occurrence_projection')->project($series, limit: 2)[0]->originalOccurrenceKey;
    $parameters = ['series' => $series->id(), 'original_occurrence_key' => $key];
    $detail = Url::fromRoute('personal_secretary.occurrence_detail', $parameters);
    $formUrl = Url::fromRoute('personal_secretary.google_calendar_export', $parameters);
    $authorize = Url::fromRoute('personal_secretary.google_calendar_authorize_write', $parameters);
    $this->drupalGet($formUrl);
    $this->assertSession()->statusCodeEquals(403);
    $account = $this->drupalCreateUser(['use personal secretary']);
    $account
      ->set(CurrentPersonResolver::FIELD_NAME, ['target_id' => (int) $person->id()])
      ->set(HouseholdAuthorizationService::FIELD_NAME, [['target_id' => (int) $household->id()]])
      ->save();
    $this->drupalLogin($account);
    $this->drupalGet($detail);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Connect Google Calendar');
    $connection = CalendarAccountConnection::create([
      'owner_user' => $account->id(), 'provider_subject_id' => 'synthetic-subject',
      'scopes' => CalendarAccountConnection::connectionScopes(), 'status' => CalendarAccountConnection::STATUS_CONNECTED, 'connected_at' => time(),
    ]);
    $connection->save();
    // A GET without a form submission cannot begin incremental authorization.
    $this->drupalGet($authorize);
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet($detail);
    $this->assertSession()->elementExists('css', '.ps-action-group--occurrence form');
    $this->assertSession()->buttonExists('Authorize and add to Google Calendar');
    $this->assertSession()->pageTextContains('Only its title, time and location are shared.');
    $this->assertSession()->elementExists('css', 'input[name="form_token"]');
    // Invalid CSRF must not establish a consent intent or redirect to Google.
    $this->getSession()->getPage()->find('css', 'input[name="form_token"]')->setValue('synthetic-invalid-csrf');
    $this->getSession()->getPage()->pressButton('Authorize and add to Google Calendar');
    $this->assertSession()->pageTextContains('The form has become outdated.');
    $this->drupalGet($authorize);
    $this->assertSession()->statusCodeEquals(403);

    $connection->set('scopes', CalendarAccountConnection::writeScopes())->save();
    $this->drupalGet($detail);
    $this->assertSession()->buttonExists('Add to Google Calendar');
    $this->container->get('current_user')->setAccount($account);
    $resolved = $this->container->get('personal_secretary.google_calendar_projection_resolver')->resolve((int) $series->id(), $key);
    $builder = $this->container->get('personal_secretary.google_calendar_payload');
    $mapping = $this->container->get('personal_secretary.google_calendar_mapping')->create(
      $series->uuid(), $key, 'synthetic-subject', $builder->eventId($series->uuid(), $key), '"synthetic-etag"', $builder->fingerprint($builder->build($resolved)),
    );
    $this->drupalGet($detail);
    $this->assertSession()->pageTextContains('This occurrence is up to date in Google Calendar.');
    $this->assertSession()->buttonNotExists('Add to Google Calendar');
    $series->set('location', 'Synthetic changed room')->save();
    $this->drupalGet($detail);
    $this->assertSession()->buttonExists('Update Google Calendar');
    foreach ([GoogleCalendarProjection::CONFLICT, GoogleCalendarProjection::REMOTE_MISSING] as $state) {
      $mapping->set('state', $state)->save();
      $this->drupalGet($detail);
      $this->assertSession()->pageTextContains($state === GoogleCalendarProjection::CONFLICT ? 'No overwrite is available.' : 'No recreation is available.');
      $this->assertSession()->buttonNotExists('Update Google Calendar');
      $this->assertSession()->buttonNotExists('Add to Google Calendar');
    }

    if (ConfigurableLanguage::load('fr') === NULL) {
      ConfigurableLanguage::createFromLangcode('fr')->save();
    }
    $this->container->get('module_handler')->loadInclude('personal_secretary', 'install');
    personal_secretary_import_french_catalog();
    $translations = $this->container->get('string_translation');
    $translations->reset();
    $this->assertSame(
      'Autoriser et ajouter à Google Agenda',
      (string) $translations->translate(
        'Authorize and add to Google Calendar',
        [],
        ['langcode' => 'fr'],
      ),
    );
    $this->assertSame(
      'Ajouter à Google Agenda',
      (string) $translations->translate(
        'Add to Google Calendar',
        [],
        ['langcode' => 'fr'],
      ),
    );
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
