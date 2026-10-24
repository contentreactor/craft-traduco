<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Services;

use ContentReactor\Traduco\Models\{
	TranslatableElement,
	UserSettings,
};
use ContentReactor\Traduco\Plugin;
use Craft;
use craft\base\Component;
use craft\events\RegisterEmailMessagesEvent;
use Throwable;

/**
 * Emails the user who asked for a translation once the queue has run it. Only whole elements are queued, so these are
 * the only translations that finish out of sight. The messages are Craft's own system messages, which gives each one
 * an editable subject and body per language, and sending never gets in a translation's way: a message that can't be
 * sent is logged and the translation stands.
 */
class Notifications extends Component
{
	public const MESSAGE_FAILED = 'traduco_translation_failed';

	public const MESSAGE_DONE = 'traduco_translation_done';

	/**
	 * Adds Traduco's messages to the ones Craft lists under Utilities, where their text can be changed
	 */
	public function registerMessages(RegisterEmailMessagesEvent $event): void
	{
		// Only where they can be sent, so a Lite install isn't offered two messages that never go out
		if (!Plugin::getInstance()->isPro()) return;

		// Twig, not Craft::t() placeholders: Craft renders a message's subject and body through
		// View::renderSandboxedString(), see craft\mail\Mailer::send()
		$event->messages[] = [
			'key' => self::MESSAGE_FAILED,
			'heading' => Craft::t('traduco', 'When a queued translation fails:'),
			'subject' => Craft::t('traduco', 'Couldn’t translate “{{ title }}” into {{ targetSite }}'),
			'body' => Craft::t('traduco', "Translating “{{ title }}” from {{ sourceSite }} into {{ targetSite }} failed:\n\n{{ error }}"),
		];
		$event->messages[] = [
			'key' => self::MESSAGE_DONE,
			'heading' => Craft::t('traduco', 'When a queued translation finishes:'),
			'subject' => Craft::t('traduco', '“{{ title }}” is translated into {{ targetSite }}'),
			'body' => Craft::t('traduco', '“{{ title }}” has been translated from {{ sourceSite }} into {{ targetSite }}.'),
		];
	}

	public function queuedTranslationDone(TranslatableElement $element, ?int $userId): void
	{
		$this->send(self::MESSAGE_DONE, $element, $userId);
	}

	public function queuedTranslationFailed(TranslatableElement $element, ?int $userId, Throwable|string $error): void
	{
		$this->send(self::MESSAGE_FAILED, $element, $userId, $error instanceof Throwable ? $error->getMessage() : $error);
	}

	/**
	 * Whether this message would be sent, which is the edition and the setting together
	 */
	public function isEnabledFor(string $key): bool
	{
		if (!Plugin::getInstance()->isPro()) return false;

		$setting = Plugin::getInstance()->getSettings()->getUserSettings()?->queueNotifications
			?? UserSettings::QUEUE_NOTIFICATIONS_NONE;

		return match ($setting) {
			UserSettings::QUEUE_NOTIFICATIONS_ALL => true,
			UserSettings::QUEUE_NOTIFICATIONS_FAILURES => $key === self::MESSAGE_FAILED,
			default => false,
		};
	}

	private function send(string $key, TranslatableElement $element, ?int $userId, ?string $error = null): void
	{
		// The whole of it, the settings read included, so nothing here can fail a translation that already happened
		try {
			if ($userId === null || !$this->isEnabledFor($key)) return;

			$user = Craft::$app->getUsers()->getUserById($userId);
			if ($user === null || $user->email === null || trim($user->email) === '') return;

			$sites = Craft::$app->getSites();
			$source = $element->getElement();

			Craft::$app->getMailer()
				->composeFromKey($key, [
					// The element may be gone by now, and its ID is the one thing that always identifies it
					'title' => (string)($source?->title ?? $element->elementId),
					'elementId' => $element->elementId,
					'sourceSite' => $sites->getSiteById($element->siteId, true)?->getName() ?? $element->siteId,
					'targetSite' => $sites->getSiteById($element->targetSiteId, true)?->getName() ?? $element->targetSiteId,
					'error' => $error ?? '',
				])
				->setTo($user)
				->send();
		} catch (Throwable $e) {
			Craft::warning("Couldn’t send the $key notification: " . $e->getMessage(), 'traduco');
		}
	}
}
