<?php

declare(strict_types=1);

namespace Baraja\Cms\Proxy\GlobalAsset;


final class CustomGlobalAssetManager
{
	/** @var CmsAsset[] */
	private array $assets = [];


	public function addAsset(CmsAsset $asset): void
	{
		$this->assets[] = $asset;
	}


	/**
	 * @return CmsAsset[]
	 */
	public function getAssets(): array
	{
		return $this->assets;
	}
}
