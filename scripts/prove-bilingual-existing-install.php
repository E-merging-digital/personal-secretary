<?php

declare(strict_types=1);

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

$phase = getenv('PERSONAL_SECRETARY_EXISTING_INSTALL_PHASE') ?: '';
$snapshotPath = '/tmp/personal-secretary-bilingual-existing-install.json';
$manager = \Drupal::entityTypeManager();

$snapshot = static function (int $personId, int $householdId, int $seriesId, int $userId) use ($manager): array {
  $person = $manager->getStorage('personal_secretary_person')->load($personId);
  $household = $manager->getStorage('personal_secretary_household')->load($householdId);
  $series = $manager->getStorage('personal_sec_activity_series')->load($seriesId);
  $user = User::load($userId);

  if ($person === NULL || $household === NULL || !$series instanceof ActivitySeries || !$user instanceof UserInterface) {
    throw new RuntimeException('Existing-install proof fixtures could not be reloaded.');
  }

  return [
    'person' => [
      'id' => (int) $person->id(),
      'uuid' => $person->uuid(),
      'name' => (string) $person->label(),
    ],
    'household' => [
      'id' => (int) $household->id(),
      'uuid' => $household->uuid(),
      'name' => (string) $household->label(),
      'members' => $household->get('members')->getValue(),
    ],
    'series' => [
      'id' => (int) $series->id(),
      'uuid' => $series->uuid(),
      'revision_id' => (int) $series->getRevisionId(),
      'name' => (string) $series->label(),
      'recurrence' => $series->get('recurrence')->getValue(),
      'effective_from' => $series->get('effective_from')->getValue(),
      'location' => $series->get('location')->getValue(),
      'time_mode' => $series->get('time_mode')->getValue(),
    ],
    'user' => [
      'id' => (int) $user->id(),
      'uuid' => $user->uuid(),
      'timezone' => (string) $user->getTimezone(),
      'preferred_langcode' => $user->getPreferredLangcode(FALSE),
    ],
  ];
};

if ($phase === 'seed') {
  if (\Drupal::moduleHandler()->moduleExists('language') || \Drupal::moduleHandler()->moduleExists('locale')) {
    throw new RuntimeException('Current-main baseline unexpectedly has multilingual Core modules enabled.');
  }

  $domain = \Drupal::service('personal_secretary.domain_mutation');
  $person = $domain->createPerson('Existing install Éva');
  $household = $domain->createHousehold('Existing install Household', [(int) $person->id()]);
  $start = new DateTimeImmutable('2026-10-20 09:00:00', new DateTimeZone('Europe/Brussels'));
  $series = $domain->createActivitySeries(
    'Existing install activity – unchanged',
    (int) $household->id(),
    $start,
    $start->modify('+1 hour'),
    'FREQ=WEEKLY;COUNT=4',
    'Existing install location',
    [(int) $person->id()],
  );

  $user = User::create([
    'name' => 'existing-install-user',
    'mail' => 'existing-install@example.invalid',
    'status' => 1,
    'timezone' => 'Asia/Tokyo',
    'preferred_langcode' => 'en',
  ]);
  $user->save();

  $before = $snapshot((int) $person->id(), (int) $household->id(), (int) $series->id(), (int) $user->id());
  file_put_contents($snapshotPath, json_encode($before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
  print "EXISTING_INSTALL_BASELINE_SEEDED=PASS\n";
  return;
}

if ($phase !== 'verify') {
  throw new RuntimeException('Set PERSONAL_SECRETARY_EXISTING_INSTALL_PHASE to seed or verify.');
}

$before = json_decode((string) file_get_contents($snapshotPath), TRUE, 512, JSON_THROW_ON_ERROR);
$after = $snapshot(
  (int) $before['person']['id'],
  (int) $before['household']['id'],
  (int) $before['series']['id'],
  (int) $before['user']['id'],
);

if ($after !== $before) {
  throw new RuntimeException(
    "Existing domain or User timezone data changed across bilingual deployment.\nBEFORE="
    . json_encode($before, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
    . "\nAFTER="
    . json_encode($after, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
  );
}

foreach ($manager->getDefinitions() as $entityTypeId => $definition) {
  if (
    $definition instanceof ContentEntityTypeInterface
    && $definition->getProvider() === 'personal_secretary'
    && $definition->isTranslatable()
  ) {
    throw new RuntimeException("Domain entity became translatable: {$entityTypeId}");
  }
}

$moduleHandler = \Drupal::moduleHandler();
foreach (['language', 'locale'] as $module) {
  if (!$moduleHandler->moduleExists($module)) {
    throw new RuntimeException("Required Core multilingual module is not enabled: {$module}");
  }
}
if ($moduleHandler->moduleExists('config_translation')) {
  throw new RuntimeException('config_translation must remain disabled for #147.');
}

$checks = [
  'config source language' => [\Drupal::config('system.site')->get('langcode'), 'en'],
  'runtime default language' => [\Drupal::config('system.site')->get('default_langcode'), 'fr'],
  'locked config langcode' => [\Drupal::config('config_language_lock.settings')->get('locked_langcode'), 'en'],
  'follow site default' => [\Drupal::config('config_language_lock.settings')->get('follow_site_default'), FALSE],
];
foreach ($checks as $label => [$actual, $expected]) {
  if ($actual !== $expected) {
    throw new RuntimeException($label . ' mismatch: ' . var_export($actual, TRUE));
  }
}

$languages = \Drupal::languageManager()->getLanguages();
if (!isset($languages['fr'], $languages['en'])) {
  throw new RuntimeException('French and English runtime languages are not both available.');
}

$translation = \Drupal::service('locale.storage')->findTranslation([
  'source' => 'Today',
  'language' => 'fr',
]);
if (($translation->translation ?? NULL) !== 'Aujourd’hui') {
  throw new RuntimeException('Repository-owned French catalog was not imported on the existing-install path.');
}

print "EXISTING_INSTALL_DOMAIN_UNCHANGED=PASS\n";
print "EXISTING_INSTALL_USER_TIMEZONE_UNCHANGED=PASS\n";
print "EXISTING_INSTALL_TRANSLATION_IMPORT=PASS\n";
print "EXISTING_INSTALL_ENTITY_TRANSLATABILITY_UNCHANGED=PASS\n";
