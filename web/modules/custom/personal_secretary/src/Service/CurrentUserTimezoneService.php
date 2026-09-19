<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\TimeZoneFormHelper;
use Drupal\Core\Session\AccountInterface;

/**
 * Resolves the current user's durable timezone with the canonical site fallback.
 */
final class CurrentUserTimezoneService {

  public const FALLBACK_TIMEZONE = 'Europe/Brussels';

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Returns the valid durable Drupal User timezone, when one exists.
   */
  public function persistedTimezone(): ?string {
    if (!$this->currentUser->isAuthenticated()) {
      return NULL;
    }

    $timezone = trim((string) $this->currentUser->getTimeZone());
    return $this->isValidTimezone($timezone) ? $timezone : NULL;
  }

  /**
   * Returns the authoritative server-side timezone for current-user defaults.
   */
  public function effectiveTimezone(): string {
    $persisted = $this->persistedTimezone();
    if ($persisted !== NULL) {
      return $persisted;
    }

    $siteDefault = trim((string) $this->configFactory
      ->get('system.date')
      ->get('timezone.default'));

    return $this->isValidTimezone($siteDefault)
      ? $siteDefault
      : self::FALLBACK_TIMEZONE;
  }

  /**
   * Returns whether progressive browser suggestion may preselect a form.
   */
  public function mayUseBrowserSuggestion(): bool {
    return $this->persistedTimezone() === NULL;
  }

  /**
   * Validates a timezone against Drupal Core's canonical IANA option list.
   */
  public function isValidTimezone(string $timezone): bool {
    return $timezone !== '' && isset(TimeZoneFormHelper::getOptionsList()[$timezone]);
  }

}
