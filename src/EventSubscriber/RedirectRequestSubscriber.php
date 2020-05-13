<?php

namespace Drupal\smart_ip_locale_redirect\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\AliasManagerInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\redirect\Exception\RedirectLoopException;
use Drupal\smart_ip_locale_redirect\RedirectChecker;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RequestContext;
use Drupal\smart_ip_maxmind_geoip2_web_service\WebServiceUtility;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\smart_ip\SmartIp;

/**
 * Redirect subscriber for controller requests.
 */
class RedirectRequestSubscriber implements EventSubscriberInterface {

  /**
   * Common interface for the language manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Defines the default configuration object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The default alias manager implementation.
   *
   * @var \Drupal\Core\Path\AliasManager
   */
  protected $aliasManager;

  /**
   * Interface for classes that manage a set of enabled modules.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Provides an interface for entity type managers.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Helper class to check the requests that should be redirected.
   *
   * @var \Drupal\smart_ip_locale_redirect\RedirectChecker
   */
  protected $checker;

  /**
   * Holds information about the current request.
   *
   * @var \Symfony\Component\Routing\RequestContext
   */
  protected $context;

  /**
   * A path processor manager for resolving the system path.
   *
   * @var \Drupal\Core\PathProcessor\InboundPathProcessorInterface
   */
  protected $pathProcessor;

  /**
   * Constructs a \Drupal\vardot_tweaks\EventSubscriber\RedirectRequestSubscriber object.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config.
   * @param \Drupal\Core\Path\AliasManagerInterface $alias_manager
   *   The alias manager service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\smart_ip_locale_redirect\RedirectChecker $checker
   *   The redirect checker service.
   * @param \Symfony\Component\Routing\RequestContext $context
   *   Request context.
   * @param \Drupal\Core\PathProcessor\InboundPathProcessorInterface $path_processor
   *   A path processor manager for resolving the system path.
   */
  public function __construct(LanguageManagerInterface $language_manager, ConfigFactoryInterface $config, AliasManagerInterface $alias_manager, ModuleHandlerInterface $module_handler, EntityTypeManagerInterface $entity_type_manager, RedirectChecker $checker, RequestContext $context, InboundPathProcessorInterface $path_processor) {
    $this->languageManager = $language_manager;
    $this->config = $config->get('smart_ip_locale_redirect.settings');
    $this->aliasManager = $alias_manager;
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
    $this->checker = $checker;
    $this->context = $context;
    $this->pathProcessor = $path_processor;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // This needs to run before RouterListener::onKernelRequest(), which has
    // a priority of 32. Otherwise, that aborts the request if no matching
    // route is found.
    $events[KernelEvents::REQUEST][] = ['onKernelRequestCheckRedirect', 256];
    return $events;
  }

  /**
   * Handles the redirect if any found.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The event to process.
   */
  public function onKernelRequestCheckRedirect(GetResponseEvent $event) {

    // Get a clone of the request. During inbound processing the request
    // can be altered. Allowing this here can lead to unexpected behavior.
    // For example the path_processor.files inbound processor provided by
    // the system module alters both the path and the request; only the
    // changes to the request will be propagated, while the change to the
    // path will be lost.
    $request = clone $event->getRequest();
    if (!$this->checker->canRedirect($request)) {
      return;
    }

    // Get URL info and process it to be used for hash generation.
    parse_str($request->getQueryString(), $request_query);

    if (strpos($request->getPathInfo(), '/sites/default/files/') === 0) {
      // If the request for a file then do nothing.
      return;
    }
    elseif ($request->getPathInfo() == '/' || $request->getPathInfo() == '') {
      $path = '';
    }
    else {
      // Do the inbound processing so that for example language prefixes are
      // removed.
      $path = $this->pathProcessor->processInbound($request->getPathInfo(), $request);
    }

    $this->context->fromRequest($request);

    try {
      $language_interface = \Drupal::languageManager()->getCurrentLanguage();
      $current_language_id = $language_interface->getId();
      $langcode = $current_language_id;
      // Disable caching for this page. This only happens when negotiating
      // based on IP. Once the redirect took place to the correct domain
      // or language prefix, this function is not reached anymore and
      // caching works as expected.
      \Drupal::service('page_cache_kill_switch')->trigger();

      // Set the cookie based on the configuration.
      $cookie_settings = $this->config->get('cookie_settings') ?: [];
      $cookie_duration = $cookie_settings['duration'] ?: 432000;
      $cookie_path = $cookie_settings['path'] ?: '/';
      $cookie_domain = $cookie_settings['domain'] ?: '';

      $update_hl = $request->get('update_hl');
      if (isset($update_hl) && $update_hl != '') {
        $langcode = $update_hl;
        setcookie('smart_ip_hl', $langcode, time() + $cookie_duration, $cookie_path, $cookie_domain);
      }

      if ($request->cookies->get('smart_ip_hl')) {
        $langcode = $request->cookies->get('smart_ip_hl');
      }
      else {
        $countries = $this->config->get('mappings') ?: [];
        $client_ip = $request->getClientIp();
        $location = SmartIp::query($client_ip);
        $country_code = isset($location['countryCode']) ? strtolower($location['countryCode']) : '';
        if (!empty($country_code)) {
          // Check if a language is set for the determined country.
          if (!empty($countries[$country_code])) {
            $langcode = $countries[$country_code];
          }
        }

        setcookie('smart_ip_hl', $langcode, time() + $cookie_duration, $cookie_path, $cookie_domain);
      }
      if ($current_language_id == $langcode && ($request->getPathInfo() != '/' && $request->getPathInfo() != '')) {
        return;
      }

      if ($request->getPathInfo() == '/' || $request->getPathInfo() == '') {
        $url = Url::fromUri('/' . $langcode . $path)->toString();
      }
      else {
        $url = '/' . $langcode . \Drupal::service('path.alias_manager')->getAliasByPath($path, $langcode);
        // Check if there is a query string then reserve it.
        $query = $request->getQueryString();
        if (!empty($query)) {
          $url = $url . '?' . $query;
        }
      }
    }
    catch (RedirectLoopException $e) {
      \Drupal::logger('smart_ip_locale_redirect')->warning('Redirect loop identified at %path for redirect %rid', ['%path' => $e->getPath(), '%rid' => $e->getRedirectId()]);
      $response = new Response();
      $response->setStatusCode(503);
      $response->setContent('Service unavailable');
      $event->setResponse($response);
      return;
    }

    if (!empty($url)) {
      $response = new TrustedRedirectResponse($url, 302);
      $build = [
        '#cache' => [
          'max-age' => 0,
        ],
      ];
      $cache_metadata = CacheableMetadata::createFromRenderArray($build);
      $response->addCacheableDependency($cache_metadata);
      $event->setResponse($response);
    }
  }

}
