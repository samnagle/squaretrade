<?php

namespace Drupal\tmgmt_lokalise\Service;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\tmgmt\JobInterface;

/**
 * Class Helper
 *
 * @package Drupal\tmgmt_lokalise\Service
 */
class Helper {

  /**
   * @var \Drupal\tmgmt_lokalise\Service\Service
   */
  private Service $lokalise;

  /**
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  private EntityStorageInterface $storage;

  /**
   * Helper constructor.
   *
   * @param \Drupal\tmgmt_lokalise\Service\Service $lokalise
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function __construct(Service $lokalise, EntityTypeManagerInterface $entityTypeManager) {
    $this->lokalise = $lokalise;
    $this->storage = $entityTypeManager->getStorage('tmgmt_job');
  }

  /**
   * @param string|NULL $language
   *
   * @return array
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function getProviders(string $language = NULL): array {
    $providers = $this->lokalise->getProviders();

    if (!empty($language)) {
      // Filter providers based on target language.
      $providers = array_filter($providers, static function ($provider) use ($language) {
        $target_languages = array_column($provider['pairs'], 'to_lang_iso');
        return in_array($language, $target_languages, FALSE);
      });
    }

    // Use slug as provider key.
    return array_combine(
      array_column($providers, 'slug'),
      array_column($providers, 'name'),
    );
  }

  /**
   * @param string|NULL $language
   *
   * @return array
   * @throws \Lokalise\Exceptions\LokaliseApiException
   * @throws \Lokalise\Exceptions\LokaliseResponseException
   */
  public function getGroups(string $language = NULL): array {
    $groups = $this->lokalise->getGroups();

    if (!empty($language)) {
      // Filter groups based on target language.
      $groups = array_filter($groups, static function ($group) use ($language) {
        $languages = array_filter($group['permissions']['languages'], static function ($language) {
          return $language['is_writable'];
        });

        $target_languages = array_column($languages, 'lang_iso');
        return in_array($language, $target_languages, FALSE);
      });
    }

    return array_combine(
      array_column($groups, 'group_id'),
      array_column($groups, 'name'),
    );
  }

  /**
   * @param string $reference
   *
   * @return \Drupal\tmgmt\JobInterface|NULL
   */
  public function findJob(string $reference): ?JobInterface {
    $jobs = $this->storage->loadByProperties([
      'reference' => $reference,
    ]);

    return $jobs ? reset($jobs) : NULL;
  }

}
