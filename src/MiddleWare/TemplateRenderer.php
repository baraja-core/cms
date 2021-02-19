<?php

declare(strict_types=1);

namespace Baraja\Cms\MiddleWare;


use Baraja\Cms\Admin;
use Baraja\Cms\Context;
use Baraja\Cms\Helpers;
use Baraja\Cms\MenuManager;
use Baraja\Cms\Plugin\ErrorPlugin;
use Baraja\Cms\User\Entity\CmsUser;
use Baraja\Cms\User\Entity\UserResetPasswordRequest;
use Baraja\Plugin\BasePlugin;
use Baraja\Plugin\CmsPluginPanel;
use Baraja\Plugin\Component\PluginComponent;
use Baraja\Plugin\Plugin;
use Baraja\Plugin\SimpleComponent\SimpleComponent;
use Baraja\Url\Url;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\NoResultException;
use Latte\Engine;
use Tracy\Debugger;
use Tracy\ILogger;

final class TemplateRenderer
{
	private string $cacheDir;

	private Context $context;

	private CmsPluginPanel $panel;

	private MenuManager $menuManager;


	public function __construct(
		string $cacheDir,
		Context $context,
		CmsPluginPanel $panel,
		MenuManager $menuManager
	) {
		$this->cacheDir = $cacheDir;
		$this->context = $context;
		$this->panel = $panel;
		$this->menuManager = $menuManager;
	}


	public function renderTemplate(Plugin $plugin, string $pluginName, string $view): string
	{
		$components = $this->context->getComponents($plugin, $plugin instanceof ErrorPlugin ? 'default' : $view);
		$this->panel->setRenderedComponents($components);

		ob_start(static function () {
		});

		$args = [
			'isDebug' => (string) ($_GET['debugMode'] ?? '') === '1',
			'basePath' => $baseUrl = Url::get()->getBaseUrl(),
			'assetsPath' => 'admin/cms-web-loader/' . $this->context->getPluginNameByType($plugin) . '.js',
			'customAssetPaths' => $this->context->getCustomGlobalAssetPaths(),
			'content' => $this->renderContentCode($plugin, $pluginName, $view, $components),
			'menu' => [
				'dashboardLink' => $baseUrl . '/admin',
				'isDashboard' => $pluginName === 'Homepage' && $view === 'default',
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
			require __DIR__ . '/../../template/@layout.phtml';

			return (string) ob_get_clean();
		} catch (\Throwable $e) {
			ob_end_clean();
			throw new \RuntimeException($e->getMessage(), $e->getCode(), $e);
		}
	}


	public function renderNeedOtpAuth(): string
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


	public function renderPermissionDenied(): string
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


	public function renderLoginTemplate(string $locale): string
	{
		return (new Engine)
			->setTempDirectory($this->cacheDir)
			->addFilter('translate', $this->context->getTranslatorFilter())
			->renderToString(
				__DIR__ . '/../../template/login.latte',
				[
					'basePath' => Url::get()->getBaseUrl(),
					'availableLocales' => Admin::SUPPORTED_LOCALES,
					'projectName' => $this->context->getConfiguration()->get('name', 'core'),
					'locale' => $locale,
				],
			);
	}


	public function renderResetPasswordTemplate(string $token, string $locale): string
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
		} catch (NoResultException | NonUniqueResultException | \InvalidArgumentException) {
			return 'The password change token does not exist. Please request a new token again.';
		}

		return (new Engine)
			->setTempDirectory($this->cacheDir)
			->addFilter('translate', $this->context->getTranslatorFilter())
			->renderToString(
				__DIR__ . '/../../template/reset-password.latte',
				[
					'basePath' => Url::get()->getBaseUrl(),
					'loginUrl' => Url::get()->getBaseUrl() . '/admin',
					'locale' => $locale,
					'username' => $request->getUser()->getUsername(),
					'token' => $request->getToken(),
				],
			);
	}


	public function renderSetUserPasswordTemplate(string $userId, string $locale): string
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
		} catch (NoResultException | NonUniqueResultException | \InvalidArgumentException) {
			return 'This link does not work. For more information, please contact your project administrator.';
		}
		if ($user->getPassword() !== '---empty-password---') {
			return 'Settings link is invalid, because password was changed.';
		}

		return (new Engine)
			->setTempDirectory($this->cacheDir)
			->addFilter('translate', $this->context->getTranslatorFilter())
			->renderToString(
				__DIR__ . '/../../template/set-user-password.latte',
				[
					'basePath' => Url::get()->getBaseUrl(),
					'loginUrl' => Url::get()->getBaseUrl() . '/admin',
					'locale' => $locale,
					'userId' => $user->getId(),
					'username' => $user->getUsername(),
				],
			);
	}


	/**
	 * @param PluginComponent[] $components
	 */
	private function renderContentCode(Plugin $plugin, string $pluginName, string $view, array $components): string
	{
		if (\count($components) === 0) {
			$return = null;
		} elseif ($view === 'detail') {
			$componentsData = [];

			$first = true;
			foreach ($components as $component) {
				if ($this->context->checkPermission($pluginName, $component->getName())) {
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
			if ($this->context->checkPermission($pluginName, $components[0]->getName())) {
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
				. '<p>Can not render component <b>'
				. htmlspecialchars($component->getTab(), ENT_QUOTES)
				. '</b></p>';
		}
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
}
