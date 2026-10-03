<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\oauth2_client\Service\Oauth2ClientServiceInterface;
use Drupal\personal_secretary\Controller\GoogleCalendarController;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\CalendarAccountConnection;
use Drupal\personal_secretary\Entity\GoogleCalendarProjection;
use Drupal\personal_secretary\OAuth2\GoogleCalendarProvider;
use Drupal\personal_secretary\Plugin\Oauth2Client\GoogleCalendar;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\GoogleCalendarEventTransport;
use Drupal\personal_secretary\Service\GoogleCalendarExportService;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Synthetic manual Google export: domain authority, OAuth and HTTP contracts.
 *
 * @group personal_secretary
 */
#[RunTestsInSeparateProcesses]
final class GoogleCalendarExportKernelTest extends KernelTestBase {

  protected static $modules = ['system', 'user', 'field', 'datetime', 'datetime_range', 'date_recur', 'key', 'oauth2_client', 'easy_encryption', 'personal_secretary'];

  private User $account;
  private array $requests = [];

  protected function setUp(): void {
    parent::setUp();
    foreach (['user', 'personal_secretary_person', 'personal_secretary_household', 'personal_sec_activity_series', 'personal_sec_activity_exception', 'personal_sec_resp_rule', 'personal_sec_resp_override', 'personal_sec_time_commit', CalendarAccountConnection::ENTITY_TYPE_ID, GoogleCalendarProjection::ENTITY_TYPE_ID] as $type) {
      $this->installEntitySchema($type);
    }
    $this->installConfig(['system', 'user']);
    Role::create(['id' => 'calendar_user', 'label' => 'Synthetic calendar user'])
      ->grantPermission('use personal secretary')->save();
    foreach ([CurrentPersonResolver::FIELD_NAME => 'personal_secretary_person', HouseholdAuthorizationService::FIELD_NAME => 'personal_secretary_household'] as $name => $target) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'user', 'type' => 'entity_reference', 'cardinality' => -1, 'settings' => ['target_type' => $target]])->save();
      FieldConfig::create(['field_name' => $name, 'entity_type' => 'user', 'bundle' => 'user', 'label' => $name])->save();
    }
    // Reserve uid 1 so isolation/authorization tests do not receive superuser access.
    User::create(['name' => 'synthetic-admin-reserved', 'status' => 1])->save();
    $this->account = User::create(['name' => 'synthetic-export-user', 'status' => 1, 'roles' => ['calendar_user']]);
    $this->account->save();
    $this->container->get('current_user')->setAccount($this->account);
    $this->container->get('router.builder')->rebuild();
  }

  public function testScopesAndIncrementalProviderAreExact(): void {
    $connections = $this->container->get('personal_secretary.google_calendar_connection');
    $connections->connect('synthetic-subject', CalendarAccountConnection::connectionScopes());
    $this->assertFalse($connections->hasWriteGrant());
    try {
      $connections->connect('synthetic-subject', CalendarAccountConnection::writeScopes());
      $this->fail('Base connection must not grant write access.');
    }
    catch (\InvalidArgumentException) {
      $this->assertFalse($connections->hasWriteGrant());
    }
    foreach ([['other-subject', CalendarAccountConnection::writeScopes()], ['synthetic-subject', CalendarAccountConnection::connectionScopes()], ['synthetic-subject', [...CalendarAccountConnection::writeScopes(), 'email']]] as [$subject, $scopes]) {
      try {
        $connections->grantWriteAccessSameSubject($subject, $scopes);
        $this->fail('Non-exact scope or subject was accepted.');
      }
      catch (\InvalidArgumentException) {
        $this->assertFalse($connections->hasWriteGrant());
      }
    }
    $connections->grantWriteAccessSameSubject('synthetic-subject', CalendarAccountConnection::writeScopes());
    $this->assertTrue($connections->hasWriteGrant());
    $connections->markInvalid();
    $this->assertFalse($connections->hasWriteGrant());
    try {
      $connections->grantWriteAccessSameSubject('synthetic-subject', CalendarAccountConnection::writeScopes());
      $this->fail('Invalid connection gained write authority.');
    }
    catch (\InvalidArgumentException) {
      $this->addToAssertionCount(1);
    }
    foreach ([FALSE, TRUE] as $incremental) {
      $scopes = $incremental ? CalendarAccountConnection::incrementalWriteScopes() : CalendarAccountConnection::connectionScopes();
      $provider = new GoogleCalendarProvider([
        'clientId' => 'synthetic-id', 'clientSecret' => 'synthetic-secret',
        'redirectUri' => 'https://example.test/callback',
        'urlAuthorize' => 'https://example.test/authorize', 'urlAccessToken' => 'https://example.test/token',
        'urlResourceOwnerDetails' => 'https://example.test/userinfo', 'scopes' => $scopes, 'scopeSeparator' => ' ',
      ], [], $incremental);
      parse_str((string) parse_url($provider->getAuthorizationUrl(['scope' => ['email'], 'include_granted_scopes' => 'true']), PHP_URL_QUERY), $query);
      $this->assertSame(implode(' ', $scopes), $query['scope']);
      $this->assertSame($incremental ? 'true' : NULL, $query['include_granted_scopes'] ?? NULL);
    }
  }

  public function testPayloadPrivacyAllDayAndRescheduledIdentity(): void {
    [$series, $key, $start] = $this->fixture();
    $resolver = $this->container->get('personal_secretary.google_calendar_projection_resolver');
    $builder = $this->container->get('personal_secretary.google_calendar_payload');
    $resolved = $resolver->resolve((int) $series->id(), $key);
    $payload = $builder->build($resolved);
    $this->assertSame(['summary', 'location', 'start', 'end'], array_keys($payload));
    $this->assertSame('Synthetic manual activity', $payload['summary']);
    $this->assertSame('Synthetic room', $payload['location']);
    $this->assertSame($start->format(DATE_ATOM), $payload['start']['dateTime']);
    $this->assertSame('Europe/Brussels', $payload['start']['timeZone']);
    $id = $builder->eventId($series->uuid(), $key);
    $this->assertSame('ps' . hash('sha256', $series->uuid() . '|' . $key), $id);
    $this->assertMatchesRegularExpression('/^[0-9a-v]{66}$/', $id);
    $this->assertSame($builder->fingerprint($payload), $builder->fingerprint(array_reverse($payload, TRUE)));
    $base = $this->container->get('personal_secretary.occurrence_projection')->project($series, limit: 2)[0];
    $this->container->get('personal_secretary.activity_exception')->createReschedule($series, $base, $start->modify('+2 hours'), $start->modify('+3 hours'), 'Europe/Brussels');
    $rescheduled = $resolver->resolve((int) $series->id(), $key);
    $this->assertSame($key, $rescheduled['occurrence']->originalOccurrenceKey);
    $this->assertSame($start->modify('+2 hours')->format(DATE_ATOM), $builder->build($rescheduled)['start']['dateTime']);
    $this->assertSame($id, $builder->eventId($rescheduled['occurrence']->seriesUuid, $key));
    [$allDay, $allDayKey, $dayStart] = $this->fixture(TRUE);
    $dayPayload = $builder->build($resolver->resolve((int) $allDay->id(), $allDayKey));
    $this->assertSame(['date' => $dayStart->format('Y-m-d')], $dayPayload['start']);
    $this->assertSame(['date' => $dayStart->modify('+1 day')->format('Y-m-d')], $dayPayload['end']);
    $this->assertArrayNotHasKey('location', $dayPayload);
  }

  public function testCreateNoopConditionalUpdateAndTerminalStates(): void {
    [$series, $key] = $this->fixture();
    $exports = $this->exportsWithResponses([200, 200, 412]);
    $this->assertSame('NOT_CONNECTED', $exports->presentationState((int) $series->id(), $key));
    $this->grant();
    $this->assertSame('NOT_EXPORTED', $exports->presentationState((int) $series->id(), $key));
    $this->assertSame('SUCCESS', $exports->create((int) $series->id(), $key));
    $this->assertSame('CURRENT', $exports->presentationState((int) $series->id(), $key));
    $this->assertSame('NOOP', $exports->update((int) $series->id(), $key));
    $this->assertCount(1, $this->requests);
    $series->set('location', 'Updated synthetic room')->save();
    $this->assertSame('STALE', $exports->presentationState((int) $series->id(), $key));
    $this->assertSame('SUCCESS', $exports->update((int) $series->id(), $key));
    $this->assertSame('POST', $this->requests[0][0]);
    $this->assertSame('PATCH', $this->requests[1][0]);
    $this->assertSame('"etag-1"', $this->requests[1][2]['headers']['If-Match']);
    $this->assertSame('Bearer synthetic-access', $this->requests[1][2]['headers']['Authorization']);
    $this->assertSame('application/json', $this->requests[1][2]['headers']['Accept']);
    $this->assertArrayNotHasKey('id', $this->requests[1][2]['json']);
    $this->assertSame($this->requests[0][1] . '/' . $this->requests[0][2]['json']['id'], $this->requests[1][1]);
    $series->set('location', '')->save();
    $this->assertSame('CONFLICT', $exports->update((int) $series->id(), $key));
    $this->assertSame('"etag-2"', $this->requests[2][2]['headers']['If-Match']);
    $this->assertNull($this->requests[2][2]['json']['location']);
    $this->assertSame('CONFLICT', $exports->presentationState((int) $series->id(), $key));
    $mapping = $this->container->get('personal_secretary.google_calendar_mapping')->find($series->uuid(), $key);
    $this->assertSame('"etag-2"', $mapping->get('etag')->value);
    try {
      $exports->update((int) $series->id(), $key);
      $this->fail('Conflict must not offer an overwrite.');
    }
    catch (\InvalidArgumentException) {
      $this->assertCount(3, $this->requests);
    }
    [$missingSeries, $missingKey] = $this->fixture();
    $exports = $this->exportsWithResponses([200, 404]);
    $this->assertSame('SUCCESS', $exports->create((int) $missingSeries->id(), $missingKey));
    $missingSeries->set('location', 'Changed')->save();
    $this->assertSame('REMOTE_MISSING', $exports->update((int) $missingSeries->id(), $missingKey));
    $this->assertSame('REMOTE_MISSING', $exports->presentationState((int) $missingSeries->id(), $missingKey));
  }

  public function testDuplicateDoesNotCreateMappingOrRetry(): void {
    [$series, $key] = $this->fixture();
    $this->grant();
    $exports = $this->exportsWithResponses([409]);
    $this->assertSame('DUPLICATE', $exports->create((int) $series->id(), $key));
    $this->assertCount(1, $this->requests);
    $this->assertNull($this->container->get('personal_secretary.google_calendar_mapping')->find($series->uuid(), $key));
  }

  public function testMappingUniquenessIsolationAndSubjectMismatch(): void {
    [$series, $key] = $this->fixture();
    $this->grant();
    $exports = $this->exportsWithResponses([200]);
    $exports->create((int) $series->id(), $key);
    $repository = $this->container->get('personal_secretary.google_calendar_mapping');
    $mapping = $repository->find($series->uuid(), $key);
    $this->assertNotNull($mapping);
    try {
      $mapping->createDuplicate()->save();
      $this->fail('Duplicate mapping must fail.');
    }
    catch (EntityStorageException) {
      $this->addToAssertionCount(1);
    }
    $other = User::create(['name' => 'synthetic-other-user', 'status' => 1]);
    $other->save();
    $this->container->get('current_user')->setAccount($other);
    $this->assertNull($repository->find($series->uuid(), $key));
    try {
      $repository->mark($mapping, 'synthetic-subject', GoogleCalendarProjection::CONFLICT);
      $this->fail('Another User changed a mapping.');
    }
    catch (\InvalidArgumentException) {
      $this->addToAssertionCount(1);
    }
    $this->container->get('current_user')->setAccount($this->account);
    $connections = $this->container->get('personal_secretary.google_calendar_connection');
    $connections->disconnectLocal();
    $connections->connect('synthetic-other-subject', CalendarAccountConnection::connectionScopes());
    $connections->grantWriteAccessSameSubject('synthetic-other-subject', CalendarAccountConnection::writeScopes());
    $this->assertSame('CONFLICT', $exports->presentationState((int) $series->id(), $key));
    try {
      $exports->update((int) $series->id(), $key);
      $this->fail('Different subject updated the event.');
    }
    catch (\InvalidArgumentException) {
      $this->assertCount(1, $this->requests);
    }
  }

  public function testActionsReauthorizeAndRejectRecurringOrIneligibleOccurrences(): void {
    [$series, $key] = $this->fixture();
    $this->grant();
    $exports = $this->exportsWithResponses([200]);
    $exports->create((int) $series->id(), $key);
    $this->account->set(HouseholdAuthorizationService::FIELD_NAME, [])->save();
    try {
      $exports->update((int) $series->id(), $key);
      $this->fail('Revoked household authority must fail even for NOOP.');
    }
    catch (\InvalidArgumentException) {
      $this->assertCount(1, $this->requests);
    }
    [$recurring, $recurringKey] = $this->fixture(FALSE, 'FREQ=DAILY;COUNT=2');
    try {
      $exports->create((int) $recurring->id(), $recurringKey);
      $this->fail('Recurring activity exported.');
    }
    catch (\InvalidArgumentException) {
      $this->assertCount(1, $this->requests);
    }
    [$ineligible, $ineligibleKey] = $this->fixture(FALSE, 'FREQ=DAILY;COUNT=1', FALSE);
    try {
      $exports->create((int) $ineligible->id(), $ineligibleKey);
      $this->fail('Occurrence without time commitment exported.');
    }
    catch (\InvalidArgumentException) {
      $this->assertCount(1, $this->requests);
    }
  }

  public function testWriteCompletionScopeSubjectAndProviderFailure(): void {
    [$series, $key] = $this->fixture();
    $connections = $this->container->get('personal_secretary.google_calendar_connection');
    foreach ([NULL, implode(' ', CalendarAccountConnection::connectionScopes()), implode(' ', [...CalendarAccountConnection::writeScopes(), 'email']), implode(' ', CalendarAccountConnection::writeScopes())] as $scope) {
      $connections->connect('synthetic-subject', CalendarAccountConnection::connectionScopes());
      $this->pending((int) $series->id(), $key);
      $oauth = $this->createMock(Oauth2ClientServiceInterface::class);
      $oauth->method('retrieveAccessToken')->willReturn(new AccessToken(['access_token' => 'synthetic-access', 'expires' => time() + 3600] + ($scope === NULL ? [] : ['scope' => $scope])));
      $http = $this->createMock(ClientInterface::class);
      $http->expects($scope === implode(' ', CalendarAccountConnection::writeScopes()) ? $this->once() : $this->never())->method('request')->willReturn(new Response(200, [], '{"sub":"other-subject"}'));
      $oauth->expects($this->once())->method('clearAccessToken');
      $this->controller($oauth, $http, $this->exportsWithResponses([]))->complete();
      $this->assertFalse($connections->hasWriteGrant());
      $this->assertNull($this->container->get('tempstore.private')->get('personal_secretary_google_calendar')->get('pending'));
    }
    $connections->connect('synthetic-subject', CalendarAccountConnection::connectionScopes());
    $this->pending((int) $series->id(), $key);
    $oauth = $this->createMock(Oauth2ClientServiceInterface::class);
    $oauth->method('retrieveAccessToken')->willReturn($this->token());
    $oauth->expects($this->never())->method('clearAccessToken');
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->once())->method('request')->willReturn(new Response(200, [], '{"sub":"synthetic-subject"}'));
    $this->controller($oauth, $http, $this->exportsWithResponses([503]))->complete();
    $this->assertTrue($connections->hasWriteGrant());
    $this->assertSame(CalendarAccountConnection::STATUS_CONNECTED, $connections->currentState());
    $this->assertNull($this->container->get('personal_secretary.google_calendar_mapping')->find($series->uuid(), $key));
    // A fresh explicit authorization can create; the captured context is consumed.
    $connections->connect('synthetic-subject', CalendarAccountConnection::connectionScopes());
    $this->pending((int) $series->id(), $key);
    $successHttp = $this->createMock(ClientInterface::class);
    $successHttp->expects($this->once())->method('request')->willReturn(new Response(200, [], '{"sub":"synthetic-subject"}'));
    $this->controller($oauth, $successHttp, $this->exportsWithResponses([200]))->complete();
    $this->assertNotNull($this->container->get('personal_secretary.google_calendar_mapping')->find($series->uuid(), $key));
  }

  public function testTransportRequiresAValidTokenBeforeEgress(): void {
    $httpWithoutOauth = $this->createMock(ClientInterface::class);
    $httpWithoutOauth->expects($this->never())->method('request');
    $transportWithoutOauth = new GoogleCalendarEventTransport(NULL, $httpWithoutOauth);
    try {
      $transportWithoutOauth->insert(
        'ps' . hash('sha256', 'synthetic-event'),
        ['summary' => 'Synthetic'],
      );
      $this->fail('Missing OAuth capability reached the Events API.');
    }
    catch (\RuntimeException) {
      $this->addToAssertionCount(1);
    }

    foreach ([NULL, new AccessToken(['access_token' => 'synthetic-expired', 'expires' => time() - 60])] as $token) {
      $oauth = $this->createMock(Oauth2ClientServiceInterface::class);
      $oauth->method('getAccessToken')->willReturn($token);
      $http = $this->createMock(ClientInterface::class);
      $http->expects($this->never())->method('request');
      $transport = new GoogleCalendarEventTransport($oauth, $http);
      try {
        $transport->insert('ps' . hash('sha256', 'synthetic-event'), ['summary' => 'Synthetic']);
        $this->fail('Invalid token reached the Events API.');
      }
      catch (\RuntimeException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  private function fixture(bool $allDay = FALSE, string $rrule = 'FREQ=DAILY;COUNT=1', bool $commitment = TRUE): array {
    $domain = $this->container->get('personal_secretary.domain_mutation');
    $person = $domain->createPerson('Synthetic responsible person');
    $household = $domain->createHousehold('Synthetic export household', [(int) $person->id()]);
    $this->account->set(CurrentPersonResolver::FIELD_NAME, $person->id())->set(HouseholdAuthorizationService::FIELD_NAME, $household->id())->save();
    $start = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Brussels')))->modify('+3 days')->setTime($allDay ? 0 : 10, 0);
    $end = $start->modify($allDay ? '+1 day' : '+1 hour');
    $series = $domain->createActivitySeries('Synthetic manual activity', (int) $household->id(), $start, $end, $rrule, $allDay ? '' : 'Synthetic room', [], $allDay ? ActivitySeries::TIME_MODE_ALL_DAY : ActivitySeries::TIME_MODE_TIMED);
    $this->container->get('personal_secretary.responsibility_mutation')->createResponsibilityRule($series, (int) $person->id(), $start, $allDay ? $end : $start->modify('+4 hours'), $rrule);
    if ($commitment) {
      $this->container->get('personal_secretary.time_commitment_mutation')->createFullOccurrenceCommitment($series, $start);
    }
    $base = $this->container->get('personal_secretary.occurrence_projection')->project($series, limit: 2)[0];
    return [$series, $base->originalOccurrenceKey, $start];
  }

  private function grant(): void {
    $connections = $this->container->get('personal_secretary.google_calendar_connection');
    $connections->connect('synthetic-subject', CalendarAccountConnection::connectionScopes());
    $connections->grantWriteAccessSameSubject('synthetic-subject', CalendarAccountConnection::writeScopes());
  }

  private function token(): AccessToken {
    return new AccessToken(['access_token' => 'synthetic-access', 'expires' => time() + 3600, 'scope' => implode(' ', CalendarAccountConnection::writeScopes())]);
  }

  private function exportsWithResponses(array $statuses): GoogleCalendarExportService {
    $oauth = $this->createMock(Oauth2ClientServiceInterface::class);
    $oauth->method('getAccessToken')->willReturn($this->token());
    $http = $this->createMock(ClientInterface::class);
    $this->requests = [];
    $http->expects($this->exactly(count($statuses)))->method('request')->willReturnCallback(function (string $method, string $uri, array $options) use (&$statuses): Response {
      $this->requests[] = [$method, $uri, $options];
      $this->assertFalse($options['allow_redirects']);
      $this->assertSame([], array_diff(array_keys($options['json']), ['id', 'summary', 'location', 'start', 'end']));
      return new Response(array_shift($statuses), [], json_encode(['id' => $options['json']['id'] ?? basename($uri), 'etag' => '"etag-' . count($this->requests) . '"'], JSON_THROW_ON_ERROR));
    });
    return new GoogleCalendarExportService(
      $this->container->get('personal_secretary.google_calendar_projection_resolver'),
      $this->container->get('personal_secretary.google_calendar_connection'),
      $this->container->get('personal_secretary.google_calendar_payload'),
      $this->container->get('personal_secretary.google_calendar_mapping'),
      new GoogleCalendarEventTransport($oauth, $http),
    );
  }

  private function pending(int $series, string $key): void {
    $this->container->get('tempstore.private')->get('oauth2_client')->set('oauth2_client_state-' . GoogleCalendar::PLUGIN_ID, 'synthetic-state');
    $this->container->get('tempstore.private')->get('personal_secretary_google_calendar')->set('pending', [
      'uid' => (int) $this->account->id(), 'state_hash' => hash('sha256', 'synthetic-state'), 'purpose' => 'write_export', 'series' => $series, 'key' => $key,
    ]);
  }

  private function controller(Oauth2ClientServiceInterface $oauth, ClientInterface $http, GoogleCalendarExportService $exports): GoogleCalendarController {
    return new GoogleCalendarController(
      $this->container->get('entity_type.manager'), $oauth,
      $this->container->get('personal_secretary.google_calendar_connection'),
      $this->container->get('tempstore.private'), $this->container->get('current_user'), $http,
      $this->container->get('request_stack'), $this->container->get('messenger'),
      $this->container->get('personal_secretary.google_calendar_projection_resolver'), $exports,
    );
  }

}
