<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Base;

use ContentReactor\Traduco\Models\{
	TranslatableElement,
	Translation,
};
use craft\base\ElementInterface;

interface TranslatorInterface
{
	public function __construct(string $apiKey);

	public static function displayName(): string;

	public function translateElement(TranslatableElement $element): ElementInterface;

	public function translateField(Translation $translation): bool;

	public function translateText(Translation $translation): string;

	/**
	 * @param array<string, mixed> $options translator-specific options
	 */
	public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null, array $options = []): string;
}
