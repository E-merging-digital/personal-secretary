<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use DateTimeZone;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Service\DomainMutationService;
use Drupal\personal_secretary\Service\OccurrenceProjectionService;
use InvalidArgumentException;

/**
 * Proves persisted first-domain entities and bounded recurrence integration.
 *
 * @group personal_secretary
 */
final class DomainCoreKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'datetime',
    'datetime_range',
    'date_recur',
    'address',
    'personal_secretary',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('personal_secretary_person');
    $this->installEntitySchema('personal_secretary_household');
    $this->installEntitySchema('personal_sec_activity_series');
    $this->installEntitySchema('personal_sec_activity_exception');
  }

  public function testPersistedDomainGraphAndBoundedOccurrenceProjection(): void {
    $entityTypeManager = $this->container->get('entity_type.manager');
    $this->assertTrue($entityTypeManager->hasDefinition('personal_secretary_person'));
    $this->assertTrue($entityTypeManager->hasDefinition('personal_secretary_household'));
    $this->assertTrue($entityTypeManager->hasDefinition('personal_sec_activity_series'));
    $this->assertTrue($entityTypeManager->hasDefinition('personal_sec_activity_exception'));
    $this->assertFalse($entityTypeManager->hasDefinition('personal_secretary_activity_occurrence'));

    /** @var \Drupal\personal_secretary\Service\DomainMutationService $mutations */
    $mutations = $this->container->get('personal_secretary.domain_mutation');
    $this->assertInstanceOf(DomainMutationService::class, $mutations);

    $personA = $mutations->createPerson('Alex Example');
    $personB = $mutations->createPerson('Riley Example');
    $this->assertNotNull($personA->id());
    $this->assertNotEmpty($personA->uuid());
    $this->assertSame('Alex Example', $entityTypeManager->getStorage('personal_secretary_person')->load($personA->id())->label());
    $this->assertFalse($personA->hasField('uid'));

    $household = $mutations->createHousehold('Example Household', [(int) $personA->id(), (int) $personB->id()]);
    $memberIds = array_map(
      static fn($item): int => (int) $item->target_id,
      iterator_to_array($household->get('members')),
    );
    sort($memberIds);
    $expectedMemberIds = [(int) $personA->id(), (int) $personB->id()];
    sort($expectedMemberIds);
    $this->assertSame($expectedMemberIds, $memberIds);

    $sourceTimezone = new DateTimeZone('Europe/Brussels');
    $localStart = new DateTimeImmutable('2026-03-22 18:00:00', $sourceTimezone);
    $localEnd = new DateTimeImmutable('2026-03-22 18:45:00', $sourceTimezone);
    $series = $mutations->createActivitySeries(
      'Example Weekly Routine',
      (int) $household->id(),
      $localStart,
      $localEnd,
      'FREQ=WEEKLY;INTERVAL=1;COUNT=4',
    );

    $seriesStorage = $entityTypeManager->getStorage('personal_sec_activity_series');
    /** @var \Drupal\personal_secretary\Entity\ActivitySeries $loadedSeries */
    $loadedSeries = $seriesStorage->load($series->id());
    $this->assertInstanceOf(ActivitySeries::class, $loadedSeries);
    $this->assertSame((string) $household->id(), (string) $loadedSeries->get('household')->target_id);
    $this->assertTrue($loadedSeries->getEntityType()->isRevisionable());
    $initialRevisionId = (string) $loadedSeries->getRevisionId();
    $this->assertNotSame('', $initialRevisionId);
    $this->assertSame('2026-03-22T17:00:00', (string) $loadedSeries->get('effective_from')->value);

    $rawRecurrence = $loadedSeries->get('recurrence')->first()->getValue();
    $this->assertSame('FREQ=WEEKLY;INTERVAL=1;COUNT=4', $rawRecurrence['rrule']);
    $this->assertSame('Europe/Brussels', $rawRecurrence['timezone']);

    /** @var \Drupal\personal_secretary\Service\OccurrenceProjectionService $projection */
    $projection = $this->container->get('personal_secretary.occurrence_projection');
    $this->assertInstanceOf(OccurrenceProjectionService::class, $projection);

    /** @var \Drupal\personal_secretary\Entity\ActivitySeries $unsavedSeries */
    $unsavedSeries = $seriesStorage->create([
      'name' => 'Example Unsaved Routine',
      'household' => (int) $household->id(),
      'recurrence' => [[
        'value' => '2026-03-22T17:00:00',
        'end_value' => '2026-03-22T17:45:00',
        'rrule' => 'FREQ=WEEKLY;INTERVAL=1;COUNT=2',
        'timezone' => 'Europe/Brussels',
      ]],
      'effective_from' => '2026-03-22T17:00:00',
    ]);
    $this->assertTrue($unsavedSeries->isNew());
    try {
      $projection->project($unsavedSeries, NULL, NULL, 1);
      $this->fail('Unsaved ActivitySeries projection must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('persisted ActivitySeries', $exception->getMessage());
    }

    $occurrences = $projection->project(
      $loadedSeries,
      new DateTimeImmutable('2026-03-01 00:00:00', $sourceTimezone),
      new DateTimeImmutable('2026-05-01 00:00:00', $sourceTimezone),
      10,
    );
    $this->assertCount(4, $occurrences);
    $this->assertSame('Europe/Brussels', $occurrences[0]->sourceTimezone);
    $this->assertSame($loadedSeries->uuid(), $occurrences[0]->seriesUuid);
    $this->assertSame($initialRevisionId, $occurrences[0]->seriesRevisionId);
    $this->assertMatchesRegularExpression('/^2026-03-22T17:00:00Z$/', $occurrences[0]->originalOccurrenceKey);
    $this->assertStringContainsString('T18:00:00+01:00', $occurrences[0]->sourceLocalStart);
    $this->assertStringContainsString('T17:00:00+00:00', $occurrences[0]->utcStart);
    $this->assertFalse(ctype_digit($occurrences[0]->originalOccurrenceKey));
    $this->assertStringContainsString('T18:00:00+02:00', $occurrences[1]->sourceLocalStart);

    // A location edit is metadata: same revision and exact occurrence keys.
    $exceptions = $this->container->get('personal_secretary.activity_exception');
    $cancelled = $exceptions->createCancel($loadedSeries, $occurrences[2]);
    $cancelId = $cancelled->id();
    $cancelRevision = (string) $cancelled->getRevisionId();
    $cancelKey = (string) $cancelled->get('original_occurrence_key')->value;
    $beforeUuid = $loadedSeries->uuid();
    $beforeEffective = (string) $loadedSeries->get('effective_from')->value;
    $beforeRecurrence = $loadedSeries->get('recurrence')->first()->getValue();
    $updatedLocation = $mutations->updateActivitySeriesLocation($loadedSeries, '  Synthetic Studio  ');
    $this->assertSame('Synthetic Studio', (string) $updatedLocation->get('location')->value);
    $this->assertSame($beforeUuid, $updatedLocation->uuid());
    $this->assertSame($initialRevisionId, (string) $updatedLocation->getRevisionId());
    $this->assertSame($beforeEffective, (string) $updatedLocation->get('effective_from')->value);
    $this->assertSame($beforeRecurrence, $updatedLocation->get('recurrence')->first()->getValue());
    $afterOccurrences = $projection->project(
      $updatedLocation,
      new DateTimeImmutable('2026-03-01 00:00:00', $sourceTimezone),
      new DateTimeImmutable('2026-05-01 00:00:00', $sourceTimezone),
      10,
    );
    $this->assertSame(
      array_map(static fn ($occurrence): string => $occurrence->originalOccurrenceKey, $occurrences),
      array_map(static fn ($occurrence): string => $occurrence->originalOccurrenceKey, $afterOccurrences),
    );
    $cancelAfter = $entityTypeManager->getStorage('personal_sec_activity_exception')->load($cancelId);
    $this->assertSame($cancelRevision, (string) $cancelAfter->getRevisionId());
    $this->assertSame($cancelKey, (string) $cancelAfter->get('original_occurrence_key')->value);
    $this->assertSame('active', (string) $cancelAfter->get('status')->value);
    try {
      $mutations->updateActivitySeriesLocation($loadedSeries, 'Stale input');
      $this->fail('An out-of-date location snapshot must be rejected.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('latest unchanged', $exception->getMessage());
    }
    try {
      $mutations->updateActivitySeriesLocation($updatedLocation, str_repeat('x', 256));
      $this->fail('Locations longer than 255 characters must be rejected.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('255', $exception->getMessage());
    }
    $updatedLocation = $mutations->updateActivitySeriesLocation($updatedLocation, '');
    $this->assertSame('', (string) $updatedLocation->get('location')->value);
    $this->assertSame($initialRevisionId, (string) $updatedLocation->getRevisionId());

    $revised = $mutations->updateActivitySeriesRecurrence(
      $loadedSeries,
      new DateTimeImmutable('2026-03-23 18:00:00', $sourceTimezone),
      new DateTimeImmutable('2026-03-23 18:45:00', $sourceTimezone),
      'FREQ=WEEKLY;INTERVAL=1;COUNT=4;BYDAY=MO',
      new DateTimeImmutable('2026-03-23 00:00:00', new DateTimeZone('UTC')),
    );
    $newRevisionId = (string) $revised->getRevisionId();
    $this->assertNotSame($initialRevisionId, $newRevisionId);
    $revisedOccurrences = $projection->project($revised, NULL, NULL, 2);
    $this->assertCount(2, $revisedOccurrences);
    $this->assertSame($newRevisionId, $revisedOccurrences[0]->seriesRevisionId);

    $infinite = $mutations->createActivitySeries(
      'Example Infinite Routine',
      (int) $household->id(),
      new DateTimeImmutable('2026-01-04 18:00:00', $sourceTimezone),
      new DateTimeImmutable('2026-01-04 18:45:00', $sourceTimezone),
      'FREQ=WEEKLY;INTERVAL=1',
    );

    try {
      $projection->project($infinite);
      $this->fail('Unbounded occurrence projection must fail closed.');
    }
    catch (InvalidArgumentException $exception) {
      $this->assertStringContainsString('explicit bounded window and/or limit', $exception->getMessage());
    }
    $this->assertCount(3, $projection->project($infinite, NULL, NULL, 3));

    $schema = Database::getConnection()->schema();
    $this->assertFalse($schema->tableExists('personal_secretary_activity_occurrence'));

    $anonymous = new AnonymousUserSession();
    $ordinary = new UserSession(['uid' => 2, 'roles' => []]);
    $personAccess = $entityTypeManager->getAccessControlHandler('personal_secretary_person');
    $this->assertFalse($personAccess->createAccess(NULL, $anonymous));
    $this->assertFalse($personAccess->createAccess(NULL, $ordinary));
    $this->assertFalse($personA->access('update', $anonymous));
    $this->assertFalse($personA->access('update', $ordinary));
  }

}
