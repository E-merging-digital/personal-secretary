<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @Block(
 *   id = "personal_secretary_product_shell",
 *   admin_label = @Translation("Personal Secretary product shell")
 * )
 */
final class ProductShellBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly AccountInterface $currentUser,
    private readonly LanguageManagerInterface $languageManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('language_manager'),
      $container->get('current_route_match'),
    );
  }

  public function build(): array {
    $routeName = (string) $this->routeMatch->getRouteName();
    $currentLanguage = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE);
    $fr = $this->languageManager->getLanguage('fr');
    $en = $this->languageManager->getLanguage('en');
    if ($fr === NULL || $en === NULL) throw new \RuntimeException('Personal Secretary product shell requires configured French and English languages.');

    $siteAdmin = $this->currentUser->hasPermission('access administration pages');
    $operator = $this->currentUser->hasPermission(HouseholdAuthorizationService::ADMIN_PERMISSION);
    $posture = $siteAdmin ? 'site-admin' : ($operator ? 'product-operator' : 'product-user');

    return [
      '#type' => 'component',
      '#component' => 'personal_secretary_product:product-header',
      '#props' => [
        'brand_label' => 'Personal Secretary',
        'home_url' => Url::fromRoute('personal_secretary.today')->toString(),
        'menu_label' => (string) $this->t('Menu'),
        'navigation_label' => (string) $this->t('Navigation'),
        'today_label' => (string) $this->t('Today'),
        'today_url' => Url::fromRoute('personal_secretary.today')->toString(),
        'today_active' => $routeName === 'personal_secretary.today',
        'tasks_label' => (string) $this->t('Tasks'),
        'tasks_url' => Url::fromRoute('personal_secretary.my_tasks')->toString(),
        'tasks_active' => $this->isTaskRoute($routeName),
        'activities_label' => (string) $this->t('Activities'),
        'activities_url' => Url::fromRoute('personal_secretary.my_upcoming')->toString(),
        'activities_active' => $this->isActivityRoute($routeName),
        'preparations_label' => (string) $this->t('Preparations'),
        'preparations_url' => Url::fromRoute('personal_secretary.my_preparations')->toString(),
        'preparations_active' => $this->isPreparationRoute($routeName),
        'calendar_label' => 'Google Calendar',
        'calendar_url' => Url::fromRoute('personal_secretary.google_calendar_status')->toString(),
        'calendar_active' => str_starts_with($routeName, 'personal_secretary.google_calendar_'),
        'settings_label' => (string) $this->t('Settings'),
        'settings_url' => Url::fromRoute('personal_secretary.reminder_settings')->toString(),
        'settings_active' => $routeName === 'personal_secretary.reminder_settings',
        'fr_url' => Url::fromRoute('<current>', [], ['language' => $fr])->toString(),
        'en_url' => Url::fromRoute('<current>', [], ['language' => $en])->toString(),
        'current_langcode' => $currentLanguage->getId(),
        'account_label' => $this->currentUser->getDisplayName(),
        'account_url' => Url::fromRoute('entity.user.edit_form', ['user' => (int) $this->currentUser->id()])->toString(),
        'operator_label' => $operator ? (string) $this->t('Manage Household access') : '',
        'operator_url' => $operator ? Url::fromRoute('personal_secretary.household_access_admin')->toString() : '',
        'posture' => $posture,
      ],
      '#cache' => ['contexts' => ['languages:language_interface', 'route', 'user', 'user.permissions']],
    ];
  }

  private function isTaskRoute(string $routeName): bool {
    return in_array($routeName, [
      'personal_secretary.my_tasks', 'personal_secretary.add_task', 'personal_secretary.task_status',
      'personal_secretary.edit_task', 'personal_secretary.complete_task', 'personal_secretary.reopen_task',
      'personal_secretary.delete_task',
    ], TRUE);
  }

  private function isActivityRoute(string $routeName): bool {
    return in_array($routeName, [
      'personal_secretary.upcoming', 'personal_secretary.my_upcoming', 'personal_secretary.occurrence_detail',
      'personal_secretary.add_activity', 'personal_secretary.edit_recurring_schedule', 'personal_secretary.edit_recurring_responsibility',
      'personal_secretary.edit_time_commitment', 'personal_secretary.pause_recurring_activity',
      'personal_secretary.responsibility_occurrence', 'personal_secretary.reschedule_occurrence',
      'personal_secretary.cancel_occurrence',
    ], TRUE);
  }

  private function isPreparationRoute(string $routeName): bool {
    return in_array($routeName, [
      'personal_secretary.my_preparations', 'personal_secretary.mark_preparation_prepared',
      'personal_secretary.mark_preparation_not_prepared',
    ], TRUE);
  }

}
