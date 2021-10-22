<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Cms\MiddleWare\AdminBusinessLogicControlException;
use Baraja\Cms\MiddleWare\Application;
use Baraja\Cms\MiddleWare\Bridge\SentryBridge;
use Baraja\Cms\MiddleWare\TemplateRenderer;
use Baraja\Url\Url;
use Nette\Application\Responses\VoidResponse;
use Nette\Http\IResponse;
use Psr\Log\LogLevel;
use Tracy\Debugger;

final class Admin
{
	/** @deprecated since 2021-10-20, use Configuration::get()->getSupportedLocales() instead. */
	public const SUPPORTED_LOCALES = ['cs', 'en'];

	private Application $application;


	public function __construct(
		private Context $context,
		MenuManager $menuManager,
	) {
		$templateRenderer = new TemplateRenderer(
			cacheDir: Configuration::get()->getCacheDir(),
			context: $context,
			panel: $context->getContainer()->getPluginPanel(),
			menuManager: $menuManager,
			settings: $this->context->getSettings(),
		);
		$this->application = new Application(
			context: $context,
			panel: $context->getContainer()->getPluginPanel(),
			templateRenderer: $templateRenderer,
		);
		if (class_exists(Debugger::class)) {
			Debugger::getBar()->addPanel($context->getContainer()->getPluginPanel());
		}
		if (function_exists('Sentry\configureScope')) {
			(new SentryBridge($context->getUserManager()->get()))->register();
		}
	}


	public static function isAdminRequest(): bool
	{
		$relativeUrl = Url::get()->getRelativeUrl(false);
		$baseUri = Configuration::get()->getBaseUri();

		return $relativeUrl === $baseUri || str_starts_with($relativeUrl, $baseUri . '/');
	}


	public function run(?string $locale, string $path): void
	{
		if (PHP_SAPI === 'cli') {
			throw new \RuntimeException('CMS is not available in CLI.');
		}
		if (self::isAdminRequest() === false) {
			return;
		}

		$path = trim((string) preg_replace('/^\/?([a-zA-Z0-9-.\/]+).*$/', '$1', $path), '/');
		[$plugin, $view, $more] = explode('/', $path . '///');

		if ($more === '' && $view !== '') { // route plugin request in format "xxx/yyy"
			$plugin = Helpers::formatPresenterNameByUri($plugin);
			$view = Helpers::formatActionNameByUri(explode('?', $view)[0]) ?: null;
		} elseif ($plugin !== '') { // route plugin request in format "xxx"
			$plugin = Helpers::formatPresenterNameByUri(explode('?', $plugin)[0]) ?: null;
		}

		try {
			$this->application->run(
				plugin: $plugin ?: 'Homepage',
				view: $view ?: 'default',
				locale: $locale ?? $this->context->getLocale(),
				path: $path,
			);
		} catch (AdminBusinessLogicControlException $controlException) {
			$this->processBusinessLogic($controlException);
		} catch (\Throwable $e) {
			try {
				$this->context->getContainer()->getLogger()->log(
					level: LogLevel::CRITICAL,
					message: $e->getMessage(),
					context: ['exception' => $e]
				);
			} catch (\Throwable) {
				// Silence is golden.
			}
			Helpers::brokenAdmin($e);
		}
		die;
	}


	private function processBusinessLogic(AdminBusinessLogicControlException $e): void
	{
		if ($e instanceof AdminRedirect) {
			$this->context->getResponse()->redirect($e->getUrl(), IResponse::S302_FOUND);
			(new VoidResponse)->send($this->context->getRequest(), $this->context->getResponse());
			die;
		}
		throw new \LogicException('Implementation for control exception "' . $e::class . '" has not implemented.');
	}
}
