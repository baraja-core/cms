<?php

declare(strict_types=1);

namespace Baraja\Cms;


use Latte\Runtime\FilterInfo;
use Nette\Localization\ITranslator;

final class TranslatorFilter
{
	private ITranslator $translator;


	public function __construct(ITranslator $translator)
	{
		$this->translator = $translator;
	}


	/**
	 * @param string|object $haystack
	 * @return string
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

		return $this->translator->translate($haystack);
	}
}
