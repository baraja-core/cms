<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\Cms;


use Baraja\Cms\Proxy\GlobalAsset\CmsAsset;

final class CmsPluginResponse
{
	/**
	 * @param array<int, CmsAsset> $staticAssets
	 * @param array<int, mixed> $components
	 */
	public function __construct(
		public array $staticAssets,
		public ?string $title,
		public string $activeKey,
		public array $components,
	) {
	}
}
