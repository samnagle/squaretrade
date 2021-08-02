<?php

namespace Drupal\tmgmt_lokalise\Plugin\tmgmt\Translator;

use Drupal\tmgmt\ContinuousTranslatorInterface;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\Translator\AvailableResult;
use Drupal\tmgmt\TranslatorInterface;
use Drupal\tmgmt\TranslatorPluginBase;

/**
 * @TranslatorPlugin(
 *   id = "lokalise",
 *   label = @Translation("Lokalise"),
 *   description = @Translation("Lokalise translation service."),
 *   ui = "Drupal\tmgmt_lokalise\LokaliseTranslatorUi",
 * )
 */
class LokaliseTranslator extends TranslatorPluginBase implements ContinuousTranslatorInterface {

  /**
   * {@inheritdoc}
   */
  public function checkAvailable(TranslatorInterface $translator): AvailableResult {
    if ($this->getSupportedRemoteLanguages($translator)) {
      return AvailableResult::yes();
    }

    return AvailableResult::no(t('@translator is not available. Make sure it is properly <a href=:configured>configured</a>.', [
      '@translator' => $translator->label(),
      ':configured' => $translator->toUrl()->toString(),
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function getSupportedRemoteLanguages(TranslatorInterface $translator): array {
    $languages = \Drupal::service('tmgmt_lokalise.service')->getLanguages();

    return array_combine(
      array_column($languages, 'lang_iso'),
      array_map(static function ($language) {
        $iso = $language['lang_iso'];
        $name = $language['lang_name'];

        return "$name ($iso)";
      }, $languages),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function requestTranslation(JobInterface $job): void {
    $job = $this->requestJobItemsTranslation($job->getItems());

    // The translation job has been successfully submitted.
    $job->submitted();
  }

  /**
   * @param array $job_items
   *
   * @return \Drupal\tmgmt\JobInterface
   */
  public function requestJobItemsTranslation(array $job_items): JobInterface {
    /** @var \Drupal\tmgmt\Entity\Job $job */
    $job = reset($job_items)->getJob();

    if ($job->isRejected()) {
      // Change the status to Unprocessed to allow submit again.
      $job->setState(Job::STATE_UNPROCESSED);
    }

    // Execute export.
    \Drupal::service('tmgmt_lokalise.export')->execute($job);

    return $job;
  }

}
