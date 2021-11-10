<?php

declare(strict_types=1);

namespace Baraja\Cms\Translator;


use Nette\Localization\Translator;

final class CmsDefaultTranslator implements Translator
{
	public function translate($message, ...$parameters): string
	{
		/** @phpstan-ignore-next-line */
		return (string) $message;
	}
}
