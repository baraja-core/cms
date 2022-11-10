<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\Cms;


use Baraja\Cms\MenuItem;
use Baraja\Cms\Proxy\GlobalAsset\CmsAsset;

final class CmsSettingsResponse
{
	/**
	 * @param array<int, CmsAsset> $staticAssets
	 * @param array<int, MenuItem> $menu
	 * @param array{user: array<string, string|null>} $settings
	 */
	public function __construct(
		public bool $isDebug,
		public string $basePath,
		public array $staticAssets,
		public string $projectName,
		public string $locale,
		public array $menu,
		public CmsGlobalSettingsResponse $globalSettings,
		public array $settings,
		public string $currentVersion,
		public string $installationHash,
	) {
	}
}
