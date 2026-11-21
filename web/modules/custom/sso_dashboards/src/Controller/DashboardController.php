<?php

declare(strict_types=1);

namespace Drupal\sso_dashboards\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\sso_dashboards\Service\DashboardData;
use Drupal\sso_dashboards\Service\UpstreamException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the member and exhibitor dashboards.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly DashboardData $dashboardData,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('sso_dashboards.data'),
    );
  }

  /**
   * Fetches and renders the sample user.
   */
  public function member(): array {
    $build = $this->buildContent($this->t('Member'));
    try {
      $user = $this->dashboardData->getUser();
      $build['data'] = ['#type' => 'html_tag', '#tag' => 'dl'];
      $fields = [
        'name' => [$this->t('Name'), $user['name']],
        'email' => [$this->t('Email'), $user['email']],
        'company' => [$this->t('Company'), $user['company']['name']],
      ];
      foreach ($fields as $key => [$label, $value]) {
        $build['data'][$key . '_label'] = ['#type' => 'html_tag', '#tag' => 'dt', 'text' => ['#plain_text' => $label]];
        $build['data'][$key] = ['#type' => 'html_tag', '#tag' => 'dd', 'text' => ['#plain_text' => $value]];
      }
    }
    catch (UpstreamException $exception) {
      $this->addUpstreamError($build, $exception);
    }
    return $build;
  }

  /**
   * Fetches and renders the country list.
   */
  public function exhibitor(): array {
    $build = $this->buildContent($this->t('Exhibitor'));
    try {
      $rows = [];
      foreach ($this->dashboardData->getCountries() as $country) {
        $rows[] = [
          ['data' => ['#plain_text' => $country['name']]],
          ['data' => ['#plain_text' => $country['isoCode']]],
        ];
      }
      $build['data'] = [
        '#type' => 'table',
        '#header' => [$this->t('Country'), $this->t('ISO code')],
        '#rows' => $rows,
      ];
    }
    catch (UpstreamException $exception) {
      $this->addUpstreamError($build, $exception);
    }
    return $build;
  }

  /**
   * Builds the identity line shared by both dashboards.
   */
  private function buildContent(string|\Stringable $role): array {
    $account = $this->currentUser();
    return [
      // The upstream data has its own shared cache. Render the identity
      // and dashboard on each request using the current account.
      '#cache' => ['contexts' => ['user'], 'max-age' => 0],
      'identity' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('Signed in as @name (@role)', [
          '@name' => $account->getAccountName(),
          '@role' => $role,
        ]),
      ],
    ];
  }

  /**
   * Logs the failure and adds an uncached error message.
   */
  private function addUpstreamError(array &$build, UpstreamException $exception): void {
    $this->getLogger('sso_dashboards')->warning('@message', ['@message' => $exception->getMessage()]);
    $build['data'] = ['#plain_text' => $this->t('The service is currently unavailable. Please try again later.')];
  }

}
