<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;

/**
 * Proves one current Upcoming occurrence responsibility can be changed.
 *
 * @group personal_secretary
 */
final class OccurrenceResponsibilityTest extends BrowserTestBase {

  protected static $modules = ['block', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testRescheduledOccurrenceResponsibilityFlow(): void {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $baseProjection */
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\ActivityExceptionService $exceptions */
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparationMutations */
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    /** @var \Drupal\personal_secretary\Service\UpcomingActivityService $upcoming */
    $upcoming = $this->container->get('personal_secretary.upcoming_activity');
    $entityTypeManager = $this->container->get('entity_type.manager');

    $utc = new DateTimeZone('UTC');
    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($utc);
    $nowLocal = $nowUtc->setTimezone($sourceTimezone);
    $firstStartLocal = $nowLocal->modify('+2 days')->setTime(10, 0);
    $firstEndLocal = $firstStartLocal->modify('+1 hour');

    $recurringPerson = $domain->createPerson('Avery Recurring');
    $alternatePerson = $domain->createPerson('Blair Alternate');
    $outsiderPerson = $domain->createPerson('Casey Outsider');
    $household = $domain->createHousehold(
      'Synthetic responsibility household',
      [(int) $recurringPerson->id(), (int) $alternatePerson->id()],
    );
    $series = $domain->createActivitySeries(
      'Synthetic responsibility activity',
      (int) $household->id(),
      $firstStartLocal,
      $firstEndLocal,
      'FREQ=DAILY;COUNT=2',
    );
    $rule = $responsibilityMutations->createResponsibilityRule(
      $series,
      (int) $recurringPerson->id(),
      $firstStartLocal->setTime(9, 0),
      $firstStartLocal->setTime(12, 0),
      'FREQ=DAILY;COUNT=2',
    );
    $preparationMutations->createPreparationRequirement(
      $series,
      'Pack responsibility equipment',
      3600,
      $nowUtc->modify('-1 day'),
    );

    $baseOccurrences = $baseProjection->project(
      $series,
      $firstStartLocal->setTimezone($utc)->modify('-1 minute'),
      $firstStartLocal->modify('+2 days')->setTimezone($utc),
    );
    $this->assertCount(2, $baseOccurrences);
    $target = $baseOccurrences[0];
    $secondTarget = $baseOccurrences[1];

    $rescheduledStartLocal = $firstStartLocal->modify('+30 minutes');
    $rescheduledEndLocal = $firstEndLocal->modify('+30 minutes');
    $exceptions->createReschedule(
      $series,
      $target,
      $rescheduledStartLocal->setTimezone($utc),
      $rescheduledEndLocal->setTimezone($utc),
      'Europe/Brussels',
    );

    $responsibilityUrl = Url::fromRoute(
      'personal_secretary.responsibility_occurrence',
      [
        'series' => (int) $series->id(),
        'original_occurrence_key' => $target->originalOccurrenceKey,
      ],
    )->toString();

    $this->drupalGet($responsibilityUrl);
    $this->assertSession()->statusCodeEquals(403);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($authorized);

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Change responsibility');
    $this->assertSession()->linkByHrefExists($responsibilityUrl);
    $this->assertSession()->pageTextContains('Avery Recurring');
    $this->assertSession()->pageTextContains('Pack responsibility equipment');

    $seriesCount = count($entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple());
    $ruleCount = count($entityTypeManager->getStorage('personal_sec_resp_rule')->loadMultiple());
    $ruleRevisionId = (string) $rule->getRevisionId();
    $rulePersonId = (int) $rule->get('responsible_person')->target_id;
    $ruleRecurrence = $rule->get('recurrence')->first()?->getValue();
    $this->assertNotNull($ruleRecurrence);

    $this->assertCount(0, $entityTypeManager->getStorage('personal_sec_resp_override')->loadMultiple());
    $this->drupalGet($responsibilityUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic responsibility activity');
    $this->assertSession()->pageTextContains('Effective date and time');
    $this->assertSession()->pageTextContains($rescheduledStartLocal->format('Y-m-d H:i'));
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->pageTextContains('Current responsibility');
    $this->assertSession()->pageTextContains('Avery Recurring');
    $this->assertSession()->pageTextContains('Use recurring responsibility');
    $this->assertSession()->pageTextContains('Blair Alternate');
    $this->assertSession()->pageTextContains('No one for this occurrence');
    $this->assertSession()->pageTextNotContains('Casey Outsider');
    $this->assertSession()->fieldValueEquals('responsibility_choice', 'use_recurring');
    $this->assertSession()->buttonExists('Save responsibility');
    $this->assertCount(0, $entityTypeManager->getStorage('personal_sec_resp_override')->loadMultiple());

    $this->submitForm([
      'responsibility_choice' => 'person:' . $alternatePerson->id(),
    ], 'Save responsibility');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);

    $items = $upcoming->aggregate($nowUtc, $nowUtc->modify('+7 days'));
    $targetItem = $this->itemForTargetTime($items, $rescheduledStartLocal->format('Y-m-d H:i'));
    $secondItem = $this->itemForTargetTime(
      $items,
      (new DateTimeImmutable($secondTarget->sourceLocalStart))->format('Y-m-d H:i'),
    );
    $this->assertSame('Blair Alternate', $targetItem['responsibility_label']);
    $this->assertSame('Avery Recurring', $secondItem['responsibility_label']);
    $this->assertNotSame([], $targetItem['preparations']);

    $overrides = array_values($entityTypeManager
      ->getStorage('personal_sec_resp_override')
      ->loadMultiple());
    $this->assertCount(1, $overrides);
    $this->assertInstanceOf(ResponsibilityOverride::class, $overrides[0]);
    $overrideId = (int) $overrides[0]->id();
    $this->assertSame(ResponsibilityOverride::STATUS_ACTIVE, (string) $overrides[0]->get('status')->value);
    $this->assertSame(ResponsibilityOverride::ACTION_ASSIGN_PERSON, (string) $overrides[0]->get('action')->value);
    $this->assertSame((int) $alternatePerson->id(), (int) $overrides[0]->get('responsible_person')->target_id);
    $this->assertSame((int) $series->id(), (int) $overrides[0]->get('series')->target_id);
    $this->assertSame((int) $target->seriesRevisionId, (int) $overrides[0]->get('target_revision_id')->value);
    $this->assertSame($target->originalOccurrenceKey, (string) $overrides[0]->get('original_occurrence_key')->value);

    $this->assertCount($seriesCount, $entityTypeManager->getStorage('personal_sec_activity_series')->loadMultiple());
    $this->assertCount($ruleCount, $entityTypeManager->getStorage('personal_sec_resp_rule')->loadMultiple());
    $currentRule = $entityTypeManager->getStorage('personal_sec_resp_rule')->load($rule->id());
    $this->assertNotNull($currentRule);
    $this->assertSame($ruleRevisionId, (string) $currentRule->getRevisionId());
    $this->assertSame($rulePersonId, (int) $currentRule->get('responsible_person')->target_id);
    $this->assertSame($ruleRecurrence, $currentRule->get('recurrence')->first()?->getValue());

    $this->drupalGet($responsibilityUrl);
    $this->assertSession()->fieldValueEquals(
      'responsibility_choice',
      'person:' . $alternatePerson->id(),
    );
    $this->submitForm([
      'responsibility_choice' => 'use_recurring',
    ], 'Save responsibility');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');

    $currentOverride = $entityTypeManager->getStorage('personal_sec_resp_override')->load($overrideId);
    $this->assertInstanceOf(ResponsibilityOverride::class, $currentOverride);
    $this->assertSame(ResponsibilityOverride::STATUS_WITHDRAWN, (string) $currentOverride->get('status')->value);
    $activeOverrideIds = $entityTypeManager
      ->getStorage('personal_sec_resp_override')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('series', $series->id())
      ->condition('target_revision_id', (int) $target->seriesRevisionId)
      ->condition('original_occurrence_key', $target->originalOccurrenceKey)
      ->condition('status', ResponsibilityOverride::STATUS_ACTIVE)
      ->execute();
    $this->assertSame([], array_values($activeOverrideIds));
    $this->assertCount(1, $entityTypeManager->getStorage('personal_sec_resp_override')->loadMultiple());

    $restoredItems = $upcoming->aggregate($nowUtc, $nowUtc->modify('+7 days'));
    $restoredTarget = $this->itemForTargetTime($restoredItems, $rescheduledStartLocal->format('Y-m-d H:i'));
    $this->assertSame('Avery Recurring', $restoredTarget['responsibility_label']);
    $this->assertNotSame([], $restoredTarget['preparations']);
  }

  /**
   * @param array<int, array<string, mixed>> $items
   *
   * @return array<string, mixed>
   */
  private function itemForTargetTime(array $items, string $effectiveStart): array {
    $matches = array_values(array_filter(
      $items,
      static fn(array $item): bool => $item['effective_start'] === $effectiveStart,
    ));
    $this->assertCount(1, $matches);
    return $matches[0];
  }

}
