<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare;


use Baraja\AdminBar\AdminBar;
use Baraja\Cms\Context;
use Baraja\Cms\Helpers;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\Proxy\Proxy;
use Baraja\Cms\Search\SearchAdminBarPlugin;
use Baraja\Plugin\CmsPluginPanel;
use Baraja\Plugin\Exception\PluginRedirectException;
use Baraja\Plugin\Exception\PluginTerminateException;
use Baraja\Plugin\Exception\PluginUserErrorException;
use Baraja\Search\Search;
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
		private TemplateRenderer $templateRenderer
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
		AdminBar::enable(AdminBar::MODE_ENABLED);
		AdminBar::getBar()->setEnableVue();
		if (class_exists(Search::class)) {
			AdminBar::getBar()->addPlugin(new SearchAdminBarPlugin);
		}

		try {
			$pluginService = $this->context->getPluginByName($plugin);
			if ($this->context->checkPermission($plugin) === false) {
				$this->terminate($this->templateRenderer->renderPermissionDenied());
			}
		} catch (\RuntimeException | \InvalidArgumentException $e) {
			if ($e->getCode() !== 404 && class_exists(Debugger::class)) {
				Debugger::log($e, ILogger::EXCEPTION);
			}
			$pluginService = $this->context->getPluginByType(ErrorPlugin::class);
		}

		$this->panel->setPluginService($pluginService);

		try {
			$pluginService->beforeRender();
			$pluginService->run();

			$actionMethod = 'action' . $view;
			if (\method_exists($pluginService, $actionMethod) === true) {
				(new ServiceMethodInvoker)->invoke($pluginService, $actionMethod, $this->context->getRequest()->getUrl()->getQueryParameters());
			}

			$pluginService->afterRender();
		} catch (PluginRedirectException $redirectException) {
			$redirectPath = $redirectException->getPath();
			if (str_starts_with($redirectPath, 'http')) {
				$this->redirect($redirectPath);
			}
			$this->redirect(Url::get()->getBaseUrl() . '/admin' . ($redirectPath === '' ? '' : '/' . $redirectPath));
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
			$this->terminate($this->context->getSettings()->runInstallProcess());
		}
		if (IntegrityWorkflow::isNeedRun() === true) {
			(new IntegrityWorkflow(
				$this->context->getUser(),
				$this->context->getEntityManager(),
				$this->context->getUserManager()->get(),
			))->run();
		}
		if ($plugin === 'ResetPassword') { // route reset password form
			$this->terminate($this->templateRenderer->renderResetPasswordTemplate($_GET['token'] ?? '', $locale));
		}
		if ($plugin === 'SetUserPassword') { // route reset password form
			$this->terminate($this->templateRenderer->renderSetUserPasswordTemplate($_GET['userId'] ?? '', $locale));
		}
		if (
			$plugin === 'CmsWebLoader'
			|| str_starts_with($path, 'assets/')
		) { // route static file from internal storage or dynamic assets
			(new Proxy($this->context))->run($path);
		}
	}


	private function processLoginPage(string $path, string $locale): void
	{
		if (
			str_starts_with($path, 'cms/') === false
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
			if (AdminBar::getBar()->isDebugMode() === false) { // minify HTML in production mode
				$haystack = Helpers::minifyHtml($haystack);
			}
			$response = new TextResponse($haystack);
		}
		$response->send($this->context->getRequest(), $this->context->getResponse());
		die;
	}


	private function redirect(string $url, int $httpCode = IResponse::S302_FOUND): void
	{
		$this->context->getResponse()->redirect($url, $httpCode);
		$this->terminate();
	}
}
