<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare;


use Baraja\AdminBar\AdminBar;
use Baraja\AssetsLoader\Minifier\DefaultJsMinifier;
use Baraja\Cms\Context;
use Baraja\Cms\Helpers;
use Baraja\Cms\LinkGenerator;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\Proxy\Proxy;
use Baraja\Cms\User\AdminBarUser;
use Baraja\Plugin\CmsPluginPanel;
use Baraja\Plugin\Exception\PluginRedirectException;
use Baraja\Plugin\Exception\PluginTerminateException;
use Baraja\Plugin\Exception\PluginUserErrorException;
use Baraja\ServiceMethodInvoker;
use Baraja\Url\Url;
use Nette\Application\Responses\TextResponse;
use Nette\Application\Responses\VoidResponse;
use Nette\Http\IResponse;
use Tracy\Debugger;
use Tracy\ILogger;

final class Application
{
	public function __construct(
		private Context $context,
		private CmsPluginPanel $panel,
		private TemplateRenderer $templateRenderer,
		private LinkGenerator $linkGenerator
	) {
	}


	/**
	 * The application is the main admin logic that handles requests to render individual pages and files.
	 *
	 * The responsibilities of the application are:
	 * 1. Accept the rendering request
	 * 2. Set the basic environment for the Tracy panel
	 * 3. Calling the system micro-workflow
	 * 4. User login
	 * 5. Init and load the default Admin bar configuration
	 * 6. Obtaining a plugin for the received request
	 * 7. Verification of access rights to the plugin and action
	 * 8. Processing of internal logic of the plugin
	 * 9. Render the template and end the request
	 */
	public function run(string $plugin, string $view, string $locale, string $path): void
	{
		$this->panel->setPlugin($plugin);
		$this->panel->setView($view);

		$this->trySystemWorkflow($plugin, $path, $locale);
		$this->processLoginPage($path, $locale);
		$this->setupAdminBar();

		try {
			$pluginService = $this->context->getPluginByName($plugin);
			if ($this->context->checkPermission($plugin) === false) {
				$this->terminate($this->templateRenderer->renderPermissionDenied());
			}
		} catch (\RuntimeException $e) {
			if (class_exists(Debugger::class)) {
				Debugger::log($e, ILogger::EXCEPTION);
			}
			$pluginService = $this->context->getPluginByType(ErrorPlugin::class);
		}

		$this->panel->setPluginService($pluginService);

		try {
			$pluginService->beforeRender();
			$pluginService->run();

			if (\method_exists($pluginService, $actionMethod = 'action' . $view) === true) {
				(new ServiceMethodInvoker)->invoke($pluginService, $actionMethod, $this->context->getRequest()->getUrl()->getQueryParameters());
			}

			$pluginService->afterRender();
		} catch (PluginRedirectException $e) {
			$this->redirect(Url::get()->getBaseUrl() . '/admin' . (($path = $e->getPath()) === '' ? '' : '/' . $path));
		} catch (PluginTerminateException) {
			$this->terminate();
		} catch (PluginUserErrorException $e) {
			/** @var ErrorPlugin $pluginService */
			$pluginService = $this->context->getPluginByType(ErrorPlugin::class);
			$pluginService->setTitle($e->getMessage());
			$pluginService->setSubtitle(null);
		}

		$this->terminate($this->templateRenderer->renderTemplate($pluginService, $plugin, $view));
	}


	private function trySystemWorkflow(string $plugin, string $path, string $locale): void
	{
		if ($this->context->getSettings()->isOk() === false) { // route installation workflow
			if ($path !== '') { // canonize configuration request to base admin URL
				$this->redirect(Url::get()->getBaseUrl() . '/admin');
			}
			$this->terminate($this->context->getSettings()->run());
		}
		if ($plugin === 'ResetPassword') { // route reset password form
			$this->terminate($this->templateRenderer->renderResetPasswordTemplate($_GET['token'] ?? '', $locale));
		}
		if ($plugin === 'SetUserPassword') { // route reset password form
			$this->terminate($this->templateRenderer->renderSetUserPasswordTemplate($_GET['userId'] ?? '', $locale));
		}
		if ($plugin === 'CmsWebLoader') { // route dynamic assets
			(new Proxy($this->context->getPluginManager()))->run($path);
		}
		if (
			($assetType = $path === 'assets/core.js' ? 'js' : null)
			|| ($assetType = $path === 'assets/core.css' ? 'css' : null)
		) { // route static assets from template directory
			header('Content-Type: ' . Proxy::CONTENT_TYPES[$assetType]);
			$assetContent = file_get_contents(__DIR__ . '/../../template/assets/core.' . $assetType)
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
	}


	private function processLoginPage(string $path, string $locale): void
	{
		if (
			strncmp($path, 'cms/', 4) !== 0
			&& $this->context->getUser()->isLoggedIn() === false
		) { // route login form
			if ($this->context->getUser()->getId() !== null) {
				$this->terminate($this->templateRenderer->renderNeedOtpAuth());
			}
			$this->terminate($this->templateRenderer->renderLoginTemplate($locale));
		}
	}


	private function terminate(?string $haystack = null): void
	{
		if ($haystack === null) {
			$response = new VoidResponse;
		} else {
			$response = new TextResponse(Helpers::minifyHtml($haystack));
		}
		$response->send($this->context->getRequest(), $this->context->getResponse());
		die;
	}


	private function redirect(string $url, int $httpCode = IResponse::S302_FOUND): void
	{
		$this->context->getResponse()->redirect($url, $httpCode);
		$this->terminate();
	}


	private function setupAdminBar(): void
	{
		AdminBar::enable(true);
		AdminBar::addPanel($this->context->getBasicInformation());
		AdminBar::setUser(new AdminBarUser($this->context->getUser()->getIdentity()));

		// Show link only in case of user can edit profile
		if ($this->context->checkPermission('user', 'user-overview') === true) {
			AdminBar::addLink(
				'My Profile',
				$this->linkGenerator->link('User:detail', [
					'id' => $this->context->getUser()->getId(),
				]),
			);
			AdminBar::addSeparator();
		}

		AdminBar::addLink('Settings', $this->linkGenerator->link('Settings:default'));
		AdminBar::addLink('Sign out', $this->linkGenerator->link('Cms:signOut'));
	}
}
