<?php

declare(strict_types=1);

namespace Drupal\Tests\personal_secretary\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Proves empty Household management screens lead to existing setup actions.
 *
 * @group personal_secretary
 */
final class HouseholdEmptyStateNavigationTest extends BrowserTestBase {

  protected static $modules = ['block', 'personal_secretary'];

  protected $defaultTheme = 'olivero';

  public function testEmptyHouseholdManagementHasARecoveryPath(): void {
    $this->drupalGet('/personal-secretary/households/members/add');
    $this->assertSession()->statusCodeEquals(403);

    $admin = $this->drupalCreateUser(['administer personal secretary domain']);
    $this->drupalLogin($admin);

    $this->drupalGet('/personal-secretary/households/members/rename');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No Household member is available to rename.');
    $this->assertSession()->buttonNotExists('Rename household member');
    $this->assertSession()->linkByHrefExists('/personal-secretary/households/members/add');

    $this->drupalGet('/personal-secretary/account/person');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('No Household member is available to link.');
    $this->assertSession()->buttonNotExists('Link my account to household member');
    $this->clickLink('Add household member');

    $this->assertSession()->addressEquals('/personal-secretary/households/members/add');
    $this->assertSession()->pageTextContains('No Household is available yet.');
    $this->assertSession()->buttonNotExists('Add household member');
    $this->assertSession()->linkByHrefExists('/personal-secretary/setup');

    $this->clickLink('Set up your first activity');
    $this->assertSession()->addressEquals('/personal-secretary/setup');
    $this->assertSession()->statusCodeEquals(200);
  }

}
