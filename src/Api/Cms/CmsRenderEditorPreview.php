<?php

declare(strict_types=1);

namespace Baraja\Cms\Api\Cms;


final class CmsRenderEditorPreview
{
	public function __construct(
		public string $html,
	) {
	}
}
