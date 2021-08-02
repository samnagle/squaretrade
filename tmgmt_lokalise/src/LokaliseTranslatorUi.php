<?php

namespace Drupal\tmgmt_lokalise;

use Drupal\Core\Form\FormStateInterface;
use Drupal\tmgmt\Form\TranslatorForm;
use Drupal\tmgmt\JobInterface;
use Drupal\tmgmt\TranslatorPluginUiBase;
use Drupal\tmgmt_lokalise\Service\Helper;
use Lokalise\LokaliseApiClient;

/**
 * Class LokaliseTranslatorUi
 *
 * @package Drupal\tmgmt_lokalise
 */
class LokaliseTranslatorUi extends TranslatorPluginUiBase {

  /**
   * @var \Drupal\tmgmt_lokalise\Service\Helper
   */
  private Helper $helper;

  /**
   * LokaliseTranslatorUi constructor.
   *
   * @param array $configuration
   * @param $plugin_id
   * @param $plugin_definition
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->helper = \Drupal::service('tmgmt_lokalise.helper');
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildConfigurationForm($form, $form_state);

    $api_token = $this->getSettingValue($form_state, Settings::API_TOKEN);
    $form[Settings::API_TOKEN] = [
      '#type' => 'textfield',
      '#required' => TRUE,
      '#title' => t('API token'),
      '#default_value' => $api_token,
      '#description' => t("Please enter your personal Lokalise API key. You can find it <a href=:api_token_url  target='_blank'>here</a>", [
        ':api_token_url' => 'https://app.lokalise.com/profile',
      ]),
    ];

    $form += $this->addConnectButton();
    $form['connect']['#limit_validation_errors'] = [];

    try {
      $client = new LokaliseApiClient($api_token);

      $teams = $client->teams->fetchAll()->getContent()['teams'];
      $team_id = $this->getSettingValue($form_state, Settings::TEAM_ID);

      $form_state->setTemporaryValue('api_token', $api_token);
      $form_state->setTemporaryValue('team_id', $team_id);

      $form[Settings::TEAM_ID] = [
        '#type' => 'select',
        '#required' => TRUE,
        '#title' => t('Team'),
        '#description' => t('In what team should the translations be made?'),
        '#default_value' => $team_id,
        '#options' => array_combine(
          array_column($teams, 'team_id'),
          array_column($teams, 'name')
        ),
      ];

      $download = $this->getSettingValue($form_state, Settings::DOWNLOAD_WHEN_READY);
      $form[Settings::DOWNLOAD_WHEN_READY] = [
        '#type' => 'checkbox',
        '#title' => t('Create order & download translations when ready'),
        '#description' => t('By default this will fully automate the export/import process. A manual download action is needed for each job when unchecked.'),
        '#default_value' => $download,
        '#ajax' => [
          'callback' => [TranslatorForm::class, 'ajaxTranslatorPluginSelect'],
          'wrapper' => 'tmgmt-plugin-wrapper',
        ],
      ];

      $cards = $client->paymentCards->fetchAll()->getContent()['payment_cards'];
      if (empty($cards)) {
        $form[Settings::DOWNLOAD_WHEN_READY] = array_merge($form[Settings::DOWNLOAD_WHEN_READY], [
          '#description' => t("No payment cards found, you'll have to use the credit card to pay for one order (above $0.50) in the Lokalise UI."),
          '#disabled' => TRUE,
        ]);
      }

      if (!$download) {
        return $form;
      }

      $delete_finished = $this->getSettingValue($form_state, Settings::DELETE_FINISHED);
      $form[Settings::DELETE_FINISHED] = [
        '#type' => 'checkbox',
        '#title' => t('Delete Lokalise project when finished'),
        '#default_value' => $delete_finished,
      ];

      $review_in_lokalise = $this->getSettingValue($form_state, Settings::REVIEW_IN_LOKALISE);
      $form[Settings::REVIEW_IN_LOKALISE] = [
        '#type' => 'checkbox',
        '#title' => t('Review translations in Lokalise'),
        '#description' => t('When the review task is closed, translations will be imported automatically.'),
        '#default_value' => $review_in_lokalise,
        '#ajax' => [
          'callback' => [TranslatorForm::class, 'ajaxTranslatorPluginSelect'],
          'wrapper' => 'tmgmt-plugin-wrapper',
        ],
      ];

      $card_id = $this->getSettingValue($form_state, Settings::CARD_ID);
      $form[Settings::CARD_ID] = [
        '#type' => 'select',
        '#required' => TRUE,
        '#title' => t('Card'),
        '#description' => t('Which card should be used to pay the orders?'),
        '#default_value' => $card_id,
        '#options' => array_combine(
          array_column($cards, 'card_id'),
          array_map(static function ($card) {
            $brand = $card['brand'];
            $last = $card['last4'];

            return "$brand (···· ···· ···· $last)";
          }, $cards)
        ),
      ];

      $provider = $this->getSettingValue($form_state, Settings::PROVIDER);
      $form[Settings::PROVIDER] = [
        '#type' => 'select',
        '#required' => TRUE,
        '#title' => t('Default translation provider'),
        '#default_value' => $provider,
        '#options' => $this->helper->getProviders(),
      ];

      /** @var \Drupal\tmgmt\TranslatorInterface $translator */
      $translator = $form_state->getFormObject()->getEntity();
      $languages = $this->getSettingValue($form_state, Settings::LANGUAGES);
      $form['languages'] = [];

      $remote_languages = $translator->getSupportedRemoteLanguages();
      foreach ($translator->getRemoteLanguagesMappings() as $language_id => $remote_id) {
        /** @var \Drupal\Core\Language\LanguageInterface $language */
        $language = \Drupal::languageManager()->getLanguage($language_id);

        $remote_id = $languages[$language_id]['remote'] ?? $remote_id;
        $form['languages'][$language->getId()] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['container-inline']],
          'remote' => [
            '#type' => 'select',
            '#title' => $language->getName() . ' (' . $language->getId() . ')',
            '#description' => t('Remote'),
            '#options' => $remote_languages,
            '#default_value' => $remote_id,
            '#ajax' => [
              'callback' => [
                TranslatorForm::class,
                'ajaxTranslatorPluginSelect',
              ],
              'wrapper' => 'tmgmt-plugin-wrapper',
            ],
          ],
          'provider' => [
            '#type' => 'select',
            '#title' => '',
            '#description' => t('Provider'),
            '#empty_option' => t('Use default'),
            '#options' => $this->helper->getProviders($remote_id),
            '#default_value' => $languages[$language->getId()]['provider'] ?? NULL,
          ],
          'group' => [
            '#type' => 'select',
            '#access' => (bool) $review_in_lokalise,
            '#title' => '',
            '#description' => t('Group'),
            '#options' => $this->helper->getGroups($remote_id),
            '#default_value' => $languages[$language->getId()]['group'] ?? NULL,
          ],
        ];
      }
    }
    catch (\Exception $e) {
      watchdog_exception('lokalise_translation_provider', $e);
    }

    return $form;
  }

  /**
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   * @param string $setting
   *
   * @return string|array|NULL
   */
  private function getSettingValue(FormStateInterface $form_state, string $setting): string|array|NULL {
    $values = $form_state->getValues();
    $translator = $form_state->getFormObject()->getEntity();

    return $values['settings'][$setting] ?? $translator->getSetting($setting);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state): void {
    parent::validateConfigurationForm($form, $form_state);
    if ($form_state->hasAnyErrors()) {
      return;
    }

    $form_state->cleanValues();
    $values = $form_state->getValues();
    $api_token = $values['settings'][Settings::API_TOKEN];

    try {
      $client = new LokaliseApiClient($api_token);
      $client->languages->fetchAllSystem();
    }
    catch (\Exception $e) {
      watchdog_exception('lokalise_translation_provider', $e);
      $form_state->setErrorByName('settings][service_url', t('Authentication failed. Please check the API token.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkoutInfo(JobInterface $job): array {
    return [
      'job' => [
        '#type' => 'value',
        '#value' => $job,
      ],
      'download' => [
        '#type' => 'submit',
        '#value' => t('Download translations'),
        '#submit' => [[$this, 'download']],
      ],
    ];
  }

  /**
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   */
  public function download(array $form, FormStateInterface $form_state): void {
    $form_state->cleanValues();
    $job = $form_state->getValue('job');

    // Import Lokalise data to job.
    \Drupal::service('tmgmt_lokalise.import')->execute($job);
  }

}
