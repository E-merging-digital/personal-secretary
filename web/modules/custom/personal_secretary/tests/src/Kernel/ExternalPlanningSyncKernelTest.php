<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\UserSession;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Entity\CalendarSyncState;
use Drupal\personal_secretary\Entity\ExternalEventShadow;
use Drupal\personal_secretary\Entity\GoogleCalendarProjection;
use Drupal\personal_secretary\Service\ExternalPlanningQueryService;
use Drupal\personal_secretary\Service\ExternalPlanningRepository;
use Drupal\personal_secretary\Service\GoogleCalendarIngestionTransport;
use Drupal\personal_secretary\Service\GoogleCalendarPlanningSyncService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves bounded external Google planning ingestion.
 *
 * Uses synthetic provider doubles only.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class ExternalPlanningSyncKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'datetime',
    'datetime_range',
    'date_recur',
    'key',
    'oauth2_client',
    'easy_encryption',
    'personal_secretary',
  ];

  /**
   * Synthetic authenticated user ID.
   */
  private int $uid;

  /**
   * Synthetic connected Google calendar account.
   */
  private CalendarAccountConnection $connection;

  /**
   * Captured synthetic HTTP requests.
   *
   * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
   */
  private array $requests = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema(CalendarAccountConnection::ENTITY_TYPE_ID);
    $this->installEntitySchema(GoogleCalendarProjection::ENTITY_TYPE_ID);
    $this->installEntitySchema(ExternalEventShadow::ENTITY_TYPE_ID);
    $this->installEntitySchema(CalendarSyncState::ENTITY_TYPE_ID);

    $user = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'external-planning-user',
      'status' => 1,
      'timezone' => 'Europe/Brussels',
    ]);
    $user->save();
    $this->uid = (int) $user->id();
    $this->container->get('current_user')->setAccount(new UserSession([
      'uid' => $this->uid,
      'name' => 'external-planning-user',
      'roles' => ['authenticated'],
    ]));

    $this->connection = CalendarAccountConnection::create([
      'owner_user' => $this->uid,
      'provider_key' => CalendarAccountConnection::PROVIDER_GOOGLE,
      'provider_subject_id' => 'synthetic-google-subject',
      'scopes' => array_map(
        static fn(string $scope): array => ['value' => $scope],
        CalendarAccountConnection::writeScopes(),
      ),
      'status' => CalendarAccountConnection::STATUS_CONNECTED,
      'connected_at' => 1893456000,
    ]);
    $this->connection->save();
  }

  /**
   * Proves bootstrap, pagination, exact identity, de-duplication and refresh.
   */
  public function testBootstrapPaginationIdentityDedupAndIncrementalLifecycle(): void {
    $linkedEventId = 'ps' . str_repeat('a', 64);
    $this->createLinkedMapping($linkedEventId);

    $now = 1893456000;
    $sync = $this->syncWithResponses($now, [
      $this->jsonResponse([
        'items' => [
          $this->timedEvent('external-timed-1', 'External appointment'),
        ],
        'nextPageToken' => 'page-2',
      ]),
      $this->jsonResponse([
        'items' => [
          $this->allDayEvent('external-all-day-1', 'External all day'),
          $this->timedEvent($linkedEventId, 'Linked native occurrence'),
        ],
        'nextSyncToken' => 'sync-1',
      ]),
    ]);

    $result = $sync->refreshCurrentUser();
    $this->assertSame('bootstrap', $result['mode']);
    $this->assertSame(3, $result['provider_items']);
    $this->assertSame(2, $result['created']);
    $this->assertSame(1, $result['linked_deduplicated']);
    $this->assertCount(2, $this->activeShadows());

    $state = $this->syncState();
    $this->assertSame('sync-1', (string) $state->get('sync_token')->value);
    $this->assertSame(CalendarSyncState::CURRENT, (string) $state->get('status')->value);
    $this->assertSame('bootstrap', (string) $state->get('last_mode')->value);
    $this->assertSame(3, (int) $state->get('provider_item_count')->value);
    $this->assertSame(1, (int) $state->get('linked_deduplicated_count')->value);

    $this->assertCount(2, $this->requests);
    $firstQuery = $this->requests[0][2]['query'];
    $secondQuery = $this->requests[1][2]['query'];
    $this->assertSame('true', $firstQuery['singleEvents']);
    $this->assertSame('true', $firstQuery['showDeleted']);
    $this->assertSame('default', $firstQuery['eventTypes']);
    $this->assertArrayHasKey('timeMin', $firstQuery);
    $this->assertArrayHasKey('timeMax', $firstQuery);
    $this->assertArrayNotHasKey('syncToken', $firstQuery);
    $this->assertSame('page-2', $secondQuery['pageToken']);
    $this->assertStringNotContainsString('description', $firstQuery['fields']);
    $this->assertStringNotContainsString('attendees', $firstQuery['fields']);
    $this->assertFalse($this->requests[0][2]['allow_redirects']);

    $shadowStorage = $this->container->get('entity_type.manager')
      ->getStorage(ExternalEventShadow::ENTITY_TYPE_ID);
    $allDay = $this->shadowByEventId('external-all-day-1');
    $this->assertSame(ExternalEventShadow::TIME_MODE_ALL_DAY, $allDay->get('time_mode')->value);
    $this->assertSame('2030-01-01', $allDay->get('all_day_start')->value);
    $this->assertSame('2030-01-03', $allDay->get('all_day_end')->value);
    foreach ([
      'description',
      'attendees',
      'organizer',
      'creator',
      'conference_data',
      'reminders',
      'attachments',
      'extended_properties',
    ] as $forbidden) {
      $this->assertFalse($allDay->hasField($forbidden));
    }

    $this->requests = [];
    $sync = $this->syncWithResponses($now + 60, [
      $this->jsonResponse([
        'items' => [
          $this->timedEvent('external-timed-1', 'External appointment updated'),
        ],
        'nextSyncToken' => 'sync-2',
      ]),
    ]);
    $result = $sync->refreshCurrentUser();
    $this->assertSame('incremental', $result['mode']);
    $this->assertSame(0, $result['created']);
    $this->assertSame(1, $result['updated']);
    $this->assertCount(2, $this->activeShadows());
    $this->assertSame(
      'External appointment updated',
      $this->shadowByEventId('external-timed-1')->get('title')->value,
    );
    $this->assertSame('sync-1', $this->requests[0][2]['query']['syncToken']);
    $this->assertArrayNotHasKey('timeMin', $this->requests[0][2]['query']);
    $this->assertSame('sync-2', (string) $this->syncState()->get('sync_token')->value);

    $this->requests = [];
    $sync = $this->syncWithResponses($now + 120, [
      $this->jsonResponse([
        'items' => [
          [
            'id' => 'external-timed-1',
            'status' => 'cancelled',
          ],
        ],
        'nextSyncToken' => 'sync-3',
      ]),
    ]);
    $result = $sync->refreshCurrentUser();
    $this->assertSame(1, $result['deleted']);
    $cancelled = $this->shadowByEventId('external-timed-1');
    $this->assertFalse((bool) $cancelled->get('active')->value);
    $this->assertSame('cancelled', (string) $cancelled->get('provider_status')->value);
    $this->assertSame('', (string) $cancelled->get('title')->value);
    $this->assertSame('', (string) $cancelled->get('location')->value);
    $this->assertTrue($cancelled->get('timed_start')->isEmpty());
    $this->assertTrue($cancelled->get('timed_end')->isEmpty());
    $this->assertCount(1, $this->activeShadows());

    $query = new ExternalPlanningQueryService(
      $this->container->get('personal_secretary.google_calendar_connection'),
      new ExternalPlanningRepository(
        $this->container->get('entity_type.manager'),
        $this->container->get('current_user'),
      ),
      $this->time($now + 120),
    );
    $localStart = new \DateTimeImmutable('2030-01-01T00:00:00+01:00');
    $localEnd = $localStart->modify('+1 day');
    $today = $query->today(
      $localStart->setTimezone(new \DateTimeZone('UTC')),
      $localEnd->setTimezone(new \DateTimeZone('UTC')),
      '2030-01-01',
      'Europe/Brussels',
    );
    $this->assertCount(1, $today);
    $this->assertTrue($today[0]['all_day']);
    $this->assertSame('2030-01-01', $today[0]['all_day_start_date']);
    $this->assertSame('2030-01-02', $today[0]['all_day_end_date']);
    $this->assertSame('Google Calendar', $today[0]['source_label']);
    $this->assertSame('EXTERNAL_READ_ONLY', $today[0]['editability']);

    $this->assertCount(2, $shadowStorage->loadMultiple());
  }

  /**
   * Proves an incomplete bootstrap publishes no partial state or cursor.
   */
  public function testIncompleteBootstrapPublishesNothingAndKeepsCursorEmpty(): void {
    $now = 1893456000;
    $sync = $this->syncWithResponses($now, [
      $this->jsonResponse([
        'items' => [$this->timedEvent('partial-event', 'Must not publish')],
        'nextPageToken' => 'page-2',
      ]),
      new Response(503),
    ]);

    try {
      $sync->refreshCurrentUser();
      $this->fail('Incomplete bootstrap must fail closed.');
    }
    catch (\RuntimeException) {
      $this->addToAssertionCount(1);
    }

    $this->assertCount(0, $this->activeShadows());
    $state = $this->syncState();
    $this->assertSame('', (string) $state->get('sync_token')->value);
    $this->assertSame(CalendarSyncState::ERROR, (string) $state->get('status')->value);
    $this->assertSame(0, (int) ($state->get('last_success_at')->value ?? 0));
  }

  /**
   * Proves a failed HTTP 410 rebase preserves last-known-good until recovery.
   */
  public function testHttp410FailedRebasePreservesLastKnownGoodThenCanRecover(): void {
    $now = 1893456000;
    $this->syncWithResponses($now, [
      $this->jsonResponse([
        'items' => [$this->timedEvent('stable-event', 'Stable before 410')],
        'nextSyncToken' => 'stable-sync',
      ]),
    ])->refreshCurrentUser();

    $this->requests = [];
    $sync = $this->syncWithResponses($now + 60, [
      new Response(410),
      new Response(503),
    ]);

    try {
      $sync->refreshCurrentUser();
      $this->fail('Failed 410 rebase must remain fail closed.');
    }
    catch (\RuntimeException) {
      $this->addToAssertionCount(1);
    }

    $shadow = $this->shadowByEventId('stable-event');
    $this->assertTrue((bool) $shadow->get('active')->value);
    $this->assertSame('Stable before 410', $shadow->get('title')->value);
    $state = $this->syncState();
    $this->assertSame('stable-sync', (string) $state->get('sync_token')->value);
    $this->assertSame(CalendarSyncState::REBASE_REQUIRED, (string) $state->get('status')->value);
    $this->assertSame('stable-sync', $this->requests[0][2]['query']['syncToken']);
    $this->assertArrayHasKey('timeMin', $this->requests[1][2]['query']);

    $this->requests = [];
    $result = $this->syncWithResponses($now + 120, [
      $this->jsonResponse([
        'items' => [$this->timedEvent('stable-event', 'Stable after rebase')],
        'nextSyncToken' => 'rebased-sync',
      ]),
    ])->refreshCurrentUser();

    $this->assertSame('bootstrap', $result['mode']);
    $this->assertSame(
      'Stable after rebase',
      $this->shadowByEventId('stable-event')->get('title')->value,
    );
    $this->assertSame('rebased-sync', (string) $this->syncState()->get('sync_token')->value);
    $this->assertSame(CalendarSyncState::CURRENT, (string) $this->syncState()->get('status')->value);
  }

  /**
   * Builds a sync service backed by synthetic provider responses.
   *
   * @param int $now
   *   Fixed current timestamp.
   * @param \GuzzleHttp\Psr7\Response[] $responses
   *   Ordered synthetic Google responses.
   *
   * @return \Drupal\personal_secretary\Service\GoogleCalendarPlanningSyncService
   *   Sync service wired to the synthetic transport.
   */
  private function syncWithResponses(int $now, array $responses): GoogleCalendarPlanningSyncService {
    $oauth = $this->createMock(Oauth2ClientServiceInterface::class);
    $oauth->method('getAccessToken')->willReturn(new AccessToken([
      'access_token' => 'synthetic-planning-access',
      'expires' => time() + 3600,
    ]));

    $http = $this->createMock(ClientInterface::class);
    $http->method('request')->willReturnCallback(
      function (string $method, string $uri, array $options) use (&$responses): Response {
        $this->requests[] = [$method, $uri, $options];
        $this->assertSame('GET', $method);
        $this->assertSame(
          'https://www.googleapis.com/calendar/v3/calendars/primary/events',
          $uri,
        );
        $this->assertFalse($options['allow_redirects']);
        $response = array_shift($responses);
        if (!$response instanceof Response) {
          throw new \RuntimeException('Synthetic response queue is empty.');
        }
        return $response;
      },
    );

    $repository = new ExternalPlanningRepository(
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
    );
    return new GoogleCalendarPlanningSyncService(
      $this->container->get('database'),
      $this->time($now),
      $this->container->get('personal_secretary.google_calendar_connection'),
      $repository,
      new GoogleCalendarIngestionTransport($oauth, $http),
    );
  }

  /**
   * Creates a fixed clock double.
   *
   * @param int $now
   *   Fixed current timestamp.
   *
   * @return \Drupal\Component\Datetime\TimeInterface
   *   Synthetic clock.
   */
  private function time(int $now): TimeInterface {
    $time = $this->createMock(TimeInterface::class);
    $time->method('getCurrentTime')->willReturn($now);
    return $time;
  }

  /**
   * Creates one synthetic JSON provider response.
   *
   * @param array<string, mixed> $payload
   *   Response payload.
   *
   * @return \GuzzleHttp\Psr7\Response
   *   Synthetic HTTP 200 response.
   */
  private function jsonResponse(array $payload): Response {
    return new Response(
      200,
      ['Content-Type' => 'application/json'],
      json_encode($payload, JSON_THROW_ON_ERROR),
    );
  }

  /**
   * Builds a synthetic timed provider event.
   *
   * @param string $id
   *   Provider event ID.
   * @param string $summary
   *   Provider event summary.
   *
   * @return array<string, mixed>
   *   Synthetic event payload.
   */
  private function timedEvent(string $id, string $summary): array {
    return [
      'id' => $id,
      'etag' => '"etag-' . substr(hash('sha256', $id . $summary), 0, 16) . '"',
      'status' => 'confirmed',
      'summary' => $summary,
      'description' => 'must-not-persist',
      'attendees' => [['email' => 'must-not-persist@example.test']],
      'start' => [
        'dateTime' => '2030-01-01T10:00:00+01:00',
        'timeZone' => 'Europe/Brussels',
      ],
      'end' => [
        'dateTime' => '2030-01-01T11:00:00+01:00',
        'timeZone' => 'Europe/Brussels',
      ],
      'location' => 'Synthetic room',
      'transparency' => 'opaque',
      'updated' => '2029-12-31T18:00:00Z',
    ];
  }

  /**
   * Builds a synthetic all-day provider event.
   *
   * @param string $id
   *   Provider event ID.
   * @param string $summary
   *   Provider event summary.
   *
   * @return array<string, mixed>
   *   Synthetic event payload.
   */
  private function allDayEvent(string $id, string $summary): array {
    return [
      'id' => $id,
      'etag' => '"etag-all-day"',
      'status' => 'confirmed',
      'summary' => $summary,
      'start' => ['date' => '2030-01-01'],
      'end' => ['date' => '2030-01-03'],
      'transparency' => 'transparent',
      'updated' => '2029-12-31T19:00:00Z',
    ];
  }

  /**
   * Creates one synthetic native-linked Google mapping.
   *
   * @param string $eventId
   *   Exact provider event ID.
   */
  private function createLinkedMapping(string $eventId): void {
    GoogleCalendarProjection::create([
      'owner_user' => $this->uid,
      'provider' => 'google',
      'target' => 'primary',
      'series_uuid' => 'synthetic-linked-series',
      'original_occurrence_key' => '2030-01-01T09:00:00Z',
      'provider_subject_id' => 'synthetic-google-subject',
      'event_id' => $eventId,
      'etag' => '"linked-etag"',
      'provider_link' => 'https://calendar.google.com/calendar/event?eid=synthetic-linked',
      'payload_fingerprint' => hash('sha256', 'linked-payload'),
      'state' => GoogleCalendarProjection::ACTIVE,
    ])->save();
  }

  /**
   * Returns active external shadows.
   *
   * @return \Drupal\personal_secretary\Entity\ExternalEventShadow[]
   *   Active synthetic shadows.
   */
  private function activeShadows(): array {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage(ExternalEventShadow::ENTITY_TYPE_ID);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('active', TRUE)
      ->execute();
    $storage->resetCache($ids);
    return array_values($storage->loadMultiple($ids));
  }

  /**
   * Loads one exact synthetic external shadow.
   *
   * @param string $eventId
   *   Exact provider event ID.
   *
   * @return \Drupal\personal_secretary\Entity\ExternalEventShadow
   *   Matching external shadow.
   */
  private function shadowByEventId(string $eventId): ExternalEventShadow {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage(ExternalEventShadow::ENTITY_TYPE_ID);
    $ids = array_values($storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('provider_event_id', $eventId)
      ->execute());
    $this->assertCount(1, $ids);
    $storage->resetCache($ids);
    $shadow = $storage->load($ids[0]);
    $this->assertInstanceOf(ExternalEventShadow::class, $shadow);
    return $shadow;
  }

  /**
   * Loads the single synthetic calendar sync state.
   *
   * @return \Drupal\personal_secretary\Entity\CalendarSyncState
   *   Current synthetic sync state.
   */
  private function syncState(): CalendarSyncState {
    $storage = $this->container->get('entity_type.manager')
      ->getStorage(CalendarSyncState::ENTITY_TYPE_ID);
    $ids = array_values($storage->getQuery()->accessCheck(FALSE)->execute());
    $this->assertCount(1, $ids);
    $storage->resetCache($ids);
    $state = $storage->load($ids[0]);
    $this->assertInstanceOf(CalendarSyncState::class, $state);
    return $state;
  }

}
