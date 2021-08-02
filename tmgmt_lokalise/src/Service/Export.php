<?php

namespace Drupal\tmgmt_lokalise\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\tmgmt\Data;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt_lokalise\Settings;
use Lokalise\LokaliseApiClient;

/**
 * Class Export
 *
 * @package Drupal\tmgmt_lokalise\Service
 */
class Export {

  private const HOOK_ORDER_COMPLETED = 'team.order.completed';

  private const HOOK_TASK_CLOSED = 'project.task.closed';

  /**
   * @var \Drupal\tmgmt_lokalise\Service\Service
   */
  private Service $lokalise;

  /**
   * @var \Drupal\Core\Entity\EntityInterface|NULL
   */
  private ?EntityInterface $translator;

  /**
   * @var \Lokalise\LokaliseApiClient
   */
  private LokaliseApiClient $client;

  /**
   * @var \Drupal\tmgmt\Data
   */
  private Data $data;

  /**
   * Export constructor.
   *
   * @param \Drupal\tmgmt_lokalise\Service\Service $lokalise
   * @param \Drupal\tmgmt\Data $data
   */
  public function __construct(Service $lokalise, Data $data) {
    $this->lokalise = $lokalise;
    $this->translator = $lokalise->getTranslator();
    $this->client = $lokalise->getClient();
    $this->data = $data;
  }

  /**
   * @param \Drupal\tmgmt\JobInterface $job
   */
  public function execute(JobInterface $job): void {
    try {
      // Load source and target languages for this job.
      $source_language = $this->translator->mapToRemoteLanguage($job->getSourceLangcode());
      $target_language = $this->translator->mapToRemoteLanguage($job->getTargetLangcode());

      // Create project and set target language.
      $label = $job->get('label')->value ?: 'Drupal TMGMT project ' . $job->id();
      $project_id = $this->lokalise->createProject($label, $target_language);
      $job->addMessage('Created a new Project in Lokalise with the id: @id', ['@id' => $project_id], 'debug');

      // Upload source data.
      $this->lokalise->uploadProjectData($project_id, $source_language, $this->buildData($job, $project_id));

      // Set job external reference.
      $job->reference = $project_id;

      if ($this->translator->getSetting(Settings::DOWNLOAD_WHEN_READY)) {
        $keys = array_column($this->client->keys->fetchAll($project_id)
          ->getContent()['keys'], 'key_id');

        // Get provider settings.
        $languages = $this->translator->getSetting(Settings::LANGUAGES);
        $default_provider = $this->translator->getSetting(Settings::PROVIDER);
        $provider = $languages[$job->getTargetLangcode()]['provider'] ?: $default_provider;

        // Create order webhook before the order itself.
        if (!$this->translator->getSetting(Settings::REVIEW_IN_LOKALISE)) {
          $this->lokalise->createWebhook($project_id, 'team_order_completed', [self::HOOK_ORDER_COMPLETED]);
        }

        // Create the order.
        $this->lokalise->createOrder($project_id, $label, $source_language, $target_language, $keys, $provider);

        if ($this->translator->getSetting(Settings::REVIEW_IN_LOKALISE)) {
          $group_id = $languages[$job->getTargetLangcode()]['group'];

          // Add project to group.
          $this->lokalise->addProjectToGroup($project_id, $group_id);

          // Create a webhook to get notified when the task if finished.
          $this->lokalise->createWebhook($project_id, 'project_task_closed', [self::HOOK_TASK_CLOSED]);

          // Create the task.
          $this->lokalise->createTask($project_id, $target_language, $group_id, $keys);
        }
      }
    }
    catch (\Exception $e) {
      watchdog_exception('lokalise_translation_provider', $e);
    }
  }

  /**
   * @param \Drupal\tmgmt\JobInterface $job
   * @param string $project_id
   *
   * @return array
   * @throws \Drupal\tmgmt\TMGMTException
   */
  private function buildData(JobInterface $job, string $project_id): array {
    $items = $job->getItems();
    $data_flat = array_filter($this->data->flatten($job->getData()), [
      $this->data,
      'filterData',
    ]);

    $translatable_content = [];
    foreach ($data_flat as $key => $value) {
      [$tjiid, $data_item_key] = explode('][', $key, 2);

      // Preserve the original data.
      $items[$tjiid]->addRemoteMapping($data_item_key, $project_id);

      // This is the actual text sent to Lokalise for translation.
      $translatable_content[$tjiid][$data_item_key] = $value['#text'];
    }

    return $translatable_content;
  }

}
