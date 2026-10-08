# Release Notes for Traduco

## 1.1.0 - 2026-10-08

> [!IMPORTANT]
> Traduco now comes in Lite and Pro editions, and existing installs update to Lite. Translating whole elements needs Pro.

### Added
- Lite and Pro editions. Lite translates fields from their action menus. Pro translates whole elements into other sites, in the background.

### Changed
- Traduco is licensed under the Craft License.

## 1.0.0 - 2026-09-25
- Initial release

### Upgrading from Traduco 2.x
- Run `php craft up` after updating. A migration renames the settings stored under the old class names.
- Traduco’s classes moved to the `ContentReactor\Traduco` namespace. Code extending Traduco, like translators and event handlers, needs the new namespace.
- Traduco no longer requires `contentreactor/core`.
