<?php

declare(strict_types=1);

namespace Baraja\Cms\Proxy\GlobalAsset;


final class CustomGlobalAssetManager
{
	/** @var CmsAsset[] */
	private array $assets = [];

	/** @var array<string, string> (hash => path) */
	private array $diskPathsMap = [];


	public function addAsset(CmsAsset $asset): void
	{
		$this->assets[] = $asset;
	}


	public function addDiskPath(string $hash, string $diskPath): void
	{
		if (isset($this->diskPathsMap[$hash]) && $this->diskPathsMap[$hash] !== $diskPath) {
			throw new \InvalidArgumentException(
				'File "' . $diskPath . '" and "' . $this->diskPathsMap[$hash] . '" '
				. 'already has been defined with same hash "' . $hash . '".',
			);
		}
		$this->diskPathsMap[$hash] = $diskPath;
	}


	/**
	 * @return CmsAsset[]
	 */
	public function getAssets(): array
	{
		return $this->assets;
	}


	/**
	 * @return array<string, string> (hash => path)
	 */
	public function getDiskPathsMap(): array
	{
		return $this->diskPathsMap;
	}
}
