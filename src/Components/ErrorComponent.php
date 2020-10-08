<?php

declare(strict_types=1);

namespace Baraja\Cms\Component;


use Baraja\Cms\Helpers;
use Baraja\Plugin\Component\VueComponent;
use Baraja\Plugin\Plugin;
use Nette\Http\Request;

final class ErrorComponent extends VueComponent
{
	public function render(Request $request, ?Plugin $plugin = null): string
	{
		return '<cms-error path="' . Helpers::escapeHtmlAttr($request->getUrl()->getPathInfo()) . '"></cms-error>';
	}
}
