<?php

declare(strict_types=1);

namespace App\AdminModule\Presenters;


use Baraja\Cms\UserManager;
use Nette\Application\UI\InvalidLinkException;

class BasePresenter extends \App\Presenters\BasePresenter
{

	/**
	 * @var UserManager
	 * @inject
	 */
	public $userManager;

	public function startup(): void
	{
		parent::startup();

		$this->template->menuContent = $this->getMenu();
	}

	/**
	 * @return string[][]
	 */
	private function getMenu(): array
	{
		$return = [];

		foreach ($this->context->getParameters()['cmsMenu'] ?? [] as $item) {
			if (isset($item['link'])) {
				try {
					$item['link'] = $this->link($item['link']);
				} catch (InvalidLinkException $e) {
					$item['link'] = '#';
				}
			}

			$return[] = $item;
		}

		return $return;
	}

}