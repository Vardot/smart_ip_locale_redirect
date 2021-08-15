<?php

namespace Drupal\smart_ip_locale_redirect\Form;

use Drupal\Core\Form\ConfigFormBaseTrait;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Defines a confirmation form for deleting an IP language negotiation mapping.
 */
class NegotiationIpDeleteForm extends ConfirmFormBase {
  use ConfigFormBaseTrait;

  /**
   * The browser language code to be deleted.
   *
   * @var string
   */
  protected $browserLangcode;

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['smart_ip_locale_redirect.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete %country_code?', ['%country_code' => $this->browserLangcode]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('smart_ip_locale_redirect.smart_ip_locale_redirect_admin_form');
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'smart_ip_locale_redirect_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $country_code = NULL) {
    $this->browserLangcode = $country_code;

    $form = parent::buildForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $mappings = $this->config('smart_ip_locale_redirect.settings')->get('mappings');
    unset($mappings[$this->browserLangcode]);

    $this->config('smart_ip_locale_redirect.settings')
      ->set('mappings', $mappings)
      ->save();
    $args = [
      '%country_code' => $this->browserLangcode,
    ];

    $this->logger('language')->notice('The country code language detection mapping for the %country_code language code has been deleted.', $args);

    \Drupal::messenger()->addMessage($this->t('The mapping for the %country_code language code has been deleted.', $args));

    $form_state->setRedirect('smart_ip_locale_redirect.smart_ip_locale_redirect_admin_form');
  }

}
