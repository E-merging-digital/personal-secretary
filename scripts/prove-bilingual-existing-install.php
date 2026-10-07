<?php

declare(strict_types=1);

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\personal_secretary\Entity\ActivitySeries;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

$phase = getenv('PERSONAL_SECRETARY_EXISTING_INSTALL_PHASE') ?: '';
$snapshotPath = '/tmp/personal-secretary-bilingual-existing-install.json';
$manager = \Drupal::entityTypeManager();
$new231Translations = [
  'Read-only external event' => 'Événement externe en lecture seule',
  'Refresh now' => 'Actualiser maintenant',
  'Planning sync: @state' => 'Synchronisation du planning : @state',
];


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
  $moduleHandler = \Drupal::moduleHandler();
  $languageEnabled = $moduleHandler->moduleExists('language');
  $localeEnabled = $moduleHandler->moduleExists('locale');
  if ($languageEnabled !== $localeEnabled) {
    throw new RuntimeException('Current-main baseline has inconsistent multilingual Core module state.');
  }

  $database = \Drupal::database();
  foreach (array_keys($new231Translations) as $source) {
    $sourceId = $database->select('locales_source', 's')
      ->fields('s', ['lid'])
      ->condition('source', $source)
      ->execute()
      ->fetchField();
    if ($sourceId === FALSE) {
      throw new RuntimeException("Expected #231 translation source is missing from the pre-repair catalog: {$source}");
    }
    $database->delete('locales_target')
      ->condition('lid', $sourceId)
      ->condition('language', 'fr')
      ->execute();
  }
  \Drupal::service('string_translation')->reset();

  $updateManager = \Drupal::entityDefinitionUpdateManager();
  foreach (['personal_sec_ext_event_shadow', 'personal_sec_calendar_sync_state'] as $entityTypeId) {
    $installed = $updateManager->getEntityType($entityTypeId);
    if ($installed !== NULL) {
      $updateManager->uninstallEntityType($installed);
    }
    if ($updateManager->getEntityType($entityTypeId) !== NULL) {
      throw new RuntimeException("Could not prepare pre-11012 persistence state: {$entityTypeId}");
    }
  }

  \Drupal::keyValue('system.schema')->set('personal_secretary', 11011);

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
  print "PRE_REPAIR_SCHEMA_VERSION=11011\n";
  print "NEW_231_FRENCH_TRANSLATIONS_PRE_REPAIR=MISSING\n";
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

$localeStorage = \Drupal::service('locale.storage');
$translation = $localeStorage->findTranslation([
  'source' => 'Today',
  'language' => 'fr',
]);
if (($translation->translation ?? NULL) !== 'Aujourd’hui') {
  throw new RuntimeException('Historical repository-owned French catalog entry is missing after repair.');
}

foreach ($new231Translations as $source => $expected) {
  $translation = $localeStorage->findTranslation([
    'source' => $source,
    'language' => 'fr',
  ]);
  if (($translation->translation ?? NULL) !== $expected) {
    throw new RuntimeException("Missing repaired #231 French translation: {$source}");
  }
}

$schemaVersion = (int) \Drupal::keyValue('system.schema')->get('personal_secretary', 0);
if ($schemaVersion !== 11013) {
  throw new RuntimeException("Expected Personal Secretary schema 11013 after repair, got {$schemaVersion}.");
}

$updateManager = \Drupal::entityDefinitionUpdateManager();
foreach (['personal_sec_ext_event_shadow', 'personal_sec_calendar_sync_state'] as $entityTypeId) {
  if ($updateManager->getEntityType($entityTypeId) === NULL) {
    throw new RuntimeException("Update 11012 did not install expected entity persistence: {$entityTypeId}");
  }
}

$installSource = (string) file_get_contents(
  DRUPAL_ROOT . '/modules/custom/personal_secretary/personal_secretary.install',
);
if (
  !str_contains($installSource, "function personal_secretary_update_11013(): string")
  || !str_contains($installSource, "personal_secretary_import_french_catalog();")
  || !str_contains($installSource, "__DIR__ . '/translations/fr.po'")
  || !str_contains($installSource, 'Gettext::fileToDatabase(')
) {
  throw new RuntimeException('French catalog repair is not wired to the governed local import helper.');
}

print "UPDATE_11012=PASS\n";
print "UPDATE_11013=PASS\n";
print "NEW_231_FRENCH_TRANSLATIONS=PASS\n";
print "EXISTING_INSTALL_DOMAIN_UNCHANGED=PASS\n";
print "EXISTING_INSTALL_USER_TIMEZONE_UNCHANGED=PASS\n";
print "EXISTING_INSTALL_TRANSLATION_IMPORT=PASS\n";
print "EXISTING_INSTALL_ENTITY_TRANSLATABILITY_UNCHANGED=PASS\n";
print "NO_NETWORK_TRANSLATION_FETCH=PASS\n";
