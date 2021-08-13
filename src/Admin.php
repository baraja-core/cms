<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Cms\MiddleWare\Application;
use Baraja\Cms\MiddleWare\Bridge\SentryBridge;
use Baraja\Cms\MiddleWare\TemplateRenderer;
use Baraja\PathResolvers\Resolvers\TempDirResolver;
use Baraja\Plugin\CmsPluginPanel;
use Baraja\Url\Url;
use Nette\Application\Responses\VoidResponse;
use Nette\Http\IResponse;
use Nette\Utils\FileSystem;
use Tracy\Debugger;
use Tracy\ILogger;

final class Admin
{
	public const SUPPORTED_LOCALES = ['cs', 'en'];

	private Application $application;


	public function __construct(
		private Context $context,
		TempDirResolver $tempDirResolver,
		MenuManager $menuManager,
		CmsPluginPanel $panel,
	) {
		$cacheDir = $tempDirResolver->get('cache/baraja.cms');
		FileSystem::createDir($cacheDir);
		$this->application = new Application(
			context: $context,
			panel: $panel,
			templateRenderer: new TemplateRenderer(
				cacheDir: $cacheDir,
				context: $context,
				panel: $panel,
				menuManager: $menuManager,
				settings: $this->context->getSettings(),
			),
		);
		Debugger::getBar()->addPanel($panel);
		(new SentryBridge($context->getUser()))->register();
	}


	public static function isAdminRequest(): bool
	{
		$relativeUrl = Url::get()->getRelativeUrl(false);

		return $relativeUrl === 'admin' || str_starts_with($relativeUrl, 'admin/');
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
		} catch (AdminRedirect $redirect) {
			$this->context->getResponse()->redirect($redirect->getUrl(), IResponse::S302_FOUND);
			(new VoidResponse)->send($this->context->getRequest(), $this->context->getResponse());
		} catch (\Throwable $e) {
			try {
				Debugger::log($e, ILogger::CRITICAL);
			} catch (\Throwable) {
				// Silence is golden.
			}
			Helpers::brokenAdmin($e);
		}
		die;
	}
}
