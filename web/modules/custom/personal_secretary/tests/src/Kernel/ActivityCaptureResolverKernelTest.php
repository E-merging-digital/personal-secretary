<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Kernel;

use DateTimeImmutable;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\personal_secretary\Entity\Household;
use Drupal\personal_secretary\Entity\Person;
use Drupal\personal_secretary\Service\ActivityCaptureResolver;
use Drupal\personal_secretary\Service\CurrentPersonResolver;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Drupal\personal_secretary\Value\ActivityCaptureExtraction;
use Drupal\personal_secretary\Value\ActivityCaptureInput;
use Drupal\personal_secretary\Value\ActivityCaptureProposal;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves deterministic activity-capture normalization and identity resolution.
 */
#[RunTestsInSeparateProcesses]
#[Group('personal_secretary')]
final class ActivityCaptureResolverKernelTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'datetime',
    'datetime_range',
    'date_recur',
    'key',
    'phpmailer_smtp',
    'personal_secretary',
  ];

  private string $roleId = 'activity_capture_resolver_test';

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('personal_secretary_person');
    $this->installEntitySchema('personal_secretary_household');
    $this->installEntitySchema('personal_sec_activity_series');
    $this->installConfig(['system', 'user']);

    Role::create([
      'id' => $this->roleId,
      'label' => 'Activity capture resolver test',
    ])->grantPermission(HouseholdAuthorizationService::PRODUCT_USE_PERMISSION)->save();

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

    // Keep the tested account out of Drupal's uid=1 bypass semantics.
    $inert = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'activity-capture-inert',
      'status' => 0,
    ]);
    $inert->save();
  }

  public function testNearMidnightResolutionUsesFrozenContextAndAuthorizedIdentity(): void {
    $current = $this->person('Current Person');
    $alpha = $this->person('Personne Alpha');
    $household = $this->household('Capture Household', [$current, $alpha]);
    $this->setCurrentUser($this->productUser($current, [$household]));

    $before = $this->activityCount();
    $proposal = $this->resolver()->resolve(
      new ActivityCaptureInput(
        'Demain à 08h, accompagner Personne Alpha.',
        new DateTimeImmutable('2026-10-24T22:30:00Z'),
        'Europe/Brussels',
      ),
      $this->extraction([
        'label_text' => 'Accompagner au rendez-vous',
        'concerned_person_mentions' => ['Personne Alpha'],
        'responsibility_candidate' => 'SELF',
        'date_expression' => 'Demain',
        'date_day' => NULL,
        'date_month' => NULL,
        'date_year' => NULL,
        'relative_day_offset' => 1,
        'start_time_expression' => '08h',
        'end_time_expression' => '09h',
        'explicit_timed_signal' => TRUE,
      ]),
    );

    self::assertSame((int) $household->id(), $proposal->householdId);
    self::assertSame(ActivityCaptureProposal::INTENT_ONE_OFF, $proposal->intent);
    self::assertSame(ActivitySeries::TIME_MODE_TIMED, $proposal->timeMode);
    self::assertSame('2026-10-26', $proposal->absoluteDate);
    self::assertSame('08:00', $proposal->localStartTime);
    self::assertSame('09:00', $proposal->localEndTime);
    self::assertSame('Europe/Brussels', $proposal->sourceTimezone);
    self::assertSame([(int) $alpha->id()], $proposal->concernedPersonIds);
    self::assertSame((int) $current->id(), $proposal->responsiblePersonId);
    self::assertSame([], $proposal->clarifications);
    self::assertTrue($proposal->readyForConfirmation());
    self::assertSame($before, $this->activityCount(), 'Resolution must never mutate ActivitySeries.');
  }

  public function testWeeklyNormalizationDoesNotInventResponsibility(): void {
    $current = $this->person('Weekly Current');
    $household = $this->household('Weekly Household', [$current]);
    $this->setCurrentUser($this->productUser($current, [$household]));

    $proposal = $this->resolver()->resolve(
      $this->input('Tous les mardis à 07h15, aller courir.'),
      $this->extraction([
        'label_text' => 'Aller courir',
        'date_expression' => 'mardi',
        'date_day' => NULL,
        'date_month' => NULL,
        'date_year' => NULL,
        'start_time_expression' => '07h15',
        'end_time_expression' => '08h15',
        'recurrence_expression' => 'Tous les mardis',
        'explicit_timed_signal' => TRUE,
      ]),
    );

    self::assertSame(ActivityCaptureProposal::INTENT_WEEKLY, $proposal->intent);
    self::assertSame('TUESDAY', $proposal->weekday);
    self::assertNull($proposal->responsiblePersonId);
    self::assertContains(ActivityCaptureResolver::CLARIFICATION_RESPONSIBILITY_REQUIRED, $proposal->clarifications);
    self::assertFalse($proposal->readyForConfirmation());
  }

  public function testComplexRecurrenceFailsClosedAndAllDayNormalizesDeterministically(): void {
    $current = $this->person('Recurrence Current');
    $household = $this->household('Recurrence Household', [$current]);
    $this->setCurrentUser($this->productUser($current, [$household]));

    $unsupported = $this->resolver()->resolve(
      $this->input("Le premier lundi de chaque mois à 09h, faire l'inventaire."),
      $this->extraction([
        'label_text' => 'Faire inventaire',
        'date_expression' => 'lundi',
        'date_day' => NULL,
        'date_month' => NULL,
        'date_year' => NULL,
        'start_time_expression' => '09h',
        'end_time_expression' => '10h',
        'recurrence_expression' => 'premier lundi de chaque mois',
        'explicit_timed_signal' => TRUE,
        'responsibility_candidate' => 'SELF',
      ]),
    );
    self::assertNull($unsupported->intent);
    self::assertContains(ActivityCaptureResolver::CLARIFICATION_UNSUPPORTED_RECURRENCE, $unsupported->clarifications);
    self::assertFalse($unsupported->readyForConfirmation());

    $allDay = $this->resolver()->resolve(
      $this->input('Le 21 septembre 2026, journée administrative.'),
      $this->extraction([
        'label_text' => 'Journée administrative',
        'date_expression' => '21 septembre 2026',
        'date_day' => 21,
        'date_month' => 9,
        'date_year' => 2026,
        'explicit_all_day_signal' => TRUE,
      ]),
    );
    self::assertSame(ActivitySeries::TIME_MODE_ALL_DAY, $allDay->timeMode);
    self::assertSame('2026-09-21', $allDay->absoluteDate);
    self::assertNull($allDay->localStartTime);
    self::assertNull($allDay->localEndTime);
    self::assertTrue($allDay->readyForConfirmation());
  }

  public function testPersonResolutionUsesZeroOneManyAndLinguisticAlternativeRules(): void {
    $current = $this->person('Identity Current');
    $alpha = $this->person('Personne Alpha');
    $beta = $this->person('Personne Bêta');
    $duplicateOne = $this->person('Personne Double');
    $duplicateTwo = $this->person('Personne Double');
    $household = $this->household(
      'Identity Household',
      [$current, $alpha, $beta, $duplicateOne, $duplicateTwo],
    );
    $this->setCurrentUser($this->productUser($current, [$household]));

    $unique = $this->resolver()->resolve(
      $this->input('Activité avec Personne Alpha.'),
      $this->extraction([
        'label_text' => 'Activité',
        'concerned_person_mentions' => ['Personne Alpha'],
        'date_expression' => '18 septembre 2026',
        'date_day' => 18,
        'date_month' => 9,
        'date_year' => 2026,
        'explicit_all_day_signal' => TRUE,
      ]),
    );
    self::assertSame([(int) $alpha->id()], $unique->concernedPersonIds);
    self::assertNotContains(ActivityCaptureResolver::CLARIFICATION_PERSON_NOT_FOUND, $unique->clarifications);

    $unknown = $this->resolver()->resolve(
      $this->input('Activité avec Personne Inconnue.'),
      $this->extraction([
        'label_text' => 'Activité',
        'concerned_person_mentions' => ['Personne Inconnue'],
        'date_expression' => '18 septembre 2026',
        'date_day' => 18,
        'date_month' => 9,
        'date_year' => 2026,
        'explicit_all_day_signal' => TRUE,
      ]),
    );
    self::assertContains(ActivityCaptureResolver::CLARIFICATION_PERSON_NOT_FOUND, $unknown->clarifications);

    $ambiguous = $this->resolver()->resolve(
      $this->input('Activité avec Personne Double.'),
      $this->extraction([
        'label_text' => 'Activité',
        'concerned_person_mentions' => ['Personne Double'],
        'date_expression' => '18 septembre 2026',
        'date_day' => 18,
        'date_month' => 9,
        'date_year' => 2026,
        'explicit_all_day_signal' => TRUE,
      ]),
    );
    self::assertContains(ActivityCaptureResolver::CLARIFICATION_PERSON_AMBIGUOUS, $ambiguous->clarifications);

    $alternative = $this->resolver()->resolve(
      $this->input('Activité avec Personne Alpha ou Personne Bêta.'),
      $this->extraction([
        'label_text' => 'Activité',
        'concerned_person_mentions' => ['Personne Alpha', 'Personne Bêta'],
        'concerned_person_alternative' => TRUE,
        'date_expression' => '18 septembre 2026',
        'date_day' => 18,
        'date_month' => 9,
        'date_year' => 2026,
        'explicit_all_day_signal' => TRUE,
      ]),
    );
    self::assertSame([], $alternative->concernedPersonIds);
    self::assertContains(ActivityCaptureResolver::CLARIFICATION_PERSON_ALTERNATIVE, $alternative->clarifications);
  }

  public function testTextResponsibilityMapsServerSideAndMissingEndRequiresReview(): void {
    $current = $this->person('Responsibility Current');
    $beta = $this->person('Personne Bêta');
    $household = $this->household('Responsibility Household', [$current, $beta]);
    $this->setCurrentUser($this->productUser($current, [$household]));

    $proposal = $this->resolver()->resolve(
      $this->input('Le 25 septembre 2026 à 18h30, fermer le local. Personne Bêta s’en charge.'),
      $this->extraction([
        'label_text' => 'Fermer le local',
        'responsibility_candidate' => 'Personne Bêta',
        'date_expression' => '25 septembre 2026',
        'date_day' => 25,
        'date_month' => 9,
        'date_year' => 2026,
        'start_time_expression' => '18h30',
        'end_time_expression' => NULL,
        'explicit_timed_signal' => TRUE,
      ]),
    );

    self::assertSame((int) $beta->id(), $proposal->responsiblePersonId);
    self::assertContains(ActivityCaptureResolver::CLARIFICATION_END_TIME_REQUIRED, $proposal->clarifications);
    self::assertFalse($proposal->readyForConfirmation());
  }

  private function resolver(): ActivityCaptureResolver {
    $resolver = $this->container->get('personal_secretary.activity_capture_resolver');
    self::assertInstanceOf(ActivityCaptureResolver::class, $resolver);
    return $resolver;
  }

  private function input(string $text): ActivityCaptureInput {
    return new ActivityCaptureInput(
      $text,
      new DateTimeImmutable('2026-09-16T12:00:00Z'),
      'Europe/Brussels',
    );
  }

  private function extraction(array $overrides): ActivityCaptureExtraction {
    $data = array_replace([
      'label_text' => 'Synthetic activity',
      'location_text' => NULL,
      'concerned_person_mentions' => [],
      'concerned_person_alternative' => FALSE,
      'responsibility_candidate' => NULL,
      'date_expression' => '18 septembre 2026',
      'date_day' => 18,
      'date_month' => 9,
      'date_year' => 2026,
      'relative_day_offset' => NULL,
      'start_time_expression' => NULL,
      'end_time_expression' => NULL,
      'recurrence_expression' => NULL,
      'explicit_all_day_signal' => FALSE,
      'explicit_timed_signal' => FALSE,
    ], $overrides);
    return ActivityCaptureExtraction::fromArray($data);
  }

  private function person(string $name): Person {
    $person = $this->container->get('entity_type.manager')
      ->getStorage('personal_secretary_person')
      ->create(['name' => $name]);
    self::assertInstanceOf(Person::class, $person);
    $person->save();
    return $person;
  }

  /**
   * @param Person[] $people
   *   Household members.
   */
  private function household(string $name, array $people): Household {
    $household = $this->container->get('entity_type.manager')
      ->getStorage('personal_secretary_household')
      ->create([
        'name' => $name,
        'members' => array_map(
          static fn(Person $person): array => ['target_id' => (int) $person->id()],
          $people,
        ),
      ]);
    self::assertInstanceOf(Household::class, $household);
    $household->save();
    return $household;
  }

  /**
   * @param Household[] $households
   *   Authorized Household grants.
   */
  private function productUser(Person $currentPerson, array $households): UserInterface {
    $user = $this->container->get('entity_type.manager')->getStorage('user')->create([
      'name' => 'capture-user-' . bin2hex(random_bytes(4)),
      'status' => 1,
      'roles' => [$this->roleId],
      CurrentPersonResolver::FIELD_NAME => ['target_id' => (int) $currentPerson->id()],
      HouseholdAuthorizationService::FIELD_NAME => array_map(
        static fn(Household $household): array => ['target_id' => (int) $household->id()],
        $households,
      ),
    ]);
    self::assertInstanceOf(UserInterface::class, $user);
    $user->save();
    return $user;
  }

  private function setCurrentUser(UserInterface $user): void {
    $this->container->get('current_user')->setAccount($user);
  }

  private function activityCount(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('personal_sec_activity_series')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
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
    $this->container->get('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
