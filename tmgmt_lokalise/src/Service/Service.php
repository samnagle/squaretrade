<?php

namespace Drupal\tmgmt_lokalise\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\tmgmt_lokalise\Settings;
use Lokalise\LokaliseApiClient;

/**
 * Class Service
 *
 * @package Drupal\tmgmt_lokalise\Service
 */
class Service {

  /**
   * @var \Drupal\Core\Entity\EntityInterface|NULL
   */
  private EntityInterface|NULL $translator;

  /**
   * @var \Lokalise\LokaliseApiClient
   */
  private LokaliseApiClient $client;

  /**
   * @var string
   */
  private string $teamId;

  /**
   * @var array
   */
  private array $languages;

  /**
   * @var array
   */
  private array $providers;

  /**
   * @var array
   */
  private array $groups;

  /**
   * Service constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->translator = $entityTypeManager->getStorage('tmgmt_translator')
      ->load('lokalise');
    $this->client = new LokaliseApiClient($this->translator->getSetting(Settings::API_TOKEN));
    $this->teamId = $this->translator->getSetting(Settings::TEAM_ID);
  }

  /**
   * @param string $label
   * @param string $target_language
   *
   * @return string
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function createProject(string $label, string $target_language): string {
    $project_id = $this->client->projects->create([
      'name' => $label,
    ])->getContent()['project_id'];

    // Add target language to project.
    $this->client->languages->create($project_id, ['languages' => [['lang_iso' => $target_language]]]);

    return $project_id;
  }

  /**
   * @param string $project_id
   * @param string $source_language
   * @param array $data
   *
   * @return string
   * @throws \JsonException
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function uploadProjectData(string $project_id, string $source_language, array $data): string {
    // Send file content to Lokalise.
    $process_id = $this->client->files->upload($project_id, [
      'data' => base64_encode(json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
      'filename' => "$project_id-$source_language.json",
      'lang_iso' => $source_language,
      'apply_tm' => TRUE,
      'use_automations' => TRUE,
    ])->getContent()['process']['process_id'];

    do {
      // Wait for upload to be finished.
      $process = $this->client->queuedProcesses->retrieve($project_id, $process_id)
        ->getContent()['process'];
    } while ($process['status'] !== 'finished');

    return $process_id;
  }

  /**
   * @param string $project_id
   * @param string $label
   * @param string $source_language
   * @param string $target_language
   * @param array $keys
   * @param string $provider
   *
   * @return string
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function createOrder(string $project_id, string $label, string $source_language, string $target_language, array $keys, string $provider): string {
    return $this->client->orders->create($this->teamId, [
      'project_id' => $project_id,
      'card_id' => $this->translator->getSetting(Settings::CARD_ID),
      'briefing' => $label,
      'source_language_iso' => $source_language,
      'target_language_isos' => [$target_language],
      'keys' => $keys,
      'provider_slug' => $provider,
      'translation_tier' => 1,
    ])->getContent()['order_id'];
  }

  /**
   * @param string $project_id
   * @param string $target_language
   * @param string $group_id
   * @param array $keys
   *
   * @return string
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function createTask(string $project_id, string $target_language, string $group_id, array $keys): string {
    return $this->client->tasks->create($project_id, [
      'title' => 'Review all translations',
      'languages' => [
        [
          'language_iso' => $target_language,
          'groups' => [$group_id],
        ],
      ],
      'task_type' => 'review',
      'keys' => $keys,
    ])->getContent()['task']['task_id'];
  }

  /**
   * @param string $project_id
   * @param string $group_id
   *
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function addProjectToGroup(string $project_id, string $group_id): void {
    $this->client->teamUserGroups->addProjects($this->teamId, $group_id, [
      'projects' => [$project_id],
    ]);
  }

  /**
   * @param string $project_id
   * @param string $route
   * @param array $events
   *
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function createWebhook(string $project_id, string $route, array $events): void {
    $this->client->webhooks->create($project_id, [
      'url' => Url::fromRoute("tmgmt_lokalise.$route")
        ->setAbsolute()
        ->toString(),
      'events' => $events,
    ]);
  }

  /**
   * @return \Drupal\Core\Entity\EntityInterface|NULL
   */
  public function getTranslator(): ?EntityInterface {
    return $this->translator;
  }

  /**
   * @return \Lokalise\LokaliseApiClient
   */
  public function getClient(): LokaliseApiClient {
    return $this->client;
  }

  /**
   * @return mixed
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function getLanguages(): mixed {
    if (empty($this->languages)) {
      // Static cache languages.
      $this->languages = $this->client->languages->fetchAllSystem()
        ->getContent()['languages'];
    }

    return $this->languages;
  }

  /**
   * @return array
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function getProviders(): array {
    if (empty($this->providers)) {
      // Static cache providers.
      $this->providers = array_map(function ($provider_id) {
        return $this->client->translationProviders->retrieve($this->teamId, $provider_id)
          ->getContent();
      }, array_column($this->client->translationProviders->fetchAll($this->teamId)
        ->getContent()['translation_providers'], 'provider_id'));
    }

    return $this->providers;
  }

  /**
   * @return mixed
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function getGroups(): mixed {
    if (empty($this->groups)) {
      // Static cache groups.
      $this->groups = $this->client->teamUserGroups->fetchAll($this->teamId)
        ->getContent()['user_groups'];
    }

    return $this->groups;
  }

}
