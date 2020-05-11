<?php

namespace Drupal\smart_ip_locale_redirect;

use Drupal\Core\Access\AccessManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Cmf\Component\Routing\RouteProviderInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Helper class to check the requests that should be redirected.
 */
class RedirectChecker {

  /**
   * The interface for a configuration object factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The interface for the state system.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The AccessManager access checker service.
   *
   * @var \Drupal\Core\Access\AccessManager
   */
  protected $accessManager;

  /**
   * The account interface which represents the current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The router provider interface.
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * Constructs a RedirectChecker instance.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The interface for the state system.
   * @param \Drupal\Core\State\StateInterface $state
   *   The interface for the state system.
   * @param \Drupal\Core\Access\AccessManager $access_manager
   *   The AccessManager access checker service.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account interface which represents the current user.
   * @param \Drupal\Core\Routing\RouteProviderInterface $route_provider
   *   The router provider interface.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StateInterface $state, AccessManager $access_manager, AccountInterface $account, RouteProviderInterface $route_provider) {
    $this->configFactory = $config_factory;
    $this->accessManager = $access_manager;
    $this->state = $state;
    $this->account = $account;
    $this->routeProvider = $route_provider;
  }

  /**
   * Determines if redirect may be performed.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object.
   * @param string $route_name
   *   The current route name.
   *
   * @return bool
   *   TRUE if redirect may be performed.
   */
  public function canRedirect(Request $request, $route_name = NULL) {
    $can_redirect = TRUE;

    if (isset($route_name)) {
      $route = $this->routeProvider->getRouteByName($route_name);
      if ($this->configFactory->get('redirect.settings')->get('access_check')) {
        // Do not redirect if is a protected page.
        $can_redirect = $this->accessManager->checkNamedRoute($route_name, [], $this->account);
      }
    }
    else {
      $route = $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
    }

    $url_object = \Drupal::service('path.validator')->getUrlIfValid($request->getPathInfo());
    if ($url_object) {
      $router = \Drupal::service('router.no_access_checks');
      $result = $router->match($request->getPathInfo());
      if (isset($result['_disable_route_normalizer']) && $result['_disable_route_normalizer']) {
        // Do not redirect if the route has _disable_route_normalizer set to TRUE.
        $can_redirect = FALSE;
      }
      elseif ($result['_route_object']->getOption('_admin_route') == 1) {
        // Do not redirect if it's admin path.
        $can_redirect = FALSE;
      }
      elseif (isset($result['_route']) && $result['_route'] == 'entity.node.edit_form') {
        // Do not redirect if the path is the node edit page.
        $can_redirect = FALSE;
      }
    }
    elseif (!preg_match('/index\.php$/', $request->getScriptName())) {
      // Do not redirect if the root script is not /index.php.
      $can_redirect = FALSE;
    }
    elseif (!($request->isMethod('GET') || $request->isMethod('HEAD'))) {
      // Do not redirect if this is other than GET request.
      $can_redirect = FALSE;
    }
    elseif ($this->state->get('system.maintenance_mode') || defined('MAINTENANCE_MODE')) {
      // Do not redirect in offline or maintenance mode.
      $can_redirect = FALSE;
    }
    elseif ($request->query->has('destination')) {
      $can_redirect = FALSE;
    }
    elseif ($this->configFactory->get('redirect.settings')->get('ignore_admin_path') && isset($route)) {
      // Do not redirect on admin paths.
      $can_redirect &= !(bool) $route->getOption('_admin_route');
    }

    $excluded_user_agents = $this->configFactory->get('smart_ip_locale_redirect.settings')->get('excluded_user_agents');
    if ($excluded_user_agents != '') {
      $excluded_user_agents = array_map('trim', preg_split("(\r\n?|\n)", $excluded_user_agents));
      foreach ($excluded_user_agents as $excluded_user_agent) {
        if (preg_match('/' . $excluded_user_agent . '/', $request->headers->get('User-Agent'))) {
          $can_redirect = FALSE;
          break;
        }
      }
    }

    return $can_redirect;
  }

}
