<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\AdminBar\AdminBar;
use Baraja\AssetsLoader\Minifier\DefaultJsMinifier;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\Proxy\Proxy;
use Baraja\Cms\User\AdminBarUser;
use Baraja\Cms\User\Entity\CmsUser;
use Baraja\Cms\User\Entity\UserResetPasswordRequest;
use Baraja\Plugin\BasePlugin;
use Baraja\Plugin\CmsPluginPanel;
use Baraja\Plugin\Component\PluginComponent;
use Baraja\Plugin\Exception\PluginRedirectException;
use Baraja\Plugin\Exception\PluginTerminateException;
use Baraja\Plugin\Exception\PluginUserErrorException;
use Baraja\Plugin\Plugin;
use Baraja\Plugin\SimpleComponent\SimpleComponent;
use Baraja\ServiceMethodInvoker;
use Baraja\Url\Url;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Latte\Engine;
use Nette\Application\Responses\TextResponse;
use Nette\Application\Responses\VoidResponse;
use Nette\Http\IResponse;
use Tracy\Debugger;
use Tracy\ILogger;

final class Admin
{
	public const SUPPORTED_LOCALES = ['cs', 'en'];

	private string $cacheDir;

	private Context $context;

	private LinkGenerator $linkGenerator;

	private MenuManager $menuManager;

	private CmsPluginPanel $panel;

	private ?string $plugin;

	private ?string $view;

	private ?string $locale;


	public function __construct(
		string $cacheDir,
		Context $context,
		LinkGenerator $linkGenerator,
		MenuManager $menuManager,
		CmsPluginPanel $panel
	) {
		$this->cacheDir = $cacheDir;
		$this->context = $context;
		$this->linkGenerator = $linkGenerator;
		$this->menuManager = $menuManager;
		$this->panel = $panel;
		Debugger::getBar()->addPanel($panel);
	}


	public function run(?string $locale, string $path): void
	{
		try {
			if ($this->context->getSettings()->isOk() === false) { // route installation workflow
				if ($path !== '') { // canonize configuration request to base admin URL
					$this->redirect(Url::get()->getBaseUrl() . '/admin');
				}
				$this->terminate($this->context->getSettings()->run());
			}
			if (strncmp($path = trim($path, '/'), 'reset-password', 14) === 0) { // route reset password form
				$this->terminate($this->renderResetPasswordTemplate($_GET['token'] ?? ''));
			}
			if (strncmp($path, 'set-user-password', 17) === 0) { // route reset password form
				$this->terminate($this->renderSetUserPasswordTemplate($_GET['userId'] ?? ''));
			}
			if (strncmp($path, 'cms-web-loader', 14) === 0) { // route dynamic assets
				(new Proxy($this->context->getPluginManager()))->run($path);
			}
			if (
				($assetType = $path === 'assets/core.js' ? 'js' : null)
				|| ($assetType = $path === 'assets/core.css' ? 'css' : null)
			) { // route static assets from template directory
				header('Content-Type: ' . Proxy::CONTENT_TYPES[$assetType]);
				$assetContent = file_get_contents(__DIR__ . '/../template/assets/core.' . $assetType)
					. (($customAssetPath = $this->context->getCustomAssetPath($assetType)) !== null ? "\n\n" . file_get_contents($customAssetPath) : '');
				if ($assetType === 'css') {
					$assetContent = Helpers::minifyHtml($assetContent);
				} elseif ($assetType === 'js' && \class_exists(DefaultJsMinifier::class)) {
					$assetContent = (new DefaultJsMinifier)->minify($assetContent);
				}
				echo '/*' . "\n"
					. ' * This file is part of Baraja CMS.' . "\n"
					. ' */' . "\n\n"
					. $assetContent;
				die;
			}
			if (
				strncmp($path, 'cms/', 4) !== 0
				&& $this->context->getUser()->isLoggedIn() === false
			) { // route login form
				if ($this->context->getUser()->getId() !== null) {
					$this->terminate($this->renderNeedOtpAuth());
				}
				$this->terminate($this->renderLoginTemplate());
			}

			$this->processAdminBar();
			$this->locale = $locale;
			[$plugin, $view, $more] = explode('/', $path . '///');

			if ($more === '' && $view !== '') { // route plugin request in format "xxx/yyy"
				$this->plugin = Helpers::formatPresenterNameByUri($plugin);
				$this->view = Helpers::formatActionNameByUri(explode('?', $view)[0]) ?: null;
			} elseif ($plugin !== '') { // route plugin request in format "xxx"
				$this->plugin = Helpers::formatPresenterNameByUri(explode('?', $plugin)[0]) ?: null;
			}

			try {
				$pluginService = $this->context->getPluginByName($this->getPlugin());
			} catch (\RuntimeException $e) {
				$pluginService = $this->context->getPluginByType(ErrorPlugin::class);
			}

			$this->panel->setPlugin($this->getPlugin());
			$this->panel->setView($this->getView());
			$this->panel->setPluginService($pluginService);

			if ($this->context->checkPermission($this->getPlugin(), null) === false) {
				$this->terminate($this->renderPermissionDenied());
			}

			try {
				$pluginService->beforeRender();
				$pluginService->run();

				if (\method_exists($pluginService, $actionMethod = 'action' . $this->getView()) === true) {
					(new ServiceMethodInvoker)->invoke($pluginService, $actionMethod, $this->context->getRequest()->getUrl()->getQueryParameters());
				}

				$pluginService->afterRender();
			} catch (PluginRedirectException $e) {
				$this->redirect(Url::get()->getBaseUrl() . '/admin' . (($path = $e->getPath()) === '' ? '' : '/' . $path));
			} catch (PluginTerminateException $e) {
				$this->terminate();
			} catch (PluginUserErrorException $e) {
				/** @var ErrorPlugin $pluginService */
				$pluginService = $this->context->getPluginByType(ErrorPlugin::class);
				$pluginService->setTitle($e->getMessage());
				$pluginService->setSubtitle(null);
			}

			$template = $this->renderTemplate($pluginService);
			$this->terminate($template);
		} catch (AdminRedirect $redirect) {
			$this->redirect($redirect->getUrl());
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::EXCEPTION);
			throw $e;
		}
	}


	public function getPlugin(): string
	{
		return $this->plugin ?? 'Homepage';
	}


	public function getView(): string
	{
		return $this->view ?? 'default';
	}


	public function getLocale(): string
	{
		return $this->locale ?? $this->context->getLocale();
	}


	private function renderLoginTemplate(): string
	{
		return Helpers::minifyHtml((new Engine)
			->setTempDirectory($this->cacheDir)
			->addFilter('translate', $this->context->getTranslatorFilter())
			->renderToString(__DIR__ . '/../template/login.latte', [
				'basePath' => Url::get()->getBaseUrl(),
				'availableLocales' => self::SUPPORTED_LOCALES,
				'projectName' => $this->context->getConfiguration()->get('name', 'core'),
				'locale' => $this->getLocale(),
			]));
	}


	private function renderResetPasswordTemplate(string $token): string
	{
		try {
			/** @var UserResetPasswordRequest $request */
			$request = $this->context->getEntityManager()->getRepository(UserResetPasswordRequest::class)
				->createQueryBuilder('resetRequest')
				->select('resetRequest, PARTIAL user.{id, username}')
				->leftJoin('resetRequest.user', 'user')
				->where('resetRequest.token = :token')
				->setParameter('token', $token)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();

			if ($request->isExpired() === true) {
				throw new \InvalidArgumentException('Token has been expired.');
			}
		} catch (NoResultException | NonUniqueResultException | \InvalidArgumentException $e) {
			return 'The password change token does not exist. Please request a new token again.';
		}

		return Helpers::minifyHtml((new Engine)
			->setTempDirectory($this->cacheDir)
			->addFilter('translate', $this->context->getTranslatorFilter())
			->renderToString(__DIR__ . '/../template/reset-password.latte', [
				'basePath' => Url::get()->getBaseUrl(),
				'loginUrl' => Url::get()->getBaseUrl() . '/admin',
				'locale' => $this->getLocale(),
				'username' => $request->getUser()->getUsername(),
				'token' => $request->getToken(),
			]));
	}


	private function renderSetUserPasswordTemplate(string $userId): string
	{
		try {
			/** @var CmsUser $user */
			$user = $this->context->getEntityManager()->getRepository($this->context->getUserManager()->get()->getDefaultEntity())
				->createQueryBuilder('user')
				->where('user.id = :userId')
				->setParameter('userId', $userId)
				->setMaxResults(1)
				->getQuery()
				->getSingleResult();
		} catch (NoResultException | NonUniqueResultException | \InvalidArgumentException $e) {
			return 'This link does not work. For more information, please contact your project administrator.';
		}
		if ($user->getPassword() !== '---empty-password---') {
			return 'Settings link is invalid, because password was changed.';
		}

		return Helpers::minifyHtml((new Engine)
			->setTempDirectory($this->cacheDir)
			->addFilter('translate', $this->context->getTranslatorFilter())
			->renderToString(__DIR__ . '/../template/set-user-password.latte', [
				'basePath' => Url::get()->getBaseUrl(),
				'loginUrl' => Url::get()->getBaseUrl() . '/admin',
				'locale' => $this->getLocale(),
				'userId' => $user->getId(),
				'username' => $user->getUsername(),
			]));
	}


	private function renderTemplate(Plugin $plugin): string
	{
		$components = $this->context->getComponents($plugin, $plugin instanceof ErrorPlugin ? 'default' : $this->getView());
		$this->panel->setRenderedComponents($components);

		ob_start(static function () {
		});

		$args = [
			'isDebug' => (string) ($_GET['debugMode'] ?? '') === '1',
			'basePath' => $baseUrl = Url::get()->getBaseUrl(),
			'assetsPath' => 'admin/cms-web-loader/' . $this->context->getPluginNameByType($plugin) . '.js',
			'customAssetPaths' => $this->context->getCustomGlobalAssetPaths(),
			'content' => $this->renderContentCode($plugin, $components),
			'menu' => [
				'dashboardLink' => $baseUrl . '/admin',
				'isDashboard' => $this->getPlugin() === 'Homepage' && $this->getView() === 'default',
				'structure' => $this->menuManager->getItems(),
				'activeKey' => $this->context->getPluginKey($plugin),
			],
			'globalSettings' => [
				'startWeekday' => 0,
			],
		];

		/** @phpstan-ignore-next-line */
		extract($args, EXTR_OVERWRITE);

		try {
			require __DIR__ . '/../template/@layout.phtml';

			return (string) ob_get_clean();
		} catch (\Throwable $e) {
			ob_end_clean();
			throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
		}
	}


	/**
	 * @param PluginComponent[] $components
	 */
	private function renderContentCode(Plugin $plugin, array $components): string
	{
		if (\count($components) === 0) {
			$return = null;
		} elseif ($this->getView() === 'detail') {
			$componentsData = [];

			$first = true;
			foreach ($components as $component) {
				if ($this->context->checkPermission($this->getPlugin(), $component->getName())) {
					$active = $first === true;
					$componentsData[] = '<b-tab lazy @click="$emit(\'activeMe\')" title="' . Helpers::escapeHtmlAttr($component->getTab()) . '"'
						. ($active ? ' active' : '')
						. '>' . $this->renderVueComponent($component, $plugin) . "\n" . '</b-tab>';
					$first = false;
				}
			}

			$componentParameters = '';
			if ($plugin instanceof BasePlugin) {
				$componentParameters = ' title="' . (($title = $plugin->getTitle()) !== null ? Helpers::escapeHtmlAttr($title) : $plugin->getName()) . '"'
					. (($subtitle = $plugin->getSubtitle()) !== null ? ' subtitle="' . Helpers::escapeHtmlAttr($subtitle) . '"' : '')
					. (($breadcrumb = $plugin->getBreadcrumb()) !== [] ? ' :breadcrumb="' . Helpers::escapeHtmlAttr($this->renderSimpleComponents($breadcrumb)) . '"' : '')
					. (($buttons = $plugin->getButtons()) !== [] ? ' :buttons="' . Helpers::escapeHtmlAttr($this->renderSimpleComponents($buttons)) . '"' : '')
					. (($contextMenu = $plugin->getContextMenu()) !== [] ? ' :context-menu="' . Helpers::escapeHtmlAttr($this->renderSimpleComponents($contextMenu)) . '"' : '')
					. (($linkBack = $plugin->getLinkBack()) !== null ? ' link-back="' . Helpers::escapeHtmlAttr($linkBack) . '"' : '')
					. ($plugin->isSaveAll() === true ? ' :save-all="true"' : '')
					. (($smartComponent = $plugin->getSmartControlComponentName()) !== null
						? ' smart-component="' . Helpers::escapeHtmlAttr($smartComponent) . '"'
						. ' :smart-component-params="' . Helpers::escapeHtmlAttr((string) json_encode($plugin->getSmartControlComponentParams())) . '"' : '');
			}

			$return = '<div class="px-4 py-2">' . "\n"
				. '<!-- Main content (tabs) -->' . "\n"
				. '<cms-detail' . $componentParameters . '>' . "\n"
				. '<b-tabs no-fade>' . "\n\n" . implode("\n", $componentsData) . "\n\n" . '</b-tabs>' . "\n"
				. '</cms-detail>' . "\n"
				. '</div>';
		} elseif (\count($components) === 1) {
			if ($this->context->checkPermission($this->getPlugin(), $components[0]->getName())) {
				$return = $this->renderVueComponent($components[0], $plugin);
			} else {
				$return = null;
			}
		} else {
			$return = null;
		}

		return $return ?? '<div class="px-4 py-2"><i>No&nbsp;components found.</i></div>';
	}


	/**
	 * Render given component entity to Vue component (HTML element with all parameters).
	 * For example: '<user-detail id="1234"></user-detail>'.
	 */
	private function renderVueComponent(PluginComponent $component, Plugin $plugin): string
	{
		try {
			return '<!-- component ' . Helpers::escapeHtmlComment($component->getKey()) . ' -->' . "\n"
				. $component->render($this->context->getRequest(), $plugin);
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::CRITICAL);

			return '<!-- can not render component! -->'
				. '<p>Can not render component <b>' . htmlspecialchars($component->getTab(), ENT_QUOTES) . '</b></p>';
		}
	}


	private function renderNeedOtpAuth(): string
	{
		return '<link type="text/css" rel="stylesheet" href="https://cdn.baraja.cz/bootstrap/bootstrap.min.css">
<div style="margin:6em auto;max-width:32em;padding:0 1em">
	<div class="row mb-4">
		<div class="col-sm-3"><img src="https://cdn.baraja.cz/icon/warning-triangle.png" alt="Warning"></div>
		<div class="col text-right"><h1>Permission denied</h1></div>
	</div>
	<p>To visit this page, you must first verify through 2-step verification.</p>
	<p class="text-secondary">That’s all we know.</p>
	<p><a href="' . Url::get()->getBaseUrl() . '/admin/cms/sign-out" class="btn btn-primary">Sign out</a></p>
</div>';
	}


	private function renderPermissionDenied(): string
	{
		return '<link type="text/css" rel="stylesheet" href="https://cdn.baraja.cz/bootstrap/bootstrap.min.css">
<div style="margin:6em auto;max-width:32em;padding:0 1em">
	<div class="row mb-4">
		<div class="col-sm-3"><img src="https://cdn.baraja.cz/icon/warning-triangle.png" alt="Warning"></div>
		<div class="col text-right"><h1>Permission denied</h1></div>
	</div>
	<p>Open this page is not permitted for your account.</p>
	<p class="text-secondary">That’s all we know.</p>
	<p><a href="' . Url::get()->getBaseUrl() . '/admin/cms/sign-out" class="btn btn-primary">Sign out</a></p>
</div>';
	}


	private function terminate(?string $haystack = null): void
	{
		($haystack === null ? new VoidResponse : new TextResponse($haystack))
			->send($this->context->getRequest(), $this->context->getResponse());
		die;
	}


	private function redirect(string $url, int $httpCode = IResponse::S302_FOUND): void
	{
		$this->context->getResponse()->redirect($url, $httpCode);
		$this->terminate();
	}


	/**
	 * @param SimpleComponent[] $simpleComponents
	 */
	private function renderSimpleComponents(array $simpleComponents): string
	{
		$return = [];
		foreach ($simpleComponents as $component) {
			$return[] = $component->toArray();
		}

		return (string) json_encode($return);
	}


	private function processAdminBar(): void
	{
		AdminBar::enable(true);
		AdminBar::addPanel($this->context->getBasicInformation());
		AdminBar::setUser(new AdminBarUser($this->context->getUser()->getIdentity()));

		if ($this->context->checkPermission('user', 'user-overview') === true) { // Show link only in case of user can edit profile
			AdminBar::addLink('My Profile', $this->linkGenerator->link('User:detail', ['id' => $this->context->getUser()->getId()]));
			AdminBar::addSeparator();
		}

		AdminBar::addLink('Settings', $this->linkGenerator->link('Settings:default'));
		AdminBar::addLink('Sign out', $this->linkGenerator->link('Cms:signOut'));
	}
}
