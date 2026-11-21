<?php

declare(strict_types=1);

namespace Drupal\sso_dashboards\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\sso_dashboards\Service\CountryInfoSoapClient;
use Drupal\sso_dashboards\Service\JsonPlaceholderClient;
use Drupal\sso_dashboards\Service\UpstreamException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the member and exhibitor dashboards.
 */
final class DashboardController extends ControllerBase implements TrustedCallbackInterface {

  public function __construct(
    private readonly JsonPlaceholderClient $restClient,
    private readonly CountryInfoSoapClient $soapClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('sso_dashboards.rest_client'),
      $container->get('sso_dashboards.soap_client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['buildMemberContent', 'buildExhibitorContent'];
  }

  /**
   * Builds the member dashboard.
   */
  public function member(): array {
    return $this->buildDashboard('member', 'buildMemberContent');
  }

  /**
   * Builds the exhibitor dashboard.
   */
  public function exhibitor(): array {
    return $this->buildDashboard('exhibitor', 'buildExhibitorContent');
  }

  /**
   * Fetches and renders the sample user.
   */
  public function buildMemberContent(): array {
    $build = $this->buildContent($this->t('Member'));
    try {
      $user = $this->restClient->getUser();
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
  public function buildExhibitorContent(): array {
    $build = $this->buildContent($this->t('Exhibitor'));
    try {
      $rows = [];
      foreach ($this->soapClient->getCountries() as $country) {
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
   * Creates a lazily rendered dashboard with per-user cache keys.
   */
  private function buildDashboard(string $role, string $callback): array {
    return [
      'content' => [
        '#lazy_builder' => [self::class . '::' . $callback, []],
        '#create_placeholder' => TRUE,
        '#cache' => [
          'keys' => ['sso_dashboards', $role],
          'contexts' => ['user'],
          'tags' => ['config:sso_dashboards.settings'],
          'max-age' => max(0, (int) $this->config('sso_dashboards.settings')->get('cache_max_age')),
        ],
      ],
    ];
  }

  /**
   * Builds the identity line shared by both dashboards.
   */
  private function buildContent(string|\Stringable $role): array {
    $account = $this->currentUser();
    return [
      '#cache' => ['tags' => ['user:' . $account->id()]],
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
    $build['#cache']['max-age'] = 0;
    $build['data'] = ['#plain_text' => $this->t('The service is currently unavailable. Please try again later.')];
  }

}
