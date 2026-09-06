<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
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

    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
