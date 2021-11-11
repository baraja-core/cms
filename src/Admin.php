<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Cms\MiddleWare\AdminBusinessLogicControlException;
use Baraja\Cms\MiddleWare\Application;
use Baraja\Cms\MiddleWare\Bridge\SentryBridge;
use Baraja\Cms\MiddleWare\TemplateRenderer;
use Baraja\Url\Url;
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


	/**
	 * @return never-return
	 */
	public function run(?string $locale = null, ?string $path = null): void
	{
		if (PHP_SAPI === 'cli') {
			throw new \RuntimeException('CMS is not available in CLI.');
		}
		if ($locale !== null) {
			trigger_error('Argument $locale is deprecated. Please remove it from your implementation.');
		}
		if ($path !== null) {
			trigger_error('Argument $path is deprecated. Please remove it from your implementation.');
		} else {
			$path = Url::get()->getRelativeUrl();
		}
		if (self::isAdminRequest() === false) {
			throw new \LogicException(sprintf('Path "%s" is not a admin request.', $path));
		}

		$route = $this->route($path);
		try {
			$this->runApplication(
				plugin: $route['plugin'],
				view: $route['view'],
				locale: $route['locale'] ?? $this->context->getLocale(),
				path: $route['path'],
			);
		} catch (\Throwable $e) {
			Helpers::brokenAdmin($e);
		}
		die;
	}


	/**
	 * @return array{plugin: string, view: string, locale: string|null, path: string}
	 */
	private function route(string $path): array
	{
		$config = Configuration::get();
		$pattern = sprintf(
			'/^%s(?:\/+(?<locale>%s))?(?<path>\/.*|\?.*|)$/',
			$config->getBaseUri(),
			implode('|', $config->getSupportedLocales()),
		);

		$locale = null;
		if (preg_match($pattern, $path, $parser) === 1) {
			if (isset($parser['locale']) && is_string($parser['locale']) && $parser['locale'] !== '') {
				$locale = $parser['locale'];
			}
			if (isset($parser['path']) && is_string($parser['path'])) {
				$path = $parser['path'];
			} else {
				throw new \LogicException(sprintf('Path can not be parsed, but "%s" given.', $path));
			}
		}

		$path = trim((string) preg_replace('/^\/?([a-zA-Z0-9-.\/]+).*$/', '$1', $path), '/');
		[$plugin, $view, $more] = explode('/', $path . '///');

		if ($more === '' && $view !== '') { // route plugin request in format "xxx/yyy"
			$plugin = Helpers::formatPresenterNameByUri($plugin);
			$view = Helpers::formatActionNameByUri(explode('?', $view)[0]);
		} elseif ($plugin !== '') { // route plugin request in format "xxx"
			$plugin = Helpers::formatPresenterNameByUri(explode('?', $plugin)[0]);
		}

		return [
			'plugin' => $plugin !== '' ? $plugin : 'Homepage',
			'view' => $view !== '' ? $view : 'default',
			'locale' => $locale,
			'path' => $path,
		];
	}


	private function runApplication(string $plugin, string $view, string $locale, string $path): void
	{
		try {
			$this->application->run(
				plugin: $plugin,
				view: $view,
				locale: $locale,
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
		}
	}


	/**
	 * @return never-return
	 */
	private function processBusinessLogic(AdminBusinessLogicControlException $e): void
	{
		if ($e instanceof AdminRedirect) {
			$this->context->getResponse()->redirect($e->getUrl(), IResponse::S302_FOUND);
			die;
		}
		throw new \LogicException('Implementation for control exception "' . $e::class . '" has not implemented.');
	}
}
