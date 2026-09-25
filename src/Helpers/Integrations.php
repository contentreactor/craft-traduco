<?php
declare(strict_types=1);

namespace ContentReactor\Traduco\Helpers;

use craft\ckeditor\Field as CKEditorField;
use lenz\linkfield\fields\LinkField as TypedLinkField;
use nystudio107\seomatic\fields\SeoSettings;
use verbb\navigation\elements\Node as NavigationNode;

/**
 * Checks for classes of plugins Traduco supports without requiring them, so they may not be installed
 */
class Integrations
{
	/**
	 * @phpstan-assert-if-true CKEditorField $value
	 */
	public static function isCKEditorField(mixed $value): bool
	{
		return class_exists(CKEditorField::class) && $value instanceof CKEditorField;
	}

	/**
	 * @phpstan-assert-if-true SeoSettings $value
	 */
	public static function isSeoSettingsField(mixed $value): bool
	{
		return class_exists(SeoSettings::class) && $value instanceof SeoSettings;
	}

	/**
	 * Typed Link fields, from sebastianlenz/linkfield
	 *
	 * @phpstan-assert-if-true TypedLinkField $value
	 */
	public static function isTypedLinkField(mixed $value): bool
	{
		return class_exists(TypedLinkField::class) && $value instanceof TypedLinkField;
	}

	/**
	 * @phpstan-assert-if-true NavigationNode $value
	 */
	public static function isNavigationNode(mixed $value): bool
	{
		return class_exists(NavigationNode::class) && $value instanceof NavigationNode;
	}
}
