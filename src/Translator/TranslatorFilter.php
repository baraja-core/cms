<?php

declare(strict_types=1);

namespace Baraja\Cms\Translator;


use Latte\Runtime\FilterInfo;
use Nette\Localization\Translator;

final class TranslatorFilter
{
	public function __construct(
		private ?Translator $translator = null,
	) {
	}


	/**
	 * @param string|object $haystack
	 */
	public function __invoke(FilterInfo $info, $haystack): string
	{
		if (is_object($haystack)) {
			if (method_exists($haystack, '__toString')) {
				$haystack = (string) $haystack;
			} else {
				throw new \InvalidArgumentException('Object "' . \get_class($haystack) . '" can not be serialized to string, because do not implement "__toString" method.');
			}
		}

		return ($this->translator ?? $this->getDefaultTranslator())->translate($haystack);
	}


	private function getDefaultTranslator(): Translator
	{
		static $cache;

		return $cache ?? $cache = new CmsDefaultTranslator;
	}
}
