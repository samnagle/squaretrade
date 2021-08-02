<?php

namespace Drupal\tmgmt_lokalise\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\tmgmt\Data;
use Drupal\tmgmt\JobInterface;
use Lokalise\LokaliseApiClient;

/**
 * Class Import
 *
 * @package Drupal\tmgmt_lokalise\Service
 */
class Import {

  private const DESTINATION = 'temporary://';

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
   * @var \Drupal\Core\File\FileSystemInterface
   */
  private FileSystemInterface $fileSystem;

  /**
   * Import constructor.
   *
   * @param \Drupal\tmgmt_lokalise\Service\Service $lokalise
   * @param \Drupal\tmgmt\Data $data
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   */
  public function __construct(Service $lokalise, Data $data, FileSystemInterface $fileSystem) {
    $this->translator = $lokalise->getTranslator();
    $this->client = $lokalise->getClient();
    $this->data = $data;
    $this->fileSystem = $fileSystem;
  }

  /**
   * @param \Drupal\tmgmt\JobInterface $job
   */
  public function execute(JobInterface $job): void {
    try {
      // Get properties linked to the job.
      $target_language = $this->translator->mapToRemoteLanguage($job->getTargetLangcode());
      $project_id = $job->getReference();

      // Get project files information.
      $content = $this->client->files->download($project_id, [
        'format' => 'json',
      ])->getContent();

      // Load translated data.
      $data = $this->getTargetLanguageData($project_id, $content['bundle_url'], $target_language);

      // Massage data to a format we can work with.
      $data = array_map(static function ($item) {
        return ['#text' => $item];
      }, $this->flatten($data));

      // Unflatten and add translations to job.
      $job->addTranslatedData($this->data->unflatten($data));
    }
    catch (\Exception $e) {
      watchdog_exception('lokalise_translation_provider', $e);
    }
  }

  /**
   * @param string $project_id
   * @param string $url
   * @param string $language
   *
   * @return array
   * @throws \JsonException
   */
  private function getTargetLanguageData(string $project_id, string $url, string $language): array {
    // Download zip file to tmp folder.
    $file = system_retrieve_file($url, self::DESTINATION);

    // Open zip file and extract contents of language file.
    $zip = new \ZipArchive();
    $zip->open($this->fileSystem->realpath($file));
    $data = $zip->getFromName("$language/$project_id-$language.json");

    // Return data in array format.
    return json_decode($data, TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * @param $array
   * @param string $prefix
   *
   * @return array
   */
  private function flatten($array, string $prefix = ''): array {
    $result = [];
    foreach ($array as $key => $value) {
      if (is_array($value)) {
        $result += $this->flatten($value, $prefix . $key . '][');
      }
      else {
        $result[$prefix . $key] = $value;
      }
    }
    return $result;
  }

}
