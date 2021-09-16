<?php

declare(strict_types=1);

namespace Baraja\Cms\Proxy\GlobalAsset;


use Baraja\Cms\Proxy\Proxy;

final class CmsEditorAsset implements CmsAsset
{
	public function getFormat(): string
	{
		return 'js';
	}


	public function getUrl(): string
	{
		return Proxy::getUrl('cms-editor.js');
	}
}
