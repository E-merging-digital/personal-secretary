<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\ResponsibilityOverride;
use Drupal\personal_secretary\Entity\ResponsibilityRule;
use Drupal\personal_secretary\Value\EffectiveResponsibility;
use InvalidArgumentException;

/**
 * Proves a future recurring responsible Person can change without series edits.
 *
 * @group personal_secretary
 */
final class EditRecurringResponsibilityTest extends BrowserTestBase {

  protected static $modules = ['block', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testFutureRecurringResponsibilityTransition(): void {
    /** @var \Drupal\personal_secretary\Service\DomainMutationService $domain */
    $domain = $this->container->get('personal_secretary.domain_mutation');
    /** @var \Drupal\personal_secretary\Service\ResponsibilityMutationService $responsibilityMutations */
    $responsibilityMutations = $this->container->get('personal_secretary.responsibility_mutation');
    /** @var \Drupal\personal_secretary\Service\PreparationRequirementMutationService $preparationMutations */
    $preparationMutations = $this->container->get('personal_secretary.preparation_requirement_mutation');
    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $baseProjection */
    $baseProjection = $this->container->get('personal_secretary.occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\EffectiveOccurrenceProjectionService $effectiveProjection */
    $effectiveProjection = $this->container->get('personal_secretary.effective_occurrence_projection');
    /** @var \Drupal\personal_secretary\Service\EffectiveResponsibilityService $effectiveResponsibility */
    $effectiveResponsibility = $this->container->get('personal_secretary.effective_responsibility');
    /** @var \Drupal\personal_secretary\Service\PreparationEligibilityService $preparationEligibility */
    $preparationEligibility = $this->container->get('personal_secretary.preparation_eligibility');
    /** @var \Drupal\personal_secretary\Service\EditRecurringResponsibilityService $editor */
    $editor = $this->container->get('personal_secretary.edit_recurring_responsibility');
    /** @var \Drupal\personal_secretary\Service\EditRecurringScheduleService $scheduleEditor */
    $scheduleEditor = $this->container->get('personal_secretary.edit_recurring_schedule');
    $entityTypeManager = $this->container->get('entity_type.manager');

    $utc = new DateTimeZone('UTC');
    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $nowUtc = (new DateTimeImmutable('@' . $this->container->get('datetime.time')->getCurrentTime()))
      ->setTimezone($utc);
    $nowLocal = $nowUtc->setTimezone($sourceTimezone);
    $seriesStart = $nowLocal->modify('-21 days')->setTime(10, 0);
    $seriesEnd = $seriesStart->modify('+1 hour');

    $personA = $domain->createPerson('Alex Current Recurring');
    $personB = $domain->createPerson('Blair Future Recurring');
    $personC = $domain->createPerson('Casey Override');
    $externalPerson = $domain->createPerson('Drew Outside Household');
    $household = $domain->createHousehold(
      'Synthetic recurring responsibility household',
      [(int) $personA->id()],
    );
    $household = $domain->addHouseholdMember($household, $personB);
    $household = $domain->addHouseholdMember($household, $personC);

    $series = $domain->createActivitySeries(
      'Synthetic recurring responsibility activity',
      (int) $household->id(),
      $seriesStart,
      $seriesEnd,
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $rule = $responsibilityMutations->createResponsibilityRule(
      $series,
      (int) $personA->id(),
      $seriesStart,
      $seriesEnd,
      'FREQ=WEEKLY;INTERVAL=1',
    );
    $requirement = $preparationMutations->createPreparationRequirement(
      $series,
      'Prepare synthetic recurring kit',
      3600,
      $seriesStart,
    );

    $futureWindowEnd = $nowUtc->modify('+35 days');
    $baseOccurrences = $baseProjection->project($series, $nowUtc, $futureWindowEnd);
    $this->assertGreaterThanOrEqual(4, count($baseOccurrences));
    [$preTransitionBase, $firstAffectedBase, $laterBase, $overrideBase] = array_slice($baseOccurrences, 0, 4);

    $preTransitionLocal = (new DateTimeImmutable($preTransitionBase->sourceLocalStart))
      ->setTimezone($sourceTimezone);
    $selectedLocalBoundary = $preTransitionLocal->modify('+1 day')->setTime(0, 0);
    $this->assertGreaterThan($nowLocal->setTime(0, 0), $selectedLocalBoundary);
    $selectedDate = $selectedLocalBoundary->format('Y-m-d');

    $effectiveBefore = $this->effectiveByOriginalKey(
      $effectiveProjection->project($series, $nowUtc, $futureWindowEnd),
    );
    $this->assertArrayHasKey($overrideBase->originalOccurrenceKey, $effectiveBefore);
    $override = $responsibilityMutations->createAssignOverride(
      $series,
      $effectiveBefore[$overrideBase->originalOccurrenceKey],
      (int) $personC->id(),
    );

    $seriesId = (int) $series->id();
    $ruleId = (int) $rule->id();
    $overrideId = (int) $override->id();
    $requirementId = (int) $requirement->id();
    $seriesStorage = $entityTypeManager->getStorage('personal_sec_activity_series');
    $ruleStorage = $entityTypeManager->getStorage('personal_sec_resp_rule');
    $overrideStorage = $entityTypeManager->getStorage('personal_sec_resp_override');
    $preparationStorage = $entityTypeManager->getStorage('personal_sec_prep_req');

    $seriesCountBefore = count($seriesStorage->loadMultiple());
    $seriesRevisionBefore = (string) $series->getRevisionId();
    $seriesRevisionCountBefore = $this->revisionCount($seriesStorage);
    $seriesRecurrenceBefore = $series->get('recurrence')->first()?->getValue();
    $this->assertIsArray($seriesRecurrenceBefore);

    $ruleCountBefore = count($ruleStorage->loadMultiple());
    $ruleRevisionBefore = (string) $rule->getRevisionId();
    $ruleRevisionCountBefore = $this->revisionCount($ruleStorage);
    $overrideCountBefore = count($overrideStorage->loadMultiple());
    $overrideRevisionBefore = (string) $override->getRevisionId();
    $preparationCountBefore = count($preparationStorage->loadMultiple());
    $preparationRevisionBefore = (string) $requirement->getRevisionId();
    $preparationRevisionCountBefore = $this->revisionCount($preparationStorage);

    $editUrl = Url::fromRoute(
      'personal_secretary.edit_recurring_responsibility',
      ['series' => $seriesId],
    )->toString();

    $this->drupalGet($editUrl);
    $this->assertSession()->statusCodeEquals(403);

    $authorized = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($authorized);

    $this->drupalGet('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkExists('Change recurring responsibility');
    $this->assertSession()->linkByHrefExists($editUrl);

    $this->drupalGet($editUrl);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Synthetic recurring responsibility activity');
    $this->assertSession()->pageTextContains('Current recurring responsible Person');
    $this->assertSession()->pageTextContains('Alex Current Recurring');
    $this->assertSession()->pageTextContains('Current weekly schedule');
    $this->assertSession()->pageTextContains('Europe/Brussels');
    $this->assertSession()->fieldExists('effective_from_date');
    $this->assertSession()->fieldExists('responsible_person_id');
    $this->assertSession()->fieldNotExists('source_timezone');
    $this->assertSession()->fieldNotExists('rrule');

    $personSelect = $this->getSession()->getPage()->findField('responsible_person_id');
    $this->assertNotNull($personSelect);
    $this->assertNotNull($personSelect->find('css', 'option[value="' . $personA->id() . '"]'));
    $this->assertNotNull($personSelect->find('css', 'option[value="' . $personB->id() . '"]'));
    $this->assertNotNull($personSelect->find('css', 'option[value="' . $personC->id() . '"]'));
    $this->assertNull($personSelect->find('css', 'option[value="' . $externalPerson->id() . '"]'));

    $this->assertCurrentRuleUnchanged(
      $ruleStorage,
      $ruleId,
      $ruleRevisionBefore,
      $ruleCountBefore,
      $ruleRevisionCountBefore,
    );
    $this->assertSame($seriesRevisionBefore, (string) $seriesStorage->load($seriesId)?->getRevisionId());

    $plan = $editor->prepare($seriesId, $selectedDate, (int) $personB->id());
    $this->assertSame($selectedDate . ' 00:00', $plan['requested_local_boundary']->format('Y-m-d H:i'));
    $this->assertSame(
      $firstAffectedBase->originalOccurrenceKey,
      $plan['first_affected_base_occurrence']->originalOccurrenceKey,
    );
    $this->assertSame(
      (new DateTimeImmutable($firstAffectedBase->utcStart))->getTimestamp(),
      $plan['rule_transition_utc']->getTimestamp(),
    );
    $this->assertCurrentRuleUnchanged(
      $ruleStorage,
      $ruleId,
      $ruleRevisionBefore,
      $ruleCountBefore,
      $ruleRevisionCountBefore,
    );

    $this->submitForm([
      'effective_from_date' => $nowLocal->format('Y-m-d'),
      'responsible_person_id' => (string) $personB->id(),
    ], 'Save recurring responsibility');
    $this->assertSession()->pageTextContains('The recurring responsibility change is not valid');
    $this->assertCurrentRuleUnchanged(
      $ruleStorage,
      $ruleId,
      $ruleRevisionBefore,
      $ruleCountBefore,
      $ruleRevisionCountBefore,
    );

    try {
      $editor->prepare($seriesId, $selectedDate, (int) $externalPerson->id());
      $this->fail('A non-Household Person must fail closed before rule mutation.');
    }
    catch (InvalidArgumentException) {
      // Expected: membership is revalidated server-side before any write.
    }
    $this->assertCurrentRuleUnchanged(
      $ruleStorage,
      $ruleId,
      $ruleRevisionBefore,
      $ruleCountBefore,
      $ruleRevisionCountBefore,
    );

    $this->drupalGet($editUrl);
    $this->submitForm([
      'effective_from_date' => $selectedDate,
      'responsible_person_id' => (string) $personA->id(),
    ], 'Save recurring responsibility');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertCurrentRuleUnchanged(
      $ruleStorage,
      $ruleId,
      $ruleRevisionBefore,
      $ruleCountBefore,
      $ruleRevisionCountBefore,
    );

    $this->drupalGet($editUrl);
    $this->submitForm([
      'effective_from_date' => $selectedDate,
      'responsible_person_id' => (string) $personB->id(),
    ], 'Save recurring responsibility');
    $this->assertSession()->addressEquals('/personal-secretary/upcoming');
    $this->assertSession()->statusCodeEquals(200);

    $seriesStorage->resetCache([$seriesId]);
    $currentSeries = $seriesStorage->load($seriesId);
    $this->assertInstanceOf(ActivitySeries::class, $currentSeries);
    $this->assertSame($seriesId, (int) $currentSeries->id());
    $this->assertCount($seriesCountBefore, $seriesStorage->loadMultiple());
    $this->assertSame($seriesRevisionBefore, (string) $currentSeries->getRevisionId());
    $this->assertSame($seriesRevisionCountBefore, $this->revisionCount($seriesStorage));
    $this->assertSame($seriesRecurrenceBefore, $currentSeries->get('recurrence')->first()?->getValue());

    $ruleStorage->resetCache([$ruleId]);
    $retiredRule = $ruleStorage->load($ruleId);
    $this->assertInstanceOf(ResponsibilityRule::class, $retiredRule);
    $this->assertNotSame($ruleRevisionBefore, (string) $retiredRule->getRevisionId());
    $expectedTransitionStorage = (new DateTimeImmutable($firstAffectedBase->utcStart))
      ->setTimezone($utc)
      ->format('Y-m-d\\TH:i:s');
    $this->assertSame($expectedTransitionStorage, (string) $retiredRule->get('effective_until')->value);
    $historicalRule = $ruleStorage->loadRevision((int) $ruleRevisionBefore);
    $this->assertInstanceOf(ResponsibilityRule::class, $historicalRule);
    $this->assertTrue($historicalRule->get('effective_until')->isEmpty());

    $allRules = array_values($ruleStorage->loadMultiple());
    $this->assertCount($ruleCountBefore + 1, $allRules);
    $this->assertSame($ruleRevisionCountBefore + 2, $this->revisionCount($ruleStorage));
    $currentRules = array_values(array_filter(
      $allRules,
      static fn($candidate): bool => $candidate instanceof ResponsibilityRule
        && (int) $candidate->get('series')->target_id === $seriesId
        && $candidate->get('effective_until')->isEmpty(),
    ));
    $this->assertCount(1, $currentRules);
    $replacementRule = $currentRules[0];
    $this->assertNotSame($ruleId, (int) $replacementRule->id());
    $this->assertSame((int) $personB->id(), (int) $replacementRule->get('responsible_person')->target_id);
    $replacementRecurrence = $replacementRule->get('recurrence')->first()?->getValue();
    $this->assertIsArray($replacementRecurrence);
    $this->assertSame('FREQ=WEEKLY;INTERVAL=1', $replacementRecurrence['rrule']);
    $this->assertSame('Europe/Brussels', $replacementRecurrence['timezone']);
    $this->assertSame(
      (new DateTimeImmutable($firstAffectedBase->utcStart))->setTimezone($utc)->format('Y-m-d\\TH:i:s'),
      $replacementRecurrence['value'],
    );
    $this->assertSame(
      (new DateTimeImmutable($firstAffectedBase->utcEnd))->setTimezone($utc)->format('Y-m-d\\TH:i:s'),
      $replacementRecurrence['end_value'],
    );

    /** @var \Drupal\personal_secretary\Service\CurrentRecurringResponsibilityResolver $sharedResolver */
    $sharedResolver = $this->container->get('personal_secretary.current_recurring_responsibility');
    $resolvedAfter = $sharedResolver->resolve($seriesId);
    $this->assertSame((int) $replacementRule->id(), (int) $resolvedAfter['rule']->id());
    $this->assertSame((int) $personB->id(), (int) $resolvedAfter['responsible_person']->id());
    $scheduleContextAfter = $scheduleEditor->context($seriesId);
    $this->assertSame((int) $personB->id(), (int) $scheduleContextAfter['responsible_person']->id());

    $effectiveAfter = $this->effectiveByOriginalKey(
      $effectiveProjection->project($currentSeries, $nowUtc, $futureWindowEnd),
    );
    foreach (
      [
        $preTransitionBase->originalOccurrenceKey,
        $firstAffectedBase->originalOccurrenceKey,
        $laterBase->originalOccurrenceKey,
        $overrideBase->originalOccurrenceKey,
      ] as $key
    ) {
      $this->assertArrayHasKey($key, $effectiveAfter);
    }

    $beforeResponsibility = $effectiveResponsibility->resolve(
      $currentSeries,
      $effectiveAfter[$preTransitionBase->originalOccurrenceKey],
    );
    $this->assertSame(EffectiveResponsibility::SOURCE_RULE, $beforeResponsibility->source);
    $this->assertSame((int) $personA->id(), $beforeResponsibility->responsiblePersonId);

    $firstResponsibility = $effectiveResponsibility->resolve(
      $currentSeries,
      $effectiveAfter[$firstAffectedBase->originalOccurrenceKey],
    );
    $this->assertSame(EffectiveResponsibility::SOURCE_RULE, $firstResponsibility->source);
    $this->assertSame((int) $personB->id(), $firstResponsibility->responsiblePersonId);

    $laterResponsibility = $effectiveResponsibility->resolve(
      $currentSeries,
      $effectiveAfter[$laterBase->originalOccurrenceKey],
    );
    $this->assertSame(EffectiveResponsibility::SOURCE_RULE, $laterResponsibility->source);
    $this->assertSame((int) $personB->id(), $laterResponsibility->responsiblePersonId);

    $overrideStorage->resetCache([$overrideId]);
    $currentOverride = $overrideStorage->load($overrideId);
    $this->assertInstanceOf(ResponsibilityOverride::class, $currentOverride);
    $this->assertSame($overrideRevisionBefore, (string) $currentOverride->getRevisionId());
    $this->assertSame(ResponsibilityOverride::STATUS_ACTIVE, (string) $currentOverride->get('status')->value);
    $this->assertCount($overrideCountBefore, $overrideStorage->loadMultiple());
    $overrideResponsibility = $effectiveResponsibility->resolve(
      $currentSeries,
      $effectiveAfter[$overrideBase->originalOccurrenceKey],
    );
    $this->assertSame(EffectiveResponsibility::SOURCE_OVERRIDE, $overrideResponsibility->source);
    $this->assertSame((int) $personC->id(), $overrideResponsibility->responsiblePersonId);
    $this->assertSame($overrideId, $overrideResponsibility->overrideId);

    $preparationStorage->resetCache([$requirementId]);
    $currentRequirement = $preparationStorage->load($requirementId);
    $this->assertNotNull($currentRequirement);
    $this->assertSame($preparationRevisionBefore, (string) $currentRequirement->getRevisionId());
    $this->assertCount($preparationCountBefore, $preparationStorage->loadMultiple());
    $this->assertSame($preparationRevisionCountBefore, $this->revisionCount($preparationStorage));
    $preparations = $preparationEligibility->derive(
      $currentSeries,
      $effectiveAfter[$firstAffectedBase->originalOccurrenceKey],
    );
    $this->assertCount(1, $preparations);
    $this->assertSame((int) $personB->id(), $preparations[0]->responsiblePersonId);
    $this->assertSame(
      (new DateTimeImmutable($firstAffectedBase->utcStart))->modify('-1 hour')->getTimestamp(),
      (new DateTimeImmutable($preparations[0]->dueAtUtc))->getTimestamp(),
    );
  }

  private function assertCurrentRuleUnchanged(
    object $storage,
    int $ruleId,
    string $expectedRevision,
    int $expectedEntityCount,
    int $expectedRevisionCount,
  ): void {
    $storage->resetCache([$ruleId]);
    $rule = $storage->load($ruleId);
    $this->assertInstanceOf(ResponsibilityRule::class, $rule);
    $this->assertSame($expectedRevision, (string) $rule->getRevisionId());
    $this->assertTrue($rule->get('effective_until')->isEmpty());
    $this->assertCount($expectedEntityCount, $storage->loadMultiple());
    $this->assertSame($expectedRevisionCount, $this->revisionCount($storage));
  }

  /**
   * @param \Drupal\personal_secretary\Value\EffectiveOccurrence[] $occurrences
   * @return array<string, \Drupal\personal_secretary\Value\EffectiveOccurrence>
   */
  private function effectiveByOriginalKey(array $occurrences): array {
    $result = [];
    foreach ($occurrences as $occurrence) {
      $result[$occurrence->originalOccurrenceKey] = $occurrence;
    }
    return $result;
  }

  private function revisionCount(object $storage): int {
    return count(
      $storage->getQuery()
        ->accessCheck(FALSE)
        ->allRevisions()
        ->execute(),
    );
  }

}
