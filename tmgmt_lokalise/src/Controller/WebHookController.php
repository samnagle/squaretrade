<?php

namespace Drupal\tmgmt_lokalise\Controller;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Access\AccessResultAllowed;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\tmgmt_lokalise\Service\Helper;
use Drupal\tmgmt_lokalise\Service\Import;
use Drupal\tmgmt_lokalise\Service\Service;
use Drupal\tmgmt_lokalise\Settings;
use Lokalise\LokaliseApiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class WebHookController
 *
 * @package Drupal\tmgmt_lokalise\Controller
 */
class WebHookController extends ControllerBase {

  private const IP_RANGE = [
    '159.69.72.82',
    '94.130.129.39',
    '195.201.158.210',
    '94.130.129.237',
  ];

  /**
   * @var \Lokalise\LokaliseApiClient
   */
  private LokaliseApiClient $client;

  /**
   * @var \Drupal\tmgmt_lokalise\Service\Helper
   */
  private Helper $helper;

  /**
   * @var \Drupal\tmgmt_lokalise\Service\Import
   */
  private Import $import;

  /**
   * WebHookController constructor.
   *
   * @param \Drupal\tmgmt_lokalise\Service\Service $lokalise
   * @param \Drupal\tmgmt_lokalise\Service\Helper $helper
   * @param \Drupal\tmgmt_lokalise\Service\Import $import
   */
  public function __construct(Service $lokalise, Helper $helper, Import $import) {
    $this->client = $lokalise->getClient();
    $this->helper = $helper;
    $this->import = $import;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tmgmt_lokalise.service'),
      $container->get('tmgmt_lokalise.helper'),
      $container->get('tmgmt_lokalise.import'),
    );
  }

  /**
   * @return \Drupal\Core\Access\AccessResultInterface
   */
  public function access(): AccessResultInterface {
    $ip_check = in_array(\Drupal::request()
      ->getClientIp(), self::IP_RANGE, FALSE);

    return AccessResultAllowed::allowedIf($ip_check);
  }

  /**
   * @param \Symfony\Component\HttpFoundation\Request $request
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   * @throws \Drupal\tmgmt\TMGMTException
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function process(Request $request): JsonResponse {
    $data = Json::decode($request->getContent());

    // Project ID not provided.
    if (!isset($data['project']['id'])) {
      return new JsonResponse(['Invalid Project ID']);
    }

    // Find the job based on project ID.
    $project_id = $data['project']['id'];
    if ($job = $this->helper->findJob($project_id)) {
      // Import translated strings.
      $this->import->execute($job);

      // Delete Lokalise project if configured that way.
      if ($job->getTranslator()->getSetting(Settings::DELETE_FINISHED)) {
        $this->client->projects->delete($project_id);
      }
    }

    return new JsonResponse(['OK']);
  }

}
