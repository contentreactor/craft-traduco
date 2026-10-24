<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Base;

/**
 * A translator that can also rewrite a value in its own language, the way DeepL Write does. Separate from
 * TranslatorInterface, so a translator that only translates is unaffected and the control panel can tell which is
 * which with an instanceof.
 */
interface RephraserInterface
{
	/**
	 * Rewrites the text in the language it's already in
	 *
	 * @param string $text
	 * @param string $language the site language the text is in, which picks the variant to write back in
	 * @return string
	 */
	public function rephrase(string $text, string $language): string;

	/**
	 * Why this translator can't rephrase anything as it's set up, or null where it can. Answered without calling the
	 * service, as the field action menu asks it on every element edit screen.
	 */
	public function getRephraseUnavailableReason(): ?string;
}
