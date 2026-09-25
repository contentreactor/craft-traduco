# Release Notes for Traduco

## Unreleased

> [!IMPORTANT]
> This release adds database migrations. Run `php craft up` after updating.

### Changed
- Traduco's classes moved to the `ContentReactor\Traduco` namespace. A migration renames the settings stored under the old class names. Code extending Traduco, like translators and event handlers, needs the new namespace.
- Traduco reads, copies and pairs field values with Field Value Parser instead of `contentreactor/core`. Its settings stay in their own table.
- Relation fields are copied with their disabled related elements too, as the ones inside nested blocks already were.
- SEO Settings fields inside nested blocks are translated too.

## 2.2.1
### Fixed
- Moved the CpAsset registration from the Element sidebar event to the Plugin init, to support elements without sidebar.

### Security
- Translation actions no longer accept anonymous requests, only run from the control panel, and require the user to be able to view the source element and save it in the target site.
- Translation requests can only read and write fields Traduco offers translations for.
- Saving settings now requires the “Manage Admin Settings” or “Manage System Settings” permission, and each settings screen only saves its own settings.
- The translator setting only accepts registered translators, and Traduco refuses to instantiate any other class.

### Fixed
- Translating no longer overwrites values the target site shares with the source site, such as fields that aren’t translatable. The field action menu only offers sites that store their own value.
- Matrix, Neo and Content Block entries shared with the target site are translated in place, instead of being replaced in every site. Copied Matrix entries keep their enabled state and get translated titles.
- Text fields are translated once instead of twice.
- Translating an element no longer fails on fields that don’t hold text, such as Number, Lightswitch, Money, Table or Multi-select fields. Only Plain Text and HTML fields are translated, everything else is copied.
- Regional site languages DeepL doesn’t support now fall back to their base language, so `de-AT` translates into German instead of failing. The source site’s language is sent along.
- HTML is translated with DeepL’s HTML tag handling.
- Element translations now run in a queue job, as the confirmation message said.
- Translating an element no longer changes the slug of an existing translation.
- Titles are translated even when the entry type’s field layout doesn’t include the title field.
- Settings could only be saved once, because the settings table had no primary key.
- The field action menu no longer breaks on single-site installs, unsaved elements and read-only views.
- The sidebar only appears on saved, localized elements, and only lists sites the element exists in and the user can edit.
- Translator errors are shown in the control panel instead of only in the browser console.
- `Traduco::EVENT_REGISTER_TRANSLATABLE_FIELDS` handlers no longer run for structured fields.
- Traduco now initializes fully on site and console requests.
- Removed references to an icon stylesheet and breadcrumb icon that don’t exist.

### Changed
- Traduco has its own icon, in the plugin list and the control panel.
- Fields inside Matrix and Neo blocks can be translated from their action menu again. The translation is saved when the target site has the same block, and shown to copy otherwise.
- Rich text translations shown to copy are previewed rendered, and copying puts their HTML on the clipboard.
- Labels, title texts and ARIA labels of Craft’s link fields, and custom labels of Typed Link fields (sebastianlenz/linkfield), are translated. Their links are copied as they are.
- CKEditor fields with nested entries can be translated. By default, the target site’s own nested entries are kept and translated when they match the source’s, and the field is translated rendered otherwise. The new “Rich Text with Nested Entries” setting can always translate the rendered field instead, saving it without nested entries.
- The sidebar dropdown and field action menus list the sites of the entry’s section, or the node’s navigation, instead of only the sites the element already exists in.
- Translating into a site the element doesn’t exist in adds it to that site where its section allows, and otherwise creates a separate, translated copy there.
- Translating a single field into a site the element doesn’t exist in creates it there, with its other values copied.
- Navigation nodes only get their labels translated, and only when they don’t link to an element.
- Rich text with nested entries in it isn’t translated.
- The element sidebar has a site dropdown, with a spinner, instead of a button that opened a popup.
- Field action menus list a translation item per site instead of opening a popup.
- Translating is disabled for drafts, unsaved changes and revisions, and for the elements nested in them.
- Removed the translation popup and `Traduco::registerTranslationsModal()`.
- Settings are stored once for the whole install instead of per site.
- Translation logic moved to `Traduco\base\BaseTranslator`, so translators only implement `translate()`.
- `Traduco::getTranslatableSites()` and the `translatableSites()` Twig function take an element instead of a site ID.
- The Google Translate translator stub throws a `NotSupportedException` instead of type errors.
- Removed `Traduco\events\RegisterTranslatorEvent`, `Traduco::getStructuredFields()`, `Traduco::isFieldStructured()` and the `EVENT_REGISTER_STRUCTURED_FIELDS` constants.
- Traduco is licensed under MIT.
