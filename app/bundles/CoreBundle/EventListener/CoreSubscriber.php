<?php

namespace Mautic\CoreBundle\EventListener;

use Mautic\CoreBundle\Controller\MauticController;
use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\IconEvent;
use Mautic\CoreBundle\Event\MenuEvent;
use Mautic\CoreBundle\Event\RouteEvent;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\CoreBundle\Factory\ModelFactory;
use Mautic\CoreBundle\Helper\BundleHelper;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Menu\MenuHelper;
use Mautic\CoreBundle\Service\FlashBag;
use Mautic\CoreBundle\Twig\Helper\AssetsHelper;
use Mautic\FormBundle\Entity\FormRepository;
use Mautic\UserBundle\Entity\User;
use Mautic\UserBundle\Event\LoginEvent;
use Mautic\UserBundle\Model\UserModel;
use Mautic\UserBundle\UserEvents;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

class CoreSubscriber implements EventSubscriberInterface
{
    /**
     * @var BundleHelper
     */
    private $bundleHelper;

    /**
     * @var MenuHelper
     */
    private $menuHelper;

    /**
     * @var UserHelper
     */
    private $userHelper;

    /**
     * @var AssetsHelper
     */
    private $assetsHelper;

    /**
     * @var AuthorizationCheckerInterface
     */
    private $securityContext;

    /**
     * @var UserModel
     */
    private $userModel;

    /**
     * @var CoreParametersHelper
     */
    private $coreParametersHelper;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var RequestStack
     */
    private $requestStack;

    /**
     * @var FormRepository
     */
    private $formRepository;

    /**
     * @var MauticFactory
     */
    private $factory;

    /**
     * @var ModelFactory<object>
     */
    private $modelFactory;

    /**
     * @var FlashBag
     */
    private $flashBag;

    /**
     * @param ModelFactory<object> $modelFactory
     */
    public function __construct(
        BundleHelper $bundleHelper,
        MenuHelper $menuHelper,
        UserHelper $userHelper,
        AssetsHelper $assetsHelper,
        CoreParametersHelper $coreParametersHelper,
        AuthorizationCheckerInterface $securityContext,
        UserModel $userModel,
        EventDispatcherInterface $dispatcher,
        TranslatorInterface $translator,
        RequestStack $requestStack,
        FormRepository $formRepository,
        MauticFactory $factory,
        ModelFactory $modelFactory,
        FlashBag $flashBag
    ) {
        $this->bundleHelper         = $bundleHelper;
        $this->menuHelper           = $menuHelper;
        $this->userHelper           = $userHelper;
        $this->assetsHelper         = $assetsHelper;
        $this->securityContext      = $securityContext;
        $this->userModel            = $userModel;
        $this->coreParametersHelper = $coreParametersHelper;
        $this->dispatcher           = $dispatcher;
        $this->translator           = $translator;
        $this->requestStack         = $requestStack;
        $this->formRepository       = $formRepository;
        $this->factory              = $factory;
        $this->modelFactory         = $modelFactory;
        $this->flashBag             = $flashBag;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::CONTROLLER => [
                ['onKernelController', 0],
                ['onKernelRequestAddGlobalJS', 0],
            ],
            CoreEvents::BUILD_MENU            => ['onBuildMenu', 9999],
            CoreEvents::BUILD_ROUTE           => ['onBuildRoute', 0],
            CoreEvents::FETCH_ICONS           => ['onFetchIcons', 9999],
            SecurityEvents::INTERACTIVE_LOGIN => ['onSecurityInteractiveLogin', 0],
        ];
    }

    /**
     * Add mauticForms in js script tag for Froala.
     */
    public function onKernelRequestAddGlobalJS(ControllerEvent $event)
    {
        if (defined('MAUTIC_INSTALLER') || $this->userHelper->getUser()->isGuest() || !$event->isMasterRequest()) {
            return;
        }

        $list        = $this->formRepository->getSimpleList();
        $mauticForms = json_encode($list, JSON_FORCE_OBJECT | JSON_PRETTY_PRINT);

        $this->assetsHelper->addScriptDeclaration("var mauticForms = {$mauticForms};");
    }

    /**
     * Set vars on login.
     */
    public function onSecurityInteractiveLogin(InteractiveLoginEvent $event)
    {
        if (defined('MAUTIC_INSTALLER')) {
            return;
        }

        $session = $event->getRequest()->getSession();
        if ($this->securityContext->isGranted('IS_AUTHENTICATED_FULLY') || $this->securityContext->isGranted('IS_AUTHENTICATED_REMEMBERED')) {
            $user = $event->getAuthenticationToken()->getUser();

            //set a session var for filemanager to know someone is logged in
            $session->set('mautic.user', $user->getId());

            //mark the user as last logged in
            $user = $this->userHelper->getUser();
            if ($user instanceof User) {
                $this->userModel->getRepository()->setLastLogin($user);

                // Set the timezone and locale in session while we have it since Symfony dispatches the onKernelRequest prior to the
                // firewall setting the known user
                $tz = $user->getTimezone();
                if (empty($tz)) {
                    $tz = $this->coreParametersHelper->get('default_timezone');
                }
                $session->set('_timezone', $tz);

                $locale = $user->getLocale();
                if (empty($locale)) {
                    $locale = $this->coreParametersHelper->get('locale');
                }
                $session->set('_locale', $locale);
            }

            //dispatch on login events
            if ($this->dispatcher->hasListeners(UserEvents::USER_LOGIN)) {
                $loginEvent = new LoginEvent($this->userHelper->getUser());
                $this->dispatcher->dispatch($loginEvent, UserEvents::USER_LOGIN);
            }
        } else {
            $session->remove('mautic.user');
        }
    }

    /**
     * Populates namespace, bundle, controller, and action into request to be used throughout application.
     */
    public function onKernelController(ControllerEvent $event)
    {
        $controller = $event->getController();

        if (!is_array($controller)) {
            return;
        }

        //only affect Mautic controllers
        if ($controller[0] instanceof MauticController) {
            // set the factory for easy use access throughout the controllers
            // @deprecated To be removed in 3.0
            $controller[0]->setFactory($this->factory);

            $controller[0]->setModelFactory($this->modelFactory);

            // set the user as well
            $controller[0]->setUser($this->userHelper->getUser());

            // and the core parameters helper
            $controller[0]->setCoreParametersHelper($this->coreParametersHelper);

            // and the dispatcher
            $controller[0]->setDispatcher($this->dispatcher);

            // and the translator
            $controller[0]->setTranslator($this->translator);

            // and the flash bag
            $controller[0]->setFlashBag($this->flashBag);

            //run any initialize functions
            $controller[0]->initialize($event);
        }
    }

    public function onBuildMenu(MenuEvent $event)
    {
        $name    = $event->getType();
        $bundles = $this->bundleHelper->getMauticBundles(true);
        foreach ($bundles as $bundle) {
            if (!empty($bundle['config']['menu'][$name])) {
                $menu = $bundle['config']['menu'][$name];
                $event->addMenuItems(
                    [
                        'priority' => !isset($menu['priority']) ? 9999 : $menu['priority'],
                        'items'    => !isset($menu['items']) ? $menu : $menu['items'],
                    ]
                );
            }
        }
    }

    public function onBuildRoute(RouteEvent $event)
    {
        $type       = $event->getType();
        $bundles    = $this->bundleHelper->getMauticBundles(true);
        $collection = $event->getCollection();

        foreach ($bundles as $bundle) {
            if (!empty($bundle['config']['routes'][$type])) {
                foreach ($bundle['config']['routes'][$type] as $name => $details) {
                    if ('api' == $type && !empty($details['standard_entity'])) {
                        $standards = [
                            'getall' => [
                                'action' => 'getEntities',
                                'method' => 'GET',
                                'path'   => '',
                            ],
                            'getone' => [
                                'action' => 'getEntity',
                                'method' => 'GET',
                                'path'   => '/{id}',
                            ],
                            'new' => [
                                'action' => 'newEntity',
                                'method' => 'POST',
                                'path'   => '/new',
                            ],
                            'newbatch' => [
                                'action' => 'newEntities',
                                'method' => 'POST',
                                'path'   => '/batch/new',
                            ],
                            'editbatchput' => [
                                'action' => 'editEntities',
                                'method' => 'PUT',
                                'path'   => '/batch/edit',
                            ],
                            'editbatchpatch' => [
                                'action' => 'editEntities',
                                'method' => 'PATCH',
                                'path'   => '/batch/edit',
                            ],
                            'editput' => [
                                'action' => 'editEntity',
                                'method' => 'PUT',
                                'path'   => '/{id}/edit',
                            ],
                            'editpatch' => [
                                'action' => 'editEntity',
                                'method' => 'PATCH',
                                'path'   => '/{id}/edit',
                            ],
                            'deletebatch' => [
                                'action' => 'deleteEntities',
                                'method' => 'DELETE',
                                'path'   => '/batch/delete',
                            ],
                            'delete' => [
                                'action' => 'deleteEntity',
                                'method' => 'DELETE',
                                'path'   => '/{id}/delete',
                            ],
                        ];

                        foreach (['name', 'path', 'controller'] as $required) {
                            if (empty($details[$required])) {
                                throw new \InvalidArgumentException("$bundle.$name must have $required defined");
                            }
                        }

                        $routeName  = 'mautic_api_'.$details['name'].'_';
                        $pathBase   = $details['path'];
                        $controller = $details['controller'];
                        foreach ($standards as $standardName => $standardDetails) {
                            if (!empty($details['supported_endpoints']) && !in_array($standardName, $details['supported_endpoints'])) {
                                // Not supported so ignore
                                continue;
                            }

                            $routeDetails = array_merge(
                                $standardDetails,
                                [
                                    'path'       => $pathBase.$standardDetails['path'],
                                    'controller' => $controller.':'.$standardDetails['action'].'Action',
                                    'method'     => $standardDetails['method'],
                                ]
                            );
                            $this->addRouteToCollection($collection, $type, $routeName.$standardName, $routeDetails);
                        }
                    } else {
                        $this->addRouteToCollection($collection, $type, $name, $details);
                    }
                }
            }
        }
    }

    public function onFetchIcons(IconEvent $event)
    {
        $session = $this->requestStack->getCurrentRequest()->getSession();
        $icons   = $session->get('mautic.menu.icons', []);

        if (empty($icons)) {
            $bundles = $this->bundleHelper->getMauticBundles(true);

            foreach ($bundles as $bundle) {
                if (!empty($bundle['config']['menu']['main'])) {
                    $items = (!isset($bundle['config']['menu']['main']['items']) ? $bundle['config']['menu']['main'] : $bundle['config']['menu']['main']['items']);
                }

                if (!empty($items)) {
                    $this->menuHelper->createMenuStructure($items);
                    foreach ($items as $item) {
                        if (isset($item['iconClass']) && isset($item['id'])) {
                            $id = explode('_', $item['id']);
                            if (isset($id[1])) {
                                // some bundle names are in plural, create also singular item
                                if ('s' == substr($id[1], -1)) {
                                    $event->addIcon(rtrim($id[1], 's'), $item['iconClass']);
                                }
                                $event->addIcon($id[1], $item['iconClass']);
                            }
                        }
                    }
                }
            }
            unset($bundles);

            $icons = $event->getIcons();
            $session->set('mautic.menu.icons', $icons);
        } else {
            $event->setIcons($icons);
        }
    }

    /**
     * @param $type
     * @param $name
     * @param $details
     */
    private function addRouteToCollection(RouteCollection $collection, $type, $name, $details)
    {
        // Set defaults and controller
        $defaults = (!empty($details['defaults'])) ? $details['defaults'] : [];
        if (isset($details['controller'])) {
            $defaults['_controller'] = $details['controller'];
        }
        if (isset($details['format'])) {
            $defaults['_format'] = $details['format'];
        } elseif ('api' == $type) {
            $defaults['_format'] = 'json';
        }
        $method = [];
        if (isset($details['method'])) {
            $method = (array) $details['method'];
        } elseif ('api' === $type) {
            $method = ['GET'];
        }
        // Set requirements
        $requirements = (!empty($details['requirements'])) ? $details['requirements'] : [];

        // Set some very commonly used defaults and requirements
        if (false !== strpos($details['path'], '{page}')) {
            if (!isset($defaults['page'])) {
                $defaults['page'] = 0;
            }
            if (!isset($requirements['page'])) {
                $requirements['page'] = '\d+';
            }
        }
        if (false !== strpos($details['path'], '{objectId}')) {
            if (!isset($defaults['objectId'])) {
                // Set default to 0 for the "new" actions
                $defaults['objectId'] = 0;
            }
            if (!isset($requirements['objectId'])) {
                // Only allow alphanumeric and _- for objectId
                $requirements['objectId'] = '[a-zA-Z0-9_-]+';
            }
        }
        if ('api' == $type) {
            if (false !== strpos($details['path'], '{id}')) {
                if (!isset($requirements['page'])) {
                    $requirements['id'] = '\d+';
                }
            }

            if (preg_match_all('/\{(.*?Id)\}/', $details['path'], $matches)) {
                // Force digits for IDs
                foreach ($matches[1] as $match) {
                    if (!isset($requirements[$match])) {
                        $requirements[$match] = '\d+';
                    }
                }
            }
        }

        // Add the route
        $collection->add($name, new Route($details['path'], $defaults, $requirements, [], '', [], $method));
    }
}
