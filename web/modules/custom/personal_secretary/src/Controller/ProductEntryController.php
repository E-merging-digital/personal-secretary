<?php

declare(strict_types=1);

namespace Drupal\personal_secretary\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\personal_secretary\Service\HouseholdAuthorizationService;
use Symfony\Component\HttpFoundation\RedirectResponse;

final class ProductEntryController extends ControllerBase {

  public function entry(): RedirectResponse {
    if ($this->currentUser()->isAnonymous()) {
      return new RedirectResponse(Url::fromRoute('user.login')->toString());
    }
    if (!$this->currentUser()->hasPermission(HouseholdAuthorizationService::PRODUCT_USE_PERMISSION)) {
      return new RedirectResponse(Url::fromRoute('entity.user.canonical', ['user' => (int) $this->currentUser()->id()])->toString());
    }
    return new RedirectResponse(Url::fromRoute('personal_secretary.today')->toString());
  }

}
