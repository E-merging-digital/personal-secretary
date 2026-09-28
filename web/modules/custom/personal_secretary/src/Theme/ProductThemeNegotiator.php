<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Theme;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Theme\ThemeNegotiatorInterface;

final class ProductThemeNegotiator implements ThemeNegotiatorInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  public function applies(RouteMatchInterface $route_match): bool {
    $routeName = $route_match->getRouteName();
    if (!is_string($routeName) || !str_starts_with($routeName, 'personal_secretary.')) return FALSE;
    $themes = $this->configFactory->get('core.extension')->get('theme');
    return is_array($themes) && array_key_exists('personal_secretary_product', $themes);
  }

  public function determineActiveTheme(RouteMatchInterface $route_match): ?string {
    return $this->applies($route_match) ? 'personal_secretary_product' : NULL;
  }

}
