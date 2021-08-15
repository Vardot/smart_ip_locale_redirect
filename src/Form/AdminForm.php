<?php

namespace Drupal\smart_ip_locale_redirect\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;

/**
 * Configure the browser language negotiation method for this site.
 */
class AdminForm extends ConfigFormBase {

  /**
   * Drupal\language\ConfigurableLanguageManagerInterface definition.
   *
   * @var \Drupal\language\ConfigurableLanguageManagerInterface
   */
  protected $languageManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->languageManager = $container->get('language_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'smart_ip_locale_redirect.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'smart_ip_locale_redirect_admin_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('smart_ip_locale_redirect.settings');

    // Initialize a language list to the ones available, including English.
    $languages = $this->languageManager->getLanguages();

    $existing_languages = [];
    foreach ($languages as $langcode => $language) {
      $existing_languages[$langcode] = $language->getName();
    }

    // If we have no languages available, present the list of predefined
    // languages only. If we do have already added languages,
    // set up list of existing languages.
    if (empty($existing_languages)) {
      $language_options = $this->languageManager->getStandardLanguageListWithoutConfigured();
    }
    else {
      $language_options = [
        (string) $this->t('Existing languages') => $existing_languages,
      ];
    }

    $form['mappings'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Country code'),
        $this->t('Site language'),
        $this->t('Operations'),
      ],
      '#attributes' => ['id' => 'language-negotiation-ip'],
      '#empty' => $this->t('No Ip language mappings available.'),
    ];

    $mappings = $this->languageGetSmartIpLangcodeMappings();
    foreach ($mappings as $country_code => $drupal_langcode) {
      $form['mappings'][$country_code] = [
        'country_code' => [
          '#title' => $this->t('Country code'),
          '#title_display' => 'invisible',
          '#type' => 'textfield',
          '#default_value' => $country_code,
          '#size' => 20,
          '#required' => TRUE,
        ],
        'drupal_langcode' => [
          '#title' => $this->t('Site language'),
          '#title_display' => 'invisible',
          '#type' => 'select',
          '#options' => $language_options,
          '#default_value' => $drupal_langcode,
          '#required' => TRUE,
        ],
      ];
      // Operations column.
      $form['mappings'][$country_code]['operations'] = [
        '#type' => 'operations',
        '#links' => [],
      ];

      $form['mappings'][$country_code]['operations']['#links']['delete'] = [
        'title' => $this->t('Delete'),
        'url' => Url::fromRoute('smart_ip_locale_redirect.negotiation_ip_delete', ['country_code' => $country_code]),
      ];
    }

    // Add empty row.
    $form['new_mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Add a new mapping'),
      '#tree' => TRUE,
    ];
    $form['new_mapping']['country_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Country code'),
      '#size' => 20,
    ];
    $form['new_mapping']['drupal_langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Site language'),
      '#options' => $language_options,
    ];
    $form['excluded_user_agents'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded user agents'),
      '#description' => $this->t('Specify user agents to be excluded from redirection. Enter one agent per line.'),
      '#default_value' => $config->get('excluded_user_agents'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // Array to check if all browser language codes are unique.
    $unique_values = [];
    $mappings = [];

    // Check all mappings.
    if ($form_state->hasValue('mappings')) {
      if ($form_state->getValue('mappings')) {
        $mappings = $form_state->getValue('mappings');
      }
      else {
        $mappings = [];
      }
      foreach ($mappings as $key => $data) {
        // Make sure country_code is unique.
        if (array_key_exists($data['country_code'], $unique_values)) {
          $form_state->setErrorByName('mappings][' . $key . '][country_code', $this->t('Country codes must be unique.'));
        }
        elseif (preg_match('/[^a-z\-]/', $data['country_code'])) {
          $form_state->setErrorByName('mappings][' . $key . '][country_code', $this->t('Country codes can only contain lowercase letters and a hyphen(-).'));
        }
        $unique_values[$data['country_code']] = $data['drupal_langcode'];
      }
    }

    // Check new mapping.
    $new_data = $form_state->getValue('new_mapping');

    if (!empty($new_data['country_code'])) {
      // Make sure country_code is unique.
      if (array_key_exists($new_data['country_code'], $unique_values)) {
        $form_state->setErrorByName('mappings][' . $new_data['country_code'] . '][country_code', $this->t('Country codes must be unique.'));
      }
      elseif (preg_match('/[^a-z\-]/', $new_data['country_code'])) {
        $form_state->setErrorByName('mappings][' . $new_data['country_code'] . '][country_code', $this->t('Country codes can only contain lowercase letters and a hyphen(-).'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $form_mappings = $form_state->getValue('mappings') ?: [];
    $mappings = [];
    foreach ($form_mappings as $form_mapping) {
      $mappings[$form_mapping['country_code']] = $form_mapping['drupal_langcode'];
    }
    $new_mapping = $form_state->getValue('new_mapping');
    if (!empty($new_mapping['country_code'])) {
      $mappings[$new_mapping['country_code']] = $new_mapping['drupal_langcode'];
    }

    $this->config('smart_ip_locale_redirect.settings')
      ->set('excluded_user_agents', $form_state->getValue('excluded_user_agents'))
      ->set('mappings', $mappings)
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Retrieves the browser's langcode mapping configuration array.
   *
   * @return array
   *   The browser's langcode mapping configuration array.
   */
  protected function languageGetSmartIpLangcodeMappings() {
    $config = $this->config('smart_ip_locale_redirect.settings');

    return $config->get('mappings') ?: [];
  }

}
