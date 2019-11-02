<?php

declare(strict_types=1);

namespace App\AdminModule\Presenters;


class HomepagePresenter extends BasePresenter
{

	public function startup(): void
	{
		parent::startup();

		// 1. Is sign in?
		if ($this->user->isLoggedIn() === false) {
			$this->template->setFile(__DIR__ . '/templates/Homepage/' . ($this->action === 'forgotPassword' ? 'forgotPassword' : 'sign') . '.latte');

			return;
		}
	}

	public function actionDefault(): void
	{
		// $this->userManager->setUserVariable($this->user->getIdentity(), 'pokus', 'abcd');
	}

	/**
	 * @param string $username
	 */
	public function actionForgotPassword(string $username): void
	{
		$this->template->username = $username;
	}

}