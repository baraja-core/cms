<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare;


use Baraja\AdminBar\AdminBar;
use Baraja\Cms\Configuration;
use Baraja\Cms\Context;
use Baraja\Cms\Helpers;
use Baraja\Cms\LinkGenerator;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\Proxy\Proxy;
use Baraja\Cms\Search\SearchAdminBarPlugin;
use Baraja\Cms\Session;
use Baraja\Cms\Support\SupportAdminBarPlugin;
use Baraja\Plugin\CmsPluginPanel;
use Baraja\Plugin\Exception\PluginRedirectException;
use Baraja\Plugin\Exception\PluginTerminateException;
use Baraja\Plugin\Exception\PluginUserErrorException;
use Baraja\Search\Search;
use Baraja\ServiceMethodInvoker;
use Baraja\Url\Url;
use Nette\Http\IResponse;

final class Application
{
	public function __construct(
		private Context $context,
		private CmsPluginPanel $panel,
		private TemplateRenderer $templateRenderer,
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
	 *
	 * @return never-return
	 */
	public function run(string $plugin, string $view, string $locale, string $path): void
	{
		$this->panel->setPlugin($plugin);
		$this->panel->setView($view);

		$this->trySystemWorkflow($plugin, $path, $locale);
		$this->processLoginPage($path, $locale);
		AdminBar::enable(AdminBar::MODE_ENABLED);
		AdminBar::getBar()->setEnableVue();
		AdminBar::getBar()->addPlugin(new SupportAdminBarPlugin);
		if (class_exists(Search::class)) {
			AdminBar::getBar()->addPlugin(new SearchAdminBarPlugin);
		}

		try {
			$pluginService = $this->context->getPluginByName($plugin);
			if ($this->context->checkPermission($plugin) === false) {
				$this->terminate($this->templateRenderer->renderPermissionDenied());
			}
		} catch (\RuntimeException | \InvalidArgumentException $e) {
			if ($e->getCode() !== 404) {
				$this->context->getContainer()->getLogger()->warning($e->getMessage(), ['exception' => $e]);
			}
			$pluginService = $this->context->getPluginByType(ErrorPlugin::class);
		}

		$this->panel->setPluginService($pluginService);

		try {
			$pluginService->beforeRender();
			$pluginService->run();

			$actionMethod = 'action' . $view;
			if (\method_exists($pluginService, $actionMethod) === true) {
				(new ServiceMethodInvoker)
					->invoke(
						service: $pluginService,
						methodName: $actionMethod,
						params: $this->context->getRequest()->getQueryParams(),
					);
			}

			$pluginService->afterRender();
		} catch (PluginRedirectException $redirectException) {
			$redirectPath = $redirectException->getPath();
			if (str_starts_with($redirectPath, 'http')) {
				$this->redirect($redirectPath);
			}
			$this->redirect(
				Url::get()->getBaseUrl()
				. '/' . Configuration::get()->getBaseUriEscaped()
				. ($redirectPath === '' ? '' : '/' . $redirectPath),
			);
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
		LinkGenerator::setupNonce();
		if ($this->context->isBot()) {
			$this->terminate('<div style="margin:8em auto;max-width:800px">The entry for robots is blocked.</div>');
		}
		if (
			isset($_GET[LinkGenerator::NONCE_QUERY_PARAM]) === true
			&& (
				LinkGenerator::verifyNonce() === false
				|| isset($this->context->getRequest()->getCookieParams()['_nss']) === false // is same site?
			)
		) {
			$this->terminate(
				'<div style="margin:8em auto;max-width:800px">'
				. '<h1>Bad request</h1>'
				. '<p>This request has been blocked by security reason. Correct nonce required.</p>'
				. ($_POST === []
					? '<p>If you really want to process this request, you can click the button:</p>'
					. '<a href="' . LinkGenerator::getSafeUrlForCallAgain() . '" rel="nofollow"><button>Process again</button></a>'
					: ''
				) . '</div>',
			);
		}
		if ($this->context->getSettings()->isOk() === false) { // route installation workflow
			if ($path !== '') { // canonize configuration request to homepage URL
				$this->redirect($this->context->getContainer()->getLinkGenerator()->linkHomepage());
			}
			$this->terminate($this->context->getSettings()->runInstallProcess());
		}
		if (IntegrityWorkflow::isNeedRun() === true) {
			$this->context->getIntegrityWorkflow()->run();
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
		if ($path === 'cms/sign-out') { // sign out always allow
			return;
		}
		if (Session::get(Session::WORKFLOW_NEED_OTP_AUTH) === true) { // route login form for OTP auth
			AdminBar::enable(AdminBar::MODE_DISABLED);
			$this->terminate($this->templateRenderer->renderLoginOtpAuthTemplate($locale));
		}
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


	/**
	 * @return never-return
	 */
	private function terminate(?string $haystack = null): void
	{
		if ($haystack !== null) {
			if (AdminBar::getBar()->isDebugMode() === false) { // minify HTML in production mode
				$haystack = Helpers::minifyHtml($haystack);
			}
			echo $haystack;
		}
		die;
	}


	/**
	 * @return never-return
	 */
	private function redirect(string $url, int $httpCode = IResponse::S302_FOUND): void
	{
		$this->context->getResponse()->redirect($url, $httpCode);
		$this->terminate();
	}
}
