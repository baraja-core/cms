<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Baraja\Cms\MiddleWare\Application;
use Baraja\Cms\MiddleWare\TemplateRenderer;
use Baraja\Plugin\CmsPluginPanel;
use Nette\Application\Responses\VoidResponse;
use Nette\Http\IResponse;
use Tracy\Debugger;
use Tracy\ILogger;

final class Admin
{
	public const SUPPORTED_LOCALES = ['cs', 'en'];

	private string $cacheDir;

	private Application $application;

	private Context $context;

	private LinkGenerator $linkGenerator;

	private MenuManager $menuManager;

	private CmsPluginPanel $panel;


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
		$this->application = new Application(
			$context,
			$panel,
			new TemplateRenderer(
				$cacheDir,
				$context,
				$panel,
				$menuManager
			),
			$linkGenerator
		);
		Debugger::getBar()->addPanel($panel);
	}


	public function run(?string $locale, string $path): void
	{
		if (PHP_SAPI === 'cli') {
			throw new \RuntimeException('CMS is not available in CLI.');
		}

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
				path: $path
			);
		} catch (AdminRedirect $redirect) {
			$this->context->getResponse()->redirect($redirect->getUrl(), IResponse::S302_FOUND);
			(new VoidResponse)->send($this->context->getRequest(), $this->context->getResponse());
			die;
		} catch (\Throwable $e) {
			Debugger::log($e, ILogger::EXCEPTION);
			throw $e;
		}
	}
}
